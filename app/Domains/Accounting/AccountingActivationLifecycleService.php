<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Enums\AccountingPostingStatus;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Accounting\Exceptions\AccountingDomainException;
use App\Domains\Broker\Enums\BookBalanceEntryType;
use App\Domains\Broker\Enums\BrokerInvoiceStatus;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceStatus;
use App\Domains\Partload\Enums\PartloadChallanStatus;
use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Partload\Enums\PartloadMainChallanStatus;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\AccountingActivationState;
use App\Models\AccountingPostingLink;
use App\Models\AccountingVoucher;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\BrokerAdjustment;
use App\Models\BrokerInvoice;
use App\Models\BrokerInvoicePayment;
use App\Models\Company;
use App\Models\DriverAdvanceEntry;
use App\Models\GlLedger;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Models\PartloadChallan;
use App\Models\PartloadDeliveryReceipt;
use App\Models\PartloadInvoice;
use App\Models\PartloadInvoicePayment;
use App\Models\PartloadMainChallan;
use App\Models\SubscriptionInvoice;
use App\Models\TransporterBookBalanceEntry;
use App\Models\TransporterVoucher;
use App\Models\TripExpense;
use App\Models\TripInvoice;
use App\Models\TripInvoicePayment;
use App\Models\User;
use App\Models\VendorExpensePayment;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Existing-company Accounting cutover (§28) and expiry/renewal catch-up (§29).
 */
final class AccountingActivationLifecycleService
{
    public function __construct(
        private readonly ChartOfAccountsBootstrapService $coa = new ChartOfAccountsBootstrapService,
        private readonly OpeningBalanceService $openingBalances = new OpeningBalanceService,
        private readonly OperationalAccountingPoster $poster = new OperationalAccountingPoster,
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly ?CompanyCapabilityResolver $capabilities = null,
    ) {}

