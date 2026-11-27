<?php

namespace App\Domains\Broker\Voucher;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Broker\Enums\BookBalanceEntryType;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\TransporterVoucher;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Presentation\Money;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transporter voucher lifecycle for Broker Lorry Hires (Rules §11).
 */
final class TransporterVoucherService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly DocumentNumberAllocator $numbers = new DocumentNumberAllocator,
        private readonly LorryHireCalculationService $calculations = new LorryHireCalculationService,
        private readonly TransporterBookBalanceService $bookBalance = new TransporterBookBalanceService,
        private readonly OperationalAccountingPoster $accounting = new OperationalAccountingPoster,
    ) {}

    /**
     * @return LengthAwarePaginator<int, TransporterVoucher>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $status = null,
        ?string $paymentMode = null,
        ?string $transporterPartyId = null,
        ?string $voucherDate = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $this->ensureTenantMatches($company, $branch);

        $builder = TransporterVoucher::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['lorryHire.lorryReceipt', 'lorryHire.vehicle'])
            ->orderByDesc('created_at');

        if (filled($status)) {
            $builder->where('status', $status);
        }
        if (filled($paymentMode)) {
            $builder->where('payment_mode', $paymentMode);
        }
        if (filled($transporterPartyId)) {
            $builder->where('transporter_party_id', $transporterPartyId);
        }
        if (filled($voucherDate)) {
            $builder->whereDate('voucher_date', $voucherDate);
        }

        return $builder->paginate($perPage);
    }

    public function findForBranch(Company $company, Branch $branch, string $voucherId): TransporterVoucher
    {
        $this->ensureTenantMatches($company, $branch);

        $voucher = TransporterVoucher::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereKey($voucherId)
            ->whereNull('deleted_at')
            ->with([
                'lorryHire.lorryReceipt',
                'lorryHire.vehicle',
                'lorryHire.transporter',
                'lorryHire.vouchers' => fn ($query) => $query->orderByDesc('created_at'),
                'lorryHire.adjustments',
                'lorryHire.tdsSnapshot',
                'bankCashAccount',
                'transporter',
            ])
            ->first();

        if ($voucher === null) {
            throw new BrokerDomainException('Voucher not found.');
        }

        return $voucher;
    }

    /**
     * @param  array{
     *     amount: string|float|int,
     *     payment_type: string,
     *     payment_mode: string,
     *     voucher_date?: string|null,
     *     bank_cash_account_id?: string|null,
     *     cash_reference?: string|null,
     *     pump_reference?: string|null,
     *     remark?: string|null,
     *     linked_bilty_group_id?: string|null,
     *     confirm_duplicate?: bool,
     * }  $payload
     */
    public function createForLorryHire(
        LorryHire $hire,
        User $actor,
        array $payload,
    ): TransporterVoucher {
        $company = Company::query()->findOrFail($hire->company_id);
        $branch = Branch::query()->findOrFail($hire->branch_id);

        $this->assertCanCreate($actor, $company, $branch);
        $this->assertBrokerEntitled($company);

        if ($hire->trashed() || $hire->status === LorryHireStatus::Cancelled) {
            throw new BrokerConflictException('Cannot create a voucher for a cancelled or deleted Lorry Hire.');
        }

        $paymentType = VoucherPaymentType::tryFrom((string) $payload['payment_type']);
        $paymentMode = VoucherPaymentMode::tryFrom((string) $payload['payment_mode']);
        if ($paymentType === null || $paymentMode === null) {
            throw ValidationException::withMessages([
                'payment_type' => 'Invalid payment type or mode.',
            ]);
        }

        $amount = $this->positiveMoney($payload['amount'], 'amount');
        $status = $this->defaultStatusForMode($paymentMode);
        $voucherDate = filled($payload['voucher_date'] ?? null)
            ? CarbonImmutable::parse((string) $payload['voucher_date'])->startOfDay()
            : CarbonImmutable::parse($this->defaultVoucherDate($company));

        try {
            return $this->auditedTransaction->run(function () use (
                $hire,
                $actor,
                $company,
                $branch,
                $payload,
                $paymentType,
                $paymentMode,
                $amount,
                $status,
                $voucherDate,
            ): TransporterVoucher {
                return $this->withTenant($company, $branch, function () use (
                    $hire,
                    $actor,
                    $company,
                    $branch,
                    $payload,
                    $paymentType,
                    $paymentMode,
                    $amount,
                    $status,
                    $voucherDate,
                ): TransporterVoucher {
                    $lockedHire = LorryHire::query()
                        ->whereKey($hire->id)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->firstOrFail();

                    $this->assertAmountWithinRemainingPayable($lockedHire, $amount);

                    if ($paymentMode === VoucherPaymentMode::AdjustInAdvance) {
                        $this->bookBalance->lockTransporter($company, (string) $lockedHire->transporter_party_id);
                        $this->bookBalance->assertAdjustmentWithinBalance(
                            $company,
                            $branch,
                            (string) $lockedHire->transporter_party_id,
                            $amount,
                        );
                    }

                    if (! ($payload['confirm_duplicate'] ?? false)) {
                        $this->assertNotDuplicate($lockedHire, $amount, $paymentMode, $voucherDate);
                    }

                    $allocation = $this->numbers->allocateNext(
                        companyId: $company->id,
                        documentType: DocumentNumberType::BrokerVoucher,
                        scopeType: DocumentNumberScopeType::Company,
                        branchId: null,
                    );

                    $voucher = TransporterVoucher::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'lorry_hire_id' => $lockedHire->id,
                        'linked_bilty_group_id' => filled($payload['linked_bilty_group_id'] ?? null)
                            ? (string) $payload['linked_bilty_group_id']
                            : null,
                        'voucher_number' => $allocation['number'],
                        'voucher_date' => $voucherDate,
                        'transporter_party_id' => $lockedHire->transporter_party_id,
                        'vehicle_id' => $lockedHire->vehicle_id,
                        'amount' => $amount,
                        'payment_type' => $paymentType,
                        'payment_mode' => $paymentMode,
                        'status' => $status,
                        'bank_cash_account_id' => filled($payload['bank_cash_account_id'] ?? null)
                            ? (string) $payload['bank_cash_account_id']
                            : null,
                        'cash_reference' => filled($payload['cash_reference'] ?? null)
                            ? trim((string) $payload['cash_reference'])
                            : null,
                        'pump_reference' => filled($payload['pump_reference'] ?? null)
                            ? trim((string) $payload['pump_reference'])
                            : null,
                        'remark' => filled($payload['remark'] ?? null)
                            ? trim((string) $payload['remark'])
                            : null,
                        'created_by_user_id' => $actor->id,
                    ]);

                    if ($paymentMode === VoucherPaymentMode::AdjustInAdvance) {
                        $this->bookBalance->createSignedEntry($company, $branch, $actor, [
                            'transporter_party_id' => $lockedHire->transporter_party_id,
                            'lorry_hire_id' => $lockedHire->id,
                            'transporter_voucher_id' => $voucher->id,
                            'amount' => bcmul($amount, '-1', 2),
                            'entry_type' => BookBalanceEntryType::AdjustInAdvanceVoucher,
                            'source_type' => TransporterBookBalanceService::SOURCE_VOUCHER_ADJUST,
                            'source_id' => $voucher->id,
                            'remark' => 'Adjust in Advance voucher '.$voucher->voucher_number,
                        ]);
                    }

                    $this->refreshHireBalances($lockedHire);

                    $this->audit->write(
                        AuditAction::Created,
                        TransporterVoucher::class,
                        $voucher->id,
                        remark: 'Create transporter voucher for Lorry Hire '.$lockedHire->hire_number,
                        before: null,
                        after: [
                            'amount' => $amount,
                            'payment_mode' => $paymentMode->value,
                            'status' => $status->value,
                        ],
                    );

                    return $voucher->fresh(['lorryHire']) ?? $voucher;
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new BrokerConflictException('Voucher number conflict.', previous: $e);
        }
    }

    public function transitionStatus(
        TransporterVoucher $voucher,
        User $actor,
        VoucherStatus $to,
    ): TransporterVoucher {
        $company = Company::query()->findOrFail($voucher->company_id);
        $branch = Branch::query()->findOrFail($voucher->branch_id);

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_STATUS, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to update voucher status.');
        }
        $this->assertBrokerEntitled($company);

        return $this->auditedTransaction->run(function () use ($voucher, $company, $branch, $actor, $to): TransporterVoucher {
            return $this->withTenant($company, $branch, function () use ($voucher, $actor, $to): TransporterVoucher {
                $locked = TransporterVoucher::query()
                    ->whereKey($voucher->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                $from = $locked->status;

                if ($from === VoucherStatus::Paid) {
                    throw new BrokerConflictException('Paid vouchers are terminal.');
                }

                $allowed = [];
                if ($from === VoucherStatus::Pending) {
                    $allowed = [VoucherStatus::InProgress];
                } elseif ($from === VoucherStatus::InProgress) {
                    $allowed = [VoucherStatus::Paid];
                }

                if (! in_array($to, $allowed, true)) {
                    throw new BrokerConflictException("Cannot transition voucher from {$from->value} to {$to->value}.");
                }

                $before = $locked->toArray();
                $locked->forceFill(['status' => $to])->save();

                $hire = LorryHire::query()->whereKey($locked->lorry_hire_id)->lockForUpdate()->firstOrFail();
                $this->refreshHireBalances($hire);

                $this->audit->write(
                    AuditAction::Updated,
                    TransporterVoucher::class,
                    $locked->id,
                    remark: "Voucher status {$from->value} → {$to->value}",
                    before: $before,
                    after: $locked->fresh()?->toArray(),
                );

                $fresh = $locked->fresh(['lorryHire']) ?? $locked;
                if ($to === VoucherStatus::Paid) {
                    $this->accounting->postTransporterVoucherPaid($fresh, $actor);
                }

                return $fresh;
            });
        });
    }

    public function defaultStatusForMode(VoucherPaymentMode $mode): VoucherStatus
    {
        return match ($mode) {
            VoucherPaymentMode::Bank, VoucherPaymentMode::PaidToPump => VoucherStatus::Pending,
            VoucherPaymentMode::Cash, VoucherPaymentMode::AdjustInAdvance => VoucherStatus::InProgress,
        };
    }

    private function refreshHireBalances(LorryHire $hire): void
    {
        $hire->load(['adjustments', 'vouchers', 'bookBalanceEntries']);
        $summary = $this->calculations->summarize($hire);

        $paid = '0.00';
        foreach ($hire->vouchers as $voucher) {
            if ($voucher->status === VoucherStatus::Paid) {
                $paid = bcadd($paid, $this->money($voucher->amount), 2);
            }
        }

        $hire->forceFill([
            'paid_amount' => $paid,
            'outstanding_amount' => $this->money($summary['outstanding_amount']),
        ])->save();
    }

    /**
     * Pending and In Progress vouchers reserve capacity, so the cap is the summary's remaining payable.
     *
     * @param  numeric-string  $amount
     */
    private function assertAmountWithinRemainingPayable(LorryHire $hire, string $amount): void
    {
        $remaining = $this->allocatableAmount($hire);
        if (bccomp($amount, $remaining, 2) === 1) {
            throw new BrokerConflictException(
                'Payment amount cannot exceed the remaining payable of '.Money::inr($remaining)
                .' for Lorry Hire '.$hire->hire_number.'.',
            );
        }
    }

    /**
     * A same-day voucher with identical amount and mode is treated as an accidental repeat unless confirmed.
     *
     * @param  numeric-string  $amount
     */
    private function assertNotDuplicate(
        LorryHire $hire,
        string $amount,
        VoucherPaymentMode $mode,
        CarbonImmutable $voucherDate,
    ): void {
        $exists = TransporterVoucher::query()
            ->where('lorry_hire_id', $hire->id)
            ->where('amount', $amount)
            ->where('payment_mode', $mode->value)
            ->whereDate('voucher_date', $voucherDate->toDateString())
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'confirm_duplicate' => 'A '.Money::inr($amount).' '.$mode->label().' voucher was already recorded for Lorry Hire '
                    .$hire->hire_number.' on '.$voucherDate->format('d-m-Y').'. Confirm to record it again.',
            ]);
        }
    }

    /**
     * @return numeric-string
     */
    public function allocatableAmount(LorryHire $hire): string
    {
        $hire->loadMissing(['adjustments', 'vouchers', 'bookBalanceEntries']);

        return $this->calculations->summarize($hire)['remaining_payable'];
    }

    /**
     * @param  list<string>  $voucherIds
     * @return list<TransporterVoucher>
     */
    public function bulkTransitionStatus(
        Company $company,
        Branch $branch,
        User $actor,
        array $voucherIds,
        VoucherStatus $targetStatus,
    ): array {
        return $this->auditedTransaction->run(function () use ($company, $branch, $actor, $voucherIds, $targetStatus): array {
            $updated = [];
            foreach ($voucherIds as $voucherId) {
                $voucher = $this->findForBranch($company, $branch, $voucherId);
                $updated[] = $this->transitionStatus($voucher, $actor, $targetStatus);
            }

            return $updated;
        });
    }

    private function assertCanCreate(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_CREATE, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to create transporter vouchers.');
        }
    }

    private function assertBrokerEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Broker)) {
            throw new BrokerDomainException('Broker module is not entitled for this company.');
        }
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new BrokerDomainException('Branch does not belong to company.');
        }
    }

    /**
     * Today in the company timezone; the Voucher Payment date default.
     */
    public function defaultVoucherDate(Company $company): string
    {
        return CarbonImmutable::now($this->companyTimezone($company))->toDateString();
    }

    private function companyTimezone(Company $company): string
    {
        $timezone = $company->timezone ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Kolkata';
    }

    /**
     * @return numeric-string
     */
    private function positiveMoney(mixed $value, string $field): string
    {
        $money = $this->money($value);
        if (bccomp($money, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                $field => 'Amount must be greater than zero.',
            ]);
        }

        return $money;
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
