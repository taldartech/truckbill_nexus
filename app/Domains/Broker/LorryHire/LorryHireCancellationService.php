<?php

namespace App\Domains\Broker\LorryHire;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Broker\Enums\BookBalanceEntryType;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\Voucher\TransporterBookBalanceService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
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
use App\Support\Presentation\Money;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Broker Lorry Hire cancellation — recover only eligible paid voucher amounts to book (PO).
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
        private readonly TransporterVoucherService $vouchers = new TransporterVoucherService,
        private readonly OperationalAccountingPoster $accounting = new OperationalAccountingPoster,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(LorryHire $hire): array
    {
        $hire->loadMissing(['adjustments', 'vouchers', 'bookBalanceEntries', 'transporter']);
        $summary = $this->calculations->cancellationSettlement($hire);

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
                'payment_mode' => $voucher->payment_mode?->value,
            ];
        }

        $eligibleTotal = $summary['eligible_settlement_total'];
        $eligiblePaid = $summary['eligible_paid_voucher_settlement'];
        $eligibleAdvance = $summary['eligible_advance_restore'];

        return [
            'hire_number' => $hire->hire_number,
            'transporter_name' => $hire->transporter?->name,
            'freight_total' => $summary['freight_total'],
            'tds_amount' => $summary['tds_amount'],
            'net_payable' => $summary['net_payable'],
            'paid_voucher_amount' => $summary['paid_voucher_amount'],
            'pending_voucher_amount' => $summary['pending_voucher_amount'],
            'in_progress_voucher_amount' => $summary['in_progress_voucher_amount'],
            'unpaid_balance' => $summary['unpaid_balance'],
            'outstanding_amount' => $summary['outstanding_amount'],
            'balance_due' => $summary['balance_due'],
            'negative_adjusted_amount' => $summary['negative_adjusted_amount'],
            'eligible_paid_voucher_settlement' => $eligiblePaid,
            'eligible_advance_restore' => $eligibleAdvance,
            'eligible_settlement_total' => $eligibleTotal,
            'adjustments' => $adjustments,
            'vouchers' => $vouchers,
            'settle_yes_description' => bccomp($eligibleTotal, '0', 2) === 0
                ? ''
                : $this->settleYesDescription($eligiblePaid, $eligibleAdvance),
            'settle_no_description' => bccomp($eligibleTotal, '0', 2) === 0
                ? 'No eligible paid voucher amount is available for book settlement.'
                : 'Keep paid voucher history on this cancelled hire without transferring amounts to the transporter book.',
            'can_settle_to_book' => bccomp($eligibleTotal, '0', 2) === 1,
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

        $settleToBook = (bool) $payload['settle_outstanding'];

        $cancelledAt = filled($payload['cancelled_at'] ?? null)
            ? CarbonImmutable::parse((string) $payload['cancelled_at'])
            : CarbonImmutable::now('UTC');

        return $this->auditedTransaction->run(function () use (
            $hire,
            $actor,
            $company,
            $branch,
            $reason,
            $settleToBook,
            $cancelledAt,
        ): LorryHire {
            return $this->withTenant($company, $branch, function () use (
                $hire,
                $actor,
                $company,
                $branch,
                $reason,
                $settleToBook,
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

                $this->vouchers->releaseUnpaidReservationsForCancelledHire($company, $branch, $actor, $locked);

                $locked->refresh();
                $locked->loadMissing(['adjustments', 'vouchers', 'bookBalanceEntries']);
                $settlement = $this->calculations->cancellationSettlement($locked);

                if ($settleToBook) {
                    $eligiblePaid = $settlement['eligible_paid_voucher_settlement'];
                    $eligibleAdvance = $settlement['eligible_advance_restore'];

                    if (bccomp($eligiblePaid, '0', 2) === 1) {
                        $this->bookBalance->createSignedEntry($company, $branch, $actor, [
                            'transporter_party_id' => $locked->transporter_party_id,
                            'lorry_hire_id' => $locked->id,
                            'amount' => $eligiblePaid,
                            'entry_type' => BookBalanceEntryType::LorryHireCancellationSettlement,
                            'entry_date' => $cancelledAt->toDateString(),
                            'reference_number' => $locked->hire_number,
                            'source_type' => TransporterBookBalanceService::SOURCE_LORRY_HIRE_CANCEL,
                            'source_id' => $locked->id,
                            'remark' => 'Paid voucher recovery on Lorry Hire cancellation '.$locked->hire_number,
                        ]);
                    }

                    if (bccomp($eligibleAdvance, '0', 2) === 1) {
                        $this->bookBalance->createSignedEntry($company, $branch, $actor, [
                            'transporter_party_id' => $locked->transporter_party_id,
                            'lorry_hire_id' => $locked->id,
                            'amount' => $eligibleAdvance,
                            'entry_type' => BookBalanceEntryType::ReversalCompensation,
                            'entry_date' => $cancelledAt->toDateString(),
                            'reference_number' => $locked->hire_number,
                            'source_type' => TransporterBookBalanceService::SOURCE_LORRY_HIRE_CANCEL_ADVANCE,
                            'source_id' => $locked->id,
                            'remark' => 'Restore advance consumed by paid vouchers — '.$locked->hire_number,
                        ]);
                    }
                }

                $before = [
                    'status' => $locked->status->value,
                    'outstanding_amount' => $this->money($locked->outstanding_amount),
                ];

                $locked->forceFill([
                    'status' => LorryHireStatus::Cancelled,
                    'cancelled_at' => $cancelledAt,
                    'cancellation_remark' => $reason,
                    'settle_outstanding' => $settleToBook,
                ])->save();

                $this->vouchers->refreshHireBalances($locked->fresh() ?? $locked);

                $locked = $locked->fresh() ?? $locked;

                $this->audit->write(
                    AuditAction::Cancelled,
                    LorryHire::class,
                    $locked->id,
                    remark: $reason,
                    before: $before,
                    after: [
                        'status' => LorryHireStatus::Cancelled->value,
                        'settle_outstanding' => $settleToBook,
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

    private function settleYesDescription(string $eligiblePaid, string $eligibleAdvance): string
    {
        $parts = [];
        if (bccomp($eligiblePaid, '0', 2) === 1) {
            $parts[] = 'transfer eligible paid voucher amount of '.Money::inr($eligiblePaid).' to the Transporter Book Balance';
        }
        if (bccomp($eligibleAdvance, '0', 2) === 1) {
            $parts[] = 'restore '.Money::inr($eligibleAdvance).' of previously consumed advance to the book balance';
        }

        return 'Yes — '.implode('; ', $parts).'.';
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