    public function ensureCutoverState(Company $company, ?User $actor = null): AccountingActivationState
    {
        $this->coa->ensureDefaults($company);
        $today = CarbonImmutable::now($company->timezone ?? 'Asia/Kolkata')->toDateString();

        /** @var AccountingActivationState|null $existing */
        $existing = AccountingActivationState::query()->where('company_id', $company->id)->first();
        if ($existing !== null) {
            if ($this->isActive($company) && $existing->last_expired_at !== null) {
                $existing->forceFill(['last_expired_at' => null])->save();
            }

            return $existing;
        }

        $state = AccountingActivationState::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'activated_at' => now(),
            'cutover_date' => $today,
            'setup_completed_at' => null,
            'activated_by_user_id' => $actor?->id,
        ]);

        $this->audit->write(
            AuditAction::Created,
            AccountingActivationState::class,
            $state->id,
            remark: 'Accounting cutover state initialized at '.$today,
            before: null,
            after: ['cutover_date' => $today],
        );

        return $state;
    }

    public function recordExpiryIfNeeded(Company $company): ?AccountingActivationState
    {
        if ($this->isActive($company)) {
            return AccountingActivationState::query()->where('company_id', $company->id)->first();
        }

        $state = AccountingActivationState::query()->where('company_id', $company->id)->first();
        if ($state !== null && $state->last_expired_at === null) {
            $state->forceFill(['last_expired_at' => now()])->save();
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(Company $company): array
    {
        $state = $this->ensureCutoverState($company);
        $this->recordExpiryIfNeeded($company);
        $state = $state->fresh() ?? $state;
        $operational = $this->operationalCutoverSummary($company);
        $catchup = $this->catchUpPreview($company, $state->cutover_date->toDateString());
        $ledgers = GlLedger::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (GlLedger $l): array => ['id' => $l->id, 'name' => $l->name])
            ->values()
            ->all();

        return [
            'entitlement_active' => $this->isActive($company),
            'entitlement_read' => $this->canRead($company),
            'state' => [
                'id' => $state->id,
                'activated_at' => $state->activated_at->toDateString(),
                'cutover_date' => $state->cutover_date->toDateString(),
                'setup_completed_at' => $state->setup_completed_at?->toDateTimeString(),
                'opening_voucher_id' => $state->opening_voucher_id,
                'last_expired_at' => $state->last_expired_at?->toDateString(),
                'last_catchup_from' => $state->last_catchup_from?->toDateString(),
                'last_catchup_to' => $state->last_catchup_to?->toDateString(),
            ],
            'operational_summary' => $operational,
            'catchup_preview' => $catchup,
            'ledgers' => $ledgers,
        ];
    }

    /**
     * Real operational balances prior to / at cutover (never synthetic).
     *
     * @return array<string, mixed>
     */
    public function operationalCutoverSummary(Company $company): array
    {
        $brokerReceivable = (string) BrokerInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', BrokerInvoiceStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->sum('balance_due');

        $tripReceivable = (string) TripInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', TripInvoiceStatus::Pending->value)
            ->whereNull('deleted_at')
            ->sum('balance_due');

        $partloadReceivable = (string) PartloadInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', 'issued')
            ->whereNull('deleted_at')
            ->sum('balance_due');

        $unbilledDueDr = (string) PartloadDeliveryReceipt::query()
            ->where('company_id', $company->id)
            ->where('payment_mode', PartloadDeliveryPaymentMode::Due->value)
            ->whereNull('deleted_at')
            ->whereHas('lorryReceipt', fn ($q) => $q->where('status', '!=', LrStatus::Invoiced->value)->whereNull('deleted_at'))
            ->sum('final_amount');

        $customerReceivable = bcadd(
            bcadd($this->money($brokerReceivable), $this->money($tripReceivable), 2),
            bcadd($this->money($partloadReceivable), $this->money($unbilledDueDr), 2),
            2,
        );

        $transporterPayable = $this->money(LorryHire::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', LorryHireStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->sum('outstanding_amount'));

        $openAdvanceVouchers = TransporterVoucher::query()
            ->where('company_id', $company->id)
            ->where('payment_type', VoucherPaymentType::Advance->value)
            ->where('status', VoucherStatus::Paid->value)
            ->whereNull('deleted_at')
            ->whereHas('lorryHire', fn ($q) => $q->where('outstanding_amount', '>', 0)->where('status', '!=', LorryHireStatus::Cancelled->value)->whereNull('deleted_at'))
            ->sum('amount');
        $manualBookBalanceNet = $this->money(TransporterBookBalanceEntry::query()
            ->where('company_id', $company->id)
            ->whereIn('entry_type', [
                BookBalanceEntryType::DirectAdvance->value,
                BookBalanceEntryType::BalanceRecovery->value,
                BookBalanceEntryType::BalanceAdjustment->value,
                BookBalanceEntryType::OpeningBalance->value,
                BookBalanceEntryType::AdjustInAdvanceVoucher->value,
            ])
            ->whereNull('deleted_at')
            ->sum('amount'));
        $positiveBookBalance = bccomp($manualBookBalanceNet, '0.00', 2) === 1 ? $manualBookBalanceNet : '0.00';
        $transporterAdvance = bcadd($this->money($openAdvanceVouchers), $positiveBookBalance, 2);

        $creditExpenses = TripExpense::query()
            ->where('company_id', $company->id)
            ->where('is_credit_payable', true)
            ->whereNull('settled_at')
            ->whereNull('deleted_at')
            ->get(['id', 'amount']);
        $vendorBilled = '0.00';
        $vendorPaid = '0.00';
        foreach ($creditExpenses as $exp) {
            $vendorBilled = bcadd($vendorBilled, $this->money($exp->amount), 2);
            $paidForExp = (string) VendorExpensePayment::query()
                ->where('company_id', $company->id)
                ->where('trip_expense_id', $exp->id)
                ->whereNull('deleted_at')
                ->sum('amount');
            $vendorPaid = bcadd($vendorPaid, $this->money($paidForExp), 2);
        }
        $vendorPayable = bcsub($vendorBilled, $vendorPaid, 2);
        if (bccomp($vendorPayable, '0.00', 2) === -1) {
            $vendorPayable = '0.00';
        }

        $driverRows = DriverAdvanceEntry::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->get(['entry_type', 'amount']);
        $driverAdvance = '0.00';
        foreach ($driverRows as $row) {
            $amt = $this->money($row->amount);
            $driverAdvance = $row->entry_type === 'advance'
                ? bcadd($driverAdvance, $amt, 2)
                : bcsub($driverAdvance, $amt, 2);
        }

        $accounts = BankCashAccount::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'account_kind'])
            ->map(fn (BankCashAccount $acc): array => [
                'id' => $acc->id,
                'name' => $acc->name,
                'account_type' => $acc->account_kind,
            ])->values()->all();

        if ($this->isActive($company)) {
            app(ChartOfAccountsBootstrapService::class)->ensureDefaults($company);
        }

        $suggestedLines = $this->buildSuggestedCutoverLines(
            $company,
            $customerReceivable,
            $transporterAdvance,
            $driverAdvance,
            $transporterPayable,
            $vendorPayable,
        );

        $hasOperationalBalances = bccomp($customerReceivable, '0.00', 2) === 1
            || bccomp($transporterPayable, '0.00', 2) === 1
            || bccomp($transporterAdvance, '0.00', 2) === 1
            || bccomp($vendorPayable, '0.00', 2) === 1
            || bccomp($driverAdvance, '0.00', 2) !== 0;

        return [
            'customer_receivables' => $customerReceivable,
            'transporter_payables' => $transporterPayable,
            'transporter_advances' => $transporterAdvance,
            'vendor_payables' => $vendorPayable,
            'driver_advances' => $driverAdvance,
            'bank_cash_accounts' => $accounts,
            'suggested_lines' => $suggestedLines,
            'has_operational_balances' => $hasOperationalBalances,
        ];
    }

    /**
     * @param  list<array{gl_ledger_id: string, debit_amount?: numeric-string|float|int|null, credit_amount?: numeric-string|float|int|null, narration?: string|null}>  $lines
     * @return array<string, mixed>
     */
    public function dryRunCutover(Company $company, array $lines, ?string $cutoverDate = null): array
    {
        $dryRun = $this->openingBalances->dryRun($company, $lines, $cutoverDate);
        $asOf = $dryRun['as_of'];

        $priorVoucherCount = AccountingVoucher::query()
            ->where('company_id', $company->id)
            ->whereDate('voucher_date', '<=', $asOf)
            ->where('voucher_type', '!=', AccountingVoucherType::Opening->value)
            ->whereNull('deleted_at')
            ->count();

        $state = $this->ensureCutoverState($company);

        return [
            ...$dryRun,
            'already_cutover' => $state->opening_voucher_id !== null,
            'existing_vouchers_before_cutover' => $priorVoucherCount,
            'double_recognition_warning' => $priorVoucherCount > 0
                ? "{$priorVoucherCount} operational voucher(s) are already posted on or before {$asOf}. Exclude those settled amounts from opening balances to prevent double recognition."
                : null,
        ];
    }

    /**
     * @param  list<array{gl_ledger_id: string, debit_amount?: numeric-string|float|int|null, credit_amount?: numeric-string|float|int|null, narration?: string|null}>|null  $lines
     * @return array{state: AccountingActivationState, voucher: AccountingVoucher|null}
     */
    public function activateCutover(
        Company $company,
        User $actor,
        ?array $lines = null,
        ?Branch $branch = null,
        ?string $cutoverDate = null,
    ): array {
        if (! $this->isActive($company)) {
            throw new AccountingDomainException('Active Accounting add-on entitlement is required.');
        }

        return $this->tx->run(function () use ($company, $actor, $lines, $branch, $cutoverDate): array {
            $state = $this->ensureCutoverState($company, $actor);
            /** @var AccountingActivationState $locked */
            $locked = AccountingActivationState::query()->whereKey($state->id)->lockForUpdate()->firstOrFail();

            $effectiveDate = filled($cutoverDate)
                ? CarbonImmutable::parse((string) $cutoverDate)->toDateString()
                : $locked->cutover_date->toDateString();

            $voucher = null;
            if (is_array($lines) && $lines !== []) {
                if ($locked->opening_voucher_id !== null) {
                    throw new AccountingDomainException('Opening balance cutover voucher is already posted and cannot be duplicated.');
                }

                $voucher = $this->openingBalances->post(
                    company: $company,
                    actor: $actor,
                    lines: $lines,
                    branch: $branch,
                    asOfDate: $effectiveDate,
                    cutoverKey: 'cutover:'.$effectiveDate,
                );

                $locked->forceFill([
                    'opening_voucher_id' => $voucher->id,
                ]);
            }

            $locked->forceFill([
                'cutover_date' => $effectiveDate,
                'setup_completed_at' => now(),
                'last_expired_at' => null,
                'activated_by_user_id' => $actor->id,
            ])->save();

            $this->audit->write(
                AuditAction::Updated,
                AccountingActivationState::class,
                $locked->id,
                remark: 'Confirmed Accounting activation cutover on '.$effectiveDate,
                before: null,
                after: [
                    'cutover_date' => $effectiveDate,
                    'opening_voucher_id' => $voucher?->id,
                ],
            );

            return [
                'state' => $locked->fresh() ?? $locked,
                'voucher' => $voucher,
            ];
        });
    }

    /**
     * Identify eligible operational transactions since cutover_date not yet linked in GL.
     *
     * @return array{
     *     from_date: string,
     *     to_date: string,
     *     unposted_count: int,
     *     breakdown: array<string, int>
     * }
     */
    public function catchUpPreview(Company $company, ?string $fromDate = null, ?string $toDate = null): array
    {
        $state = AccountingActivationState::query()->where('company_id', $company->id)->first();
        $from = filled($fromDate)
            ? CarbonImmutable::parse((string) $fromDate)->toDateString()
            : ($state?->cutover_date->toDateString() ?? CarbonImmutable::now($company->timezone ?? 'Asia/Kolkata')->toDateString());
        $to = filled($toDate)
            ? CarbonImmutable::parse((string) $toDate)->toDateString()
            : CarbonImmutable::now($company->timezone ?? 'Asia/Kolkata')->toDateString();

        $postedKeys = $this->postedLinkKeys($company);

        $counts = [
            'broker_invoices' => 0,
            'broker_payments' => 0,
            'lorry_hires' => 0,
            'broker_adjustments' => 0,
            'transporter_vouchers' => 0,
            'transporter_book_balance_entries' => 0,
            'trip_invoices' => 0,
            'trip_payments' => 0,
            'trip_expenses' => 0,
            'vendor_payments' => 0,
            'driver_advances' => 0,
            'partload_paid_bookings' => 0,
            'partload_challans' => 0,
            'partload_main_challans' => 0,
            'partload_delivery_receipts' => 0,
            'partload_invoices' => 0,
            'partload_payments' => 0,
            'subscription_invoices' => 0,
        ];

        BrokerInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', BrokerInvoiceStatus::Cancelled->value)
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[BrokerInvoice::class.":{$id}:invoice_issued"])) {
                    $counts['broker_invoices']++;
                }
            });

        BrokerInvoicePayment::query()
            ->where('company_id', $company->id)
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[BrokerInvoicePayment::class.":{$id}:invoice_payment"])) {
                    $counts['broker_payments']++;
                }
            });

        LorryHire::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', LorryHireStatus::Cancelled->value)
            ->whereDate('hire_date', '>=', $from)
            ->whereDate('hire_date', '<=', $to)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[LorryHire::class.":{$id}:hire_recognized"])) {
                    $counts['lorry_hires']++;
                }
            });

        BrokerAdjustment::query()
            ->where('company_id', $company->id)
            ->whereDate('occurred_at', '>=', $from)
            ->whereDate('occurred_at', '<=', $to)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[BrokerAdjustment::class.":{$id}:broker_adjustment"])) {
                    $counts['broker_adjustments']++;
                }
            });

        TransporterVoucher::query()
            ->where('company_id', $company->id)
            ->where('status', VoucherStatus::Paid->value)
            ->whereDate('voucher_date', '>=', $from)
            ->whereDate('voucher_date', '<=', $to)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[TransporterVoucher::class.":{$id}:transporter_voucher_paid"])) {
                    $counts['transporter_vouchers']++;
                }
            });

        TransporterBookBalanceEntry::query()
            ->where('company_id', $company->id)
            ->whereIn('entry_type', [
                BookBalanceEntryType::DirectAdvance->value,
                BookBalanceEntryType::BalanceRecovery->value,
                BookBalanceEntryType::BalanceAdjustment->value,
                BookBalanceEntryType::OpeningBalance->value,
            ])
            ->whereRaw('COALESCE(entry_date, CAST(created_at AS DATE)) >= ?', [$from])
            ->whereRaw('COALESCE(entry_date, CAST(created_at AS DATE)) <= ?', [$to])
            ->whereNull('deleted_at')
            ->get(['id', 'entry_type'])
            ->each(function (TransporterBookBalanceEntry $entry) use ($postedKeys, &$counts): void {
                $purpose = 'transporter_book_balance_'.$entry->entry_type->value;
                if (! isset($postedKeys[TransporterBookBalanceEntry::class.":{$entry->id}:{$purpose}"])) {
                    $counts['transporter_book_balance_entries']++;
                }
            });

        TripInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', TripInvoiceStatus::Pending->value)
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[TripInvoice::class.":{$id}:trip_invoice_issued"])) {
                    $counts['trip_invoices']++;
                }
            });

        TripInvoicePayment::query()
            ->where('company_id', $company->id)
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[TripInvoicePayment::class.":{$id}:trip_invoice_payment"])) {
                    $counts['trip_payments']++;
                }
            });

        TripExpense::query()
            ->where('company_id', $company->id)
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[TripExpense::class.":{$id}:trip_expense"])) {
                    $counts['trip_expenses']++;
                }
            });

        VendorExpensePayment::query()
            ->where('company_id', $company->id)
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[VendorExpensePayment::class.":{$id}:vendor_expense_payment"])) {
                    $counts['vendor_payments']++;
                }
            });

        DriverAdvanceEntry::query()
            ->where('company_id', $company->id)
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->whereNull('deleted_at')
            ->get(['id', 'entry_type'])
            ->each(function (DriverAdvanceEntry $entry) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[DriverAdvanceEntry::class.":{$entry->id}:driver_advance_{$entry->entry_type}"])) {
                    $counts['driver_advances']++;
                }
            });

        LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('workflow_context', LrWorkflowContext::Partload->value)
            ->where('freight_payment_term', PartloadFreightPaymentTerm::Paid->value)
            ->where('status', '!=', LrStatus::Cancelled->value)
            ->whereDate('lr_date', '>=', $from)
            ->whereDate('lr_date', '<=', $to)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[LorryReceipt::class.":{$id}:partload_paid_booking"])) {
                    $counts['partload_paid_bookings']++;
                }
            });

        PartloadChallan::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [PartloadChallanStatus::Dispatched->value, PartloadChallanStatus::Received->value])
            ->whereDate('challan_date', '>=', $from)
            ->whereDate('challan_date', '<=', $to)
            ->whereNull('deleted_at')
            ->where('total_amount', '>', 0)
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[PartloadChallan::class.":{$id}:partload_challan_dispatched"])) {
                    $counts['partload_challans']++;
                }
            });

        PartloadMainChallan::query()
            ->where('company_id', $company->id)
            ->where('status', PartloadMainChallanStatus::Dispatched->value)
            ->whereDate('main_challan_date', '>=', $from)
            ->whereDate('main_challan_date', '<=', $to)
            ->whereNull('deleted_at')
            ->where('total_amount', '>', 0)
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[PartloadMainChallan::class.":{$id}:partload_main_challan_dispatched"])) {
                    $counts['partload_main_challans']++;
                }
            });

        PartloadDeliveryReceipt::query()
            ->where('company_id', $company->id)
            ->whereDate('dr_date', '>=', $from)
            ->whereDate('dr_date', '<=', $to)
            ->whereNull('deleted_at')
            ->where('final_amount', '>', 0)
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[PartloadDeliveryReceipt::class.":{$id}:partload_delivery_receipt"])) {
                    $counts['partload_delivery_receipts']++;
                }
            });

        PartloadInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', 'issued')
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[PartloadInvoice::class.":{$id}:partload_invoice_issued"])) {
                    $counts['partload_invoices']++;
                }
            });

        PartloadInvoicePayment::query()
            ->where('company_id', $company->id)
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[PartloadInvoicePayment::class.":{$id}:partload_invoice_payment"])) {
                    $counts['partload_payments']++;
                }
            });

        SubscriptionInvoice::query()
            ->where('company_id', $company->id)
            ->where('document_type', 'tax_invoice')
            ->where('status', 'issued')
            ->whereDate('issued_at', '>=', $from)
            ->whereDate('issued_at', '<=', $to)
            ->pluck('id')
            ->each(function (string $id) use ($postedKeys, &$counts): void {
                if (! isset($postedKeys[SubscriptionInvoice::class.":{$id}:customer_subscription_expense"])) {
                    $counts['subscription_invoices']++;
                }
            });

        return [
            'from_date' => $from,
            'to_date' => $to,
            'unposted_count' => array_sum($counts),
            'breakdown' => $counts,
        ];
    }

    /**
     * Controlled idempotent catch-up posting for an unposted or post-renewal period (§28, §29).
     *
     * @return array{from_date: string, to_date: string, posted_count: int}
     */
    public function runCatchUp(Company $company, User $actor, ?string $fromDate = null, ?string $toDate = null): array
    {
        if (! $this->isActive($company)) {
            throw new AccountingDomainException('Active Accounting add-on entitlement is required to run catch-up postings.');
        }

        $state = $this->ensureCutoverState($company, $actor);
        $from = filled($fromDate)
            ? CarbonImmutable::parse((string) $fromDate)->toDateString()
            : $state->cutover_date->toDateString();
        $to = filled($toDate)
            ? CarbonImmutable::parse((string) $toDate)->toDateString()
            : CarbonImmutable::now($company->timezone ?? 'Asia/Kolkata')->toDateString();

        $beforeCount = AccountingPostingLink::query()
            ->where('company_id', $company->id)
            ->where('status', AccountingPostingStatus::Posted->value)
            ->count();

        $this->tx->run(function () use ($company, $actor, $from, $to, $state): void {
            BrokerInvoice::query()
                ->where('company_id', $company->id)
                ->where('status', '!=', BrokerInvoiceStatus::Cancelled->value)
                ->whereDate('invoice_date', '>=', $from)
                ->whereDate('invoice_date', '<=', $to)
                ->whereNull('deleted_at')
                ->orderBy('invoice_date')
                ->each(fn (BrokerInvoice $inv) => $this->poster->postBrokerInvoiceIssued($inv, $actor));

            BrokerInvoicePayment::query()
                ->where('company_id', $company->id)
                ->whereDate('payment_date', '>=', $from)
                ->whereDate('payment_date', '<=', $to)
                ->whereNull('deleted_at')
                ->with('brokerInvoice')
                ->orderBy('payment_date')
                ->each(function (BrokerInvoicePayment $p) use ($actor): void {
                    if ($p->brokerInvoice !== null) {
                        $this->poster->postBrokerInvoicePayment($p, $p->brokerInvoice, $actor);
                    }
                });

            LorryHire::query()
                ->where('company_id', $company->id)
                ->where('status', '!=', LorryHireStatus::Cancelled->value)
                ->whereDate('hire_date', '>=', $from)
                ->whereDate('hire_date', '<=', $to)
                ->whereNull('deleted_at')
                ->orderBy('hire_date')
                ->each(fn (LorryHire $hire) => $this->poster->postLorryHireRecognized($hire, $actor));

            BrokerAdjustment::query()
                ->where('company_id', $company->id)
                ->whereDate('occurred_at', '>=', $from)
                ->whereDate('occurred_at', '<=', $to)
                ->whereNull('deleted_at')
                ->with('lorryHire')
                ->orderBy('occurred_at')
                ->each(function (BrokerAdjustment $adj) use ($actor): void {
                    if ($adj->lorryHire !== null) {
                        $this->poster->postBrokerAdjustment($adj, $adj->lorryHire, $actor);
                    }
                });

            TransporterVoucher::query()
                ->where('company_id', $company->id)
                ->where('status', VoucherStatus::Paid->value)
                ->whereDate('voucher_date', '>=', $from)
                ->whereDate('voucher_date', '<=', $to)
                ->whereNull('deleted_at')
                ->orderBy('voucher_date')
                ->each(fn (TransporterVoucher $v) => $this->poster->postTransporterVoucherPaid($v, $actor));

            TransporterBookBalanceEntry::query()
                ->where('company_id', $company->id)
                ->whereIn('entry_type', [
                    BookBalanceEntryType::DirectAdvance->value,
                    BookBalanceEntryType::BalanceRecovery->value,
                    BookBalanceEntryType::BalanceAdjustment->value,
                    BookBalanceEntryType::OpeningBalance->value,
                ])
                ->whereRaw('COALESCE(entry_date, CAST(created_at AS DATE)) >= ?', [$from])
                ->whereRaw('COALESCE(entry_date, CAST(created_at AS DATE)) <= ?', [$to])
                ->whereNull('deleted_at')
                ->orderByRaw('COALESCE(entry_date, CAST(created_at AS DATE)) ASC')
                ->orderBy('created_at')
                ->each(fn (TransporterBookBalanceEntry $entry) => $this->poster->postTransporterBookBalanceEntry($entry, $actor));

            TripInvoice::query()
                ->where('company_id', $company->id)
                ->where('status', TripInvoiceStatus::Pending->value)
                ->whereDate('invoice_date', '>=', $from)
                ->whereDate('invoice_date', '<=', $to)
                ->whereNull('deleted_at')
                ->orderBy('invoice_date')
                ->each(fn (TripInvoice $inv) => $this->poster->postTripInvoiceIssued($inv, $actor));

            TripInvoicePayment::query()
                ->where('company_id', $company->id)
                ->whereDate('payment_date', '>=', $from)
                ->whereDate('payment_date', '<=', $to)
                ->with('invoice')
                ->orderBy('payment_date')
                ->each(function (TripInvoicePayment $p) use ($actor): void {
                    if ($p->invoice !== null) {
                        $this->poster->postTripInvoicePayment($p, $p->invoice, $actor);
                    }
                });

            TripExpense::query()
                ->where('company_id', $company->id)
                ->whereDate('expense_date', '>=', $from)
                ->whereDate('expense_date', '<=', $to)
                ->whereNull('deleted_at')
                ->with(['trip', 'category', 'paymentMode'])
                ->orderBy('expense_date')
                ->each(fn (TripExpense $e) => $this->poster->postTripExpense($e, $actor));

            VendorExpensePayment::query()
                ->where('company_id', $company->id)
                ->whereDate('payment_date', '>=', $from)
                ->whereDate('payment_date', '<=', $to)
                ->whereNull('deleted_at')
                ->orderBy('payment_date')
                ->each(fn (VendorExpensePayment $p) => $this->poster->postVendorExpensePayment($p, $actor));

            DriverAdvanceEntry::query()
                ->where('company_id', $company->id)
                ->whereDate('entry_date', '>=', $from)
                ->whereDate('entry_date', '<=', $to)
                ->whereNull('deleted_at')
                ->orderBy('entry_date')
                ->each(fn (DriverAdvanceEntry $entry) => $this->poster->postDriverAdvanceEntry($entry, $actor));

            LorryReceipt::query()
                ->where('company_id', $company->id)
                ->where('workflow_context', LrWorkflowContext::Partload->value)
                ->where('freight_payment_term', PartloadFreightPaymentTerm::Paid->value)
                ->where('status', '!=', LrStatus::Cancelled->value)
                ->whereDate('lr_date', '>=', $from)
                ->whereDate('lr_date', '<=', $to)
                ->whereNull('deleted_at')
                ->orderBy('lr_date')
                ->each(fn (LorryReceipt $lr) => $this->poster->postPartloadPaidBooking($lr, $actor));

            PartloadChallan::query()
                ->where('company_id', $company->id)
                ->whereIn('status', [PartloadChallanStatus::Dispatched->value, PartloadChallanStatus::Received->value])
                ->whereDate('challan_date', '>=', $from)
                ->whereDate('challan_date', '<=', $to)
                ->whereNull('deleted_at')
                ->orderBy('challan_date')
                ->each(fn (PartloadChallan $c) => $this->poster->postPartloadChallanDispatched($c, $actor));

            PartloadMainChallan::query()
                ->where('company_id', $company->id)
                ->where('status', PartloadMainChallanStatus::Dispatched->value)
                ->whereDate('main_challan_date', '>=', $from)
                ->whereDate('main_challan_date', '<=', $to)
                ->whereNull('deleted_at')
                ->orderBy('main_challan_date')
                ->each(fn (PartloadMainChallan $m) => $this->poster->postPartloadMainChallanDispatched($m, $actor));

            PartloadDeliveryReceipt::query()
                ->where('company_id', $company->id)
                ->whereDate('dr_date', '>=', $from)
                ->whereDate('dr_date', '<=', $to)
                ->whereNull('deleted_at')
                ->with('lorryReceipt')
                ->orderBy('dr_date')
                ->each(fn (PartloadDeliveryReceipt $dr) => $this->poster->postPartloadDeliveryReceipt($dr, $actor));

            PartloadInvoice::query()
                ->where('company_id', $company->id)
                ->where('status', 'issued')
                ->whereDate('invoice_date', '>=', $from)
                ->whereDate('invoice_date', '<=', $to)
                ->whereNull('deleted_at')
                ->orderBy('invoice_date')
                ->each(fn (PartloadInvoice $inv) => $this->poster->postPartloadInvoiceIssued($inv, $actor));

            PartloadInvoicePayment::query()
                ->where('company_id', $company->id)
                ->whereDate('payment_date', '>=', $from)
                ->whereDate('payment_date', '<=', $to)
                ->whereNull('deleted_at')
                ->with('invoice')
                ->orderBy('payment_date')
                ->each(function (PartloadInvoicePayment $p) use ($actor): void {
                    if ($p->invoice !== null) {
                        $this->poster->postPartloadInvoicePayment($p, $p->invoice, $actor);
                    }
                });

            SubscriptionInvoice::query()
                ->where('company_id', $company->id)
                ->where('document_type', 'tax_invoice')
                ->whereIn('status', [
                    SubscriptionInvoice::STATUS_APPROVED,
                    SubscriptionInvoice::STATUS_ISSUED,
                    SubscriptionInvoice::STATUS_EMAILED,
                ])
                ->whereDate('issued_at', '>=', $from)
                ->whereDate('issued_at', '<=', $to)
                ->orderBy('issued_at')
                ->each(fn (SubscriptionInvoice $s) => $this->poster->postSubscriptionExpenseForCustomer($s, $company, $actor));

            $state->forceFill([
                'last_catchup_from' => $from,
                'last_catchup_to' => $to,
                'last_expired_at' => null,
            ])->save();
        });

        $afterCount = AccountingPostingLink::query()
            ->where('company_id', $company->id)
            ->where('status', AccountingPostingStatus::Posted->value)
            ->count();

        return [
            'from_date' => $from,
            'to_date' => $to,
            'posted_count' => max(0, $afterCount - $beforeCount),
        ];
    }

    /**
     * @return list<array{gl_ledger_id: string, ledger_name: string, debit_amount: string, credit_amount: string, narration: string}>
     */
    private function buildSuggestedCutoverLines(
        Company $company,
        string $customerReceivable,
        string $transporterAdvance,
        string $driverAdvance,
        string $transporterPayable,
        string $vendorPayable,
    ): array {
        $ledgers = GlLedger::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('name');

        $lines = [];
        $netDebit = '0.00';

        $addDebit = static function (string $name, string $amount, string $narration) use ($ledgers, &$lines, &$netDebit): void {
            /** @var numeric-string $normalized */
            $normalized = number_format((float) $amount, 2, '.', '');
            if (bccomp($normalized, '0.00', 2) !== 1 || ! $ledgers->has($name)) {
                return;
            }
            $ledger = $ledgers->get($name);
            $lines[] = [
                'gl_ledger_id' => $ledger->id,
                'ledger_name' => $ledger->name,
                'debit_amount' => $normalized,
                'credit_amount' => '0.00',
                'narration' => $narration,
            ];
            $netDebit = bcadd($netDebit, $normalized, 2);
        };

        $addCredit = static function (string $name, string $amount, string $narration) use ($ledgers, &$lines, &$netDebit): void {
            /** @var numeric-string $normalized */
            $normalized = number_format((float) $amount, 2, '.', '');
            if (bccomp($normalized, '0.00', 2) !== 1 || ! $ledgers->has($name)) {
                return;
            }
            $ledger = $ledgers->get($name);
            $lines[] = [
                'gl_ledger_id' => $ledger->id,
                'ledger_name' => $ledger->name,
                'debit_amount' => '0.00',
                'credit_amount' => $normalized,
                'narration' => $narration,
            ];
            $netDebit = bcsub($netDebit, $normalized, 2);
        };

        $addDebit('Customer Receivables', $customerReceivable, 'Cutover open customer receivables');
        $addDebit('Transporter Advances', $transporterAdvance, 'Cutover open transporter advances');
        if (bccomp(number_format((float) $driverAdvance, 2, '.', ''), '0.00', 2) === 1) {
            $addDebit('Driver Advances', $driverAdvance, 'Cutover net driver advance receivables');
        }
        $addCredit('Transporter Payables', $transporterPayable, 'Cutover open transporter payables');
        $addCredit('Vendor Payables', $vendorPayable, 'Cutover open vendor payables');

        /** @var numeric-string $netBalance */
        $netBalance = number_format((float) $netDebit, 2, '.', '');
        if ($lines !== [] && bccomp($netBalance, '0.00', 2) !== 0 && $ledgers->has('Capital')) {
            /** @var GlLedger $capital */
            $capital = $ledgers->get('Capital');
            $abs = number_format(abs((float) $netBalance), 2, '.', '');
            $lines[] = [
                'gl_ledger_id' => $capital->id,
                'ledger_name' => $capital->name,
                'debit_amount' => bccomp($netBalance, '0.00', 2) === -1 ? $abs : '0.00',
                'credit_amount' => bccomp($netBalance, '0.00', 2) === 1 ? $abs : '0.00',
                'narration' => 'Cutover balancing equity adjustment',
            ];
        }

        return $lines;
    }

    /**
     * @return array<string, bool>
     */
    private function postedLinkKeys(Company $company): array
    {
        $rows = AccountingPostingLink::query()
            ->where('company_id', $company->id)
            ->where('status', AccountingPostingStatus::Posted->value)
            ->get(['source_type', 'source_id', 'posting_purpose']);

        $keys = [];
        foreach ($rows as $row) {
            $keys["{$row->source_type}:{$row->source_id}:{$row->posting_purpose}"] = true;
        }

        return $keys;
    }

    private function isActive(Company $company): bool
    {
        return ($this->capabilities ?? app(CompanyCapabilityResolver::class))
            ->allowsMutate($company, CompanyCapabilities::ACCOUNTING);
    }

    private function canRead(Company $company): bool
    {
        return ($this->capabilities ?? app(CompanyCapabilityResolver::class))
            ->allowsRead($company, CompanyCapabilities::ACCOUNTING);
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
