<?php

namespace App\Domains\Broker\LorryHire;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Broker\Enums\BookBalanceEntryType;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\Voucher\TransporterBookBalanceService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Broker Lorry Hire cancellation with optional outstanding settlement (Rules §11).
 */
final class LorryHireCancellationService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly LorryHireCalculationService $calculations = new LorryHireCalculationService,
        private readonly TransporterBookBalanceService $bookBalance = new TransporterBookBalanceService,
        private readonly OperationalAccountingPoster $accounting = new OperationalAccountingPoster,
    ) {}

    /**
     * @return array{
     *     freight_total: numeric-string,
     *     tds_amount: numeric-string,
     *     adjustments: list<array{
     *         id: string,
     *         type: string,
     *         amount: numeric-string,
     *         reason: string|null
     *     }>,
     *     vouchers: list<array{
     *         id: string,
     *         voucher_number: string,
     *         amount: numeric-string,
     *         status: string
     *     }>,
     *     net_payable: numeric-string,
     *     outstanding_amount: numeric-string,
     *     settle_yes_description: string,
     *     settle_no_description: string
     * }
     */
    public function preview(LorryHire $hire): array
    {
        $hire->loadMissing(['adjustments', 'vouchers', 'bookBalanceEntries']);
        $summary = $this->calculations->summarize($hire);
        $outstanding = $summary['outstanding_amount'];

        $adjustments = [];
        foreach ($hire->adjustments as $adjustment) {
            $adjustments[] = [
                'id' => $adjustment->id,
                'type' => $adjustment->type->value,
                'amount' => $this->money($adjustment->amount),
                'reason' => $adjustment->reason,
            ];
        }

        $vouchers = [];
        foreach ($hire->vouchers as $voucher) {
            $vouchers[] = [
                'id' => $voucher->id,
                'voucher_number' => $voucher->voucher_number,
                'amount' => $this->money($voucher->amount),
                'status' => $voucher->status->value,
            ];
        }

        $formattedOutstanding = $this->formatSignedMoney($outstanding);

        return [
            'freight_total' => $summary['freight_total'],
            'tds_amount' => $summary['tds_amount'],
            'adjustments' => $adjustments,
            'vouchers' => $vouchers,
            'net_payable' => $summary['net_payable'],
            'outstanding_amount' => $outstanding,
            'settle_yes_description' => bccomp($outstanding, '0', 2) === 0
                ? 'There is no outstanding balance to transfer to the transporter book.'
                : "Yes — transfer {$formattedOutstanding} to the transporter book balance (operational ledger).",
            'settle_no_description' => bccomp($outstanding, '0', 2) === 0
                ? 'Outstanding is already zero; it will remain on this cancelled Lorry Hire record.'
                : "No — retain {$formattedOutstanding} against this cancelled Lorry Hire.",
        ];
    }

    /**
     * @param  array{
     *     reason: string,
     *     settle_outstanding: bool,
     *     cancelled_at?: string|null
     * }  $payload
     */
    public function cancel(LorryHire $hire, User $actor, array $payload): LorryHire
    {
        $company = Company::query()->findOrFail($hire->company_id);
        $branch = Branch::query()->findOrFail($hire->branch_id);

        $this->assertBrokerEntitled($company);
        $this->assertCanCancel($actor, $company, $branch);

        $reason = trim($payload['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Cancellation reason is required.',
            ]);
        }

        $settleOutstanding = $payload['settle_outstanding'];

        $cancelledAt = filled($payload['cancelled_at'] ?? null)
            ? CarbonImmutable::parse((string) $payload['cancelled_at'])
            : CarbonImmutable::now('UTC');

        return $this->auditedTransaction->run(function () use (
            $hire,
            $actor,
            $company,
            $branch,
            $reason,
            $settleOutstanding,
            $cancelledAt,
        ): LorryHire {
            return $this->withTenant($company, $branch, function () use (
                $hire,
                $actor,
                $company,
                $branch,
                $reason,
                $settleOutstanding,
                $cancelledAt,
            ): LorryHire {
                $locked = LorryHire::query()
                    ->whereKey($hire->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status === LorryHireStatus::Cancelled) {
                    throw new BrokerConflictException('Lorry Hire is already cancelled.');
                }

                $locked->loadMissing(['adjustments', 'vouchers', 'bookBalanceEntries']);
                $summary = $this->calculations->summarize($locked);
                $outstanding = $summary['outstanding_amount'];

                if ($settleOutstanding && bccomp($outstanding, '0', 2) !== 0) {
                    $this->bookBalance->createSignedEntry($company, $branch, $actor, [
                        'transporter_party_id' => $locked->transporter_party_id,
                        'lorry_hire_id' => $locked->id,
                        'amount' => $outstanding,
                        'entry_type' => BookBalanceEntryType::LorryHireCancellationSettlement,
                        'entry_date' => $cancelledAt->toDateString(),
                        'reference_number' => $locked->hire_number,
                        'source_type' => TransporterBookBalanceService::SOURCE_LORRY_HIRE_CANCEL,
                        'source_id' => $locked->id,
                        'remark' => 'Lorry Hire cancellation settlement '.$locked->hire_number,
                    ]);
                }

                $before = [
                    'status' => $locked->status->value,
                    'outstanding_amount' => $this->money($locked->outstanding_amount),
                ];

                $locked->forceFill([
                    'status' => LorryHireStatus::Cancelled,
                    'cancelled_at' => $cancelledAt,
                    'cancellation_remark' => $reason,
                    'settle_outstanding' => $settleOutstanding,
                    'outstanding_amount' => $settleOutstanding && bccomp($outstanding, '0', 2) !== 0
                        ? '0.00'
                        : $this->money($locked->outstanding_amount),
                ])->save();

                $this->audit->write(
                    AuditAction::Cancelled,
                    LorryHire::class,
                    $locked->id,
                    remark: $reason,
                    before: $before,
                    after: [
                        'status' => LorryHireStatus::Cancelled->value,
                        'settle_outstanding' => $settleOutstanding,
                        'outstanding_amount' => $locked->outstanding_amount,
                    ],
                    cancellationReason: $reason,
                    cancellationAt: $locked->cancelled_at,
                );

                $this->accounting->postLorryHireCancelled($locked, $actor, $reason);

                return $locked->fresh([
                    'lorryReceipt',
                    'vehicle',
                    'transporter',
                    'goodsLines',
                    'tdsSnapshot',
                    'vouchers',
                    'adjustments',
                    'bookBalanceEntries',
                ]) ?? $locked;
            });
        });
    }

    private function assertCanCancel(User $actor, Company $company, Branch $branch): void
    {
        if ($actor->id === $company->owner_user_id) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LORRY_HIRE_CANCEL, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to cancel Broker Lorry Hires.');
        }
    }

    private function assertBrokerEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Broker)) {
            throw new BrokerDomainException('Broker module is not entitled for this company.');
        }
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param  numeric-string  $amount
     */
    private function formatSignedMoney(string $amount): string
    {
        $zero = '0.00';
        if (bccomp($amount, $zero, 2) < 0) {
            return '₹'.number_format((float) bcsub($zero, $amount, 2), 2).' (overpaid)';
        }

        return '₹'.number_format((float) $amount, 2);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(Company $company, Branch $branch, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: $branch->id,
            ownerId: (string) $company->owner_user_id,
            isInternal: false,
            isOwnerScope: false,
        ));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::inject($previous);
            }
        }
    }
}
