<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Enums\AccountingPostingStatus;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\AccountingPostingLink;
use App\Models\AccountingVoucher;
use App\Models\Branch;
use App\Models\BrokerAdjustment;
use App\Models\BrokerInvoice;
use App\Models\BrokerInvoicePayment;
use App\Models\Company;
use App\Models\DriverAdvanceEntry;
use App\Models\GlLedger;
use App\Models\LorryHire;
use App\Models\LorryHireRevision;
use App\Models\LorryReceipt;
use App\Models\PartloadChallan;
use App\Models\PartloadDeliveryReceipt;
use App\Models\PartloadInvoice;
use App\Models\PartloadInvoicePayment;
use App\Models\PartloadMainChallan;
use App\Models\SubscriptionInvoice;
use App\Models\TransporterVoucher;
use App\Models\TripExpense;
use App\Models\TripInvoice;
use App\Models\TripInvoicePayment;
use App\Models\User;
use App\Models\VendorExpensePayment;
use Illuminate\Support\Str;

/**
 * Translates operational financial events into the shared accounting engine (ADR-010).
 * Automatically posts ONLY when the Company holds active Accounting add-on entitlement,
 * while preserving controlled reversals/settlements for already-posted source records.
 */
final class OperationalAccountingPoster
{
    public function __construct(
        private readonly VoucherPostingService $posting = new VoucherPostingService,
        private readonly SystemLedgerResolver $ledgers = new SystemLedgerResolver,
        private readonly AccountingPeriodService $periods = new AccountingPeriodService,
        private readonly ChartOfAccountsBootstrapService $coa = new ChartOfAccountsBootstrapService,
    ) {}

    public function shouldAutoPost(Company $company): bool
    {
        return app(CompanyCapabilityResolver::class)
            ->allowsMutate($company, CompanyCapabilities::ACCOUNTING);
    }

    public function hasPostedLink(Company $company, string $sourceType, string $sourceId, ?string $purpose = null): bool
    {
        return AccountingPostingLink::query()
            ->where('company_id', $company->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->when($purpose !== null, fn ($q) => $q->where('posting_purpose', $purpose))
            ->where('status', AccountingPostingStatus::Posted->value)
            ->exists();
    }

    public function postBrokerInvoiceIssued(BrokerInvoice $invoice, User $actor, bool $allowBypass = false): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        if (! $allowBypass && ! $this->shouldAutoPost($company)) {
            return;
        }

        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->prepare($company);

        $receivable = $this->ledgers->requireByName($company, 'Customer Receivables');
        $freightIncome = $this->ledgers->requireByName($company, 'Freight Income');

        $taxable = $this->money($invoice->taxable_total);
        $gst = $this->money($invoice->gst_amount);
        $total = $this->money($invoice->invoice_total);
        if (bccomp($total, '0', 2) !== 1) {
            return;
        }

        $zero = $this->zeroAmount();
        $lines = [
            ['gl_ledger_id' => $receivable->id, 'debit_amount' => $total, 'credit_amount' => $zero, 'narration' => 'Customer invoice '.$invoice->invoice_number],
            ['gl_ledger_id' => $freightIncome->id, 'debit_amount' => $zero, 'credit_amount' => $taxable, 'narration' => 'Freight revenue'],
            ...$this->buildOutputGstCreditLines($company, $invoice->gst_rate_snapshot ?? [], $gst),
        ];

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Sales,
                'voucher_date' => $invoice->invoice_date?->toDateString() ?? now()->toDateString(),
                'narration' => 'Broker invoice '.$invoice->invoice_number,
                'branch_id' => $branch->id,
                'source_type' => BrokerInvoice::class,
                'source_id' => $invoice->id,
                'posting_purpose' => 'invoice_issued',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowBypass,
        );
    }

    public function postBrokerInvoiceCancelled(BrokerInvoice $invoice, User $actor, string $remark): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $this->reverseBySource(
            $company,
            BrokerInvoice::class,
            $invoice->id,
            'invoice_issued',
            $actor,
            'Cancel Broker invoice '.$invoice->invoice_number.': '.$remark,
        );
    }

    public function postBrokerInvoicePayment(
        BrokerInvoicePayment $payment,
        BrokerInvoice $invoice,
        User $actor,
        bool $allowBypass = false,
    ): void {
        $company = Company::query()->findOrFail($invoice->company_id);
        $allowControlled = $allowBypass || $this->hasPostedLink($company, BrokerInvoice::class, $invoice->id, 'invoice_issued');
        if (! $this->shouldAutoPost($company) && ! $allowControlled) {
            return;
        }

        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->prepare($company);

        $receivable = $this->ledgers->requireByName($company, 'Customer Receivables');
        $cashOrBank = $this->resolveCashOrBank($company, $payment->bank_cash_account_id, $payment->payment_mode);
        $amount = $this->money($payment->amount_received);
        $tds = $this->money($payment->tds_amount);
        $deduction = $this->money($payment->deduction_amount);

        $zero = $this->zeroAmount();
        $lines = [];
        if (bccomp($amount, '0', 2) === 1) {
            $lines[] = ['gl_ledger_id' => $cashOrBank->id, 'debit_amount' => $amount, 'credit_amount' => $zero, 'narration' => 'Receipt '.$invoice->invoice_number];
        }
        if (bccomp($tds, '0', 2) === 1) {
            $tdsLedger = $this->ledgers->requireByName($company, 'TDS Receivable');
            $lines[] = ['gl_ledger_id' => $tdsLedger->id, 'debit_amount' => $tds, 'credit_amount' => $zero, 'narration' => 'Customer TDS '.$invoice->invoice_number];
        }
        if (bccomp($deduction, '0', 2) === 1) {
            $freightIncome = $this->ledgers->requireByName($company, 'Freight Income');
            $lines[] = ['gl_ledger_id' => $freightIncome->id, 'debit_amount' => $deduction, 'credit_amount' => $zero, 'narration' => 'Invoice deduction '.$invoice->invoice_number];
        }

        $credit = bcadd($amount, bcadd($tds, $deduction, 2), 2);
        if (bccomp($credit, '0', 2) !== 1) {
            return;
        }
        $lines[] = ['gl_ledger_id' => $receivable->id, 'debit_amount' => $zero, 'credit_amount' => $credit, 'narration' => 'Settle invoice '.$invoice->invoice_number];

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Receipt,
                'voucher_date' => $payment->payment_date->toDateString(),
                'narration' => 'Customer receipt for '.$invoice->invoice_number,
                'branch_id' => $branch->id,
                'source_type' => BrokerInvoicePayment::class,
                'source_id' => $payment->id,
                'posting_purpose' => 'invoice_payment',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowControlled,
        );
    }

    public function postLorryHireRecognized(LorryHire $hire, User $actor, bool $allowBypass = false): void
    {
        $company = Company::query()->findOrFail($hire->company_id);
        if (! $allowBypass && ! $this->shouldAutoPost($company)) {
            return;
        }

        $branch = Branch::query()->findOrFail($hire->branch_id);
        $this->prepare($company);

        $expense = $this->ledgers->requireByName($company, 'Transporter Freight Expense');
        $payable = $this->ledgers->requireByName($company, 'Transporter Payables');
        $freight = $this->money($hire->freight_total);
        if (bccomp($freight, '0', 2) !== 1) {
            return;
        }

        $tds = $this->money($hire->tds_amount);
        if (bccomp($tds, $freight, 2) === 1) {
            $tds = '0.00';
        }
        $netPayable = bcsub($freight, $tds, 2);

        $zero = $this->zeroAmount();
        $lines = [
            ['gl_ledger_id' => $expense->id, 'debit_amount' => $freight, 'credit_amount' => $zero, 'narration' => 'Transporter freight'],
        ];
        if (bccomp($netPayable, '0', 2) === 1) {
            $lines[] = ['gl_ledger_id' => $payable->id, 'debit_amount' => $zero, 'credit_amount' => $netPayable, 'narration' => 'Transporter payable'];
        }
        if (bccomp($tds, '0', 2) === 1) {
            $tdsPayable = $this->ledgers->requireByName($company, 'TDS Payable');
            $lines[] = ['gl_ledger_id' => $tdsPayable->id, 'debit_amount' => $zero, 'credit_amount' => $tds, 'narration' => 'Transporter TDS'];
        }

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Purchase,
                'voucher_date' => $hire->hire_date?->toDateString() ?? now()->toDateString(),
                'narration' => 'Lorry Hire '.$hire->hire_number,
                'branch_id' => $branch->id,
                'source_type' => LorryHire::class,
                'source_id' => $hire->id,
                'posting_purpose' => 'hire_recognized',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowBypass,
        );
    }

    public function postLorryHireCancelled(LorryHire $hire, User $actor, string $remark): void
    {
        $company = Company::query()->findOrFail($hire->company_id);
        $this->reverseBySource(
            $company,
            LorryHire::class,
            $hire->id,
            'hire_recognized',
            $actor,
            'Cancel Lorry Hire '.$hire->hire_number.': '.$remark,
        );

        $revisionIds = LorryHireRevision::query()
            ->where('company_id', $hire->company_id)
            ->where('lorry_hire_id', $hire->id)
            ->pluck('id');

        foreach ($revisionIds as $revisionId) {
            $this->reverseBySource(
                $company,
                LorryHireRevision::class,
                (string) $revisionId,
                'hire_revised',
                $actor,
                'Cancel Lorry Hire '.$hire->hire_number.': '.$remark,
            );
        }
    }

    /**
     * Delta journal for an edited Lorry Hire; the original recognition voucher is never rewritten.
     */
    public function postLorryHireRevised(LorryHireRevision $revision, LorryHire $hire, User $actor): void
    {
        $company = Company::query()->findOrFail($hire->company_id);
        $allowControlled = $this->hasPostedLink($company, LorryHire::class, $hire->id, 'hire_recognized');
        if (! $this->shouldAutoPost($company) && ! $allowControlled) {
            return;
        }

        $freightDelta = $this->money($revision->freight_delta);
        $tdsDelta = $this->money($revision->tds_delta);
        if (bccomp($freightDelta, '0', 2) === 0 && bccomp($tdsDelta, '0', 2) === 0) {
            return;
        }
        $payableDelta = bcsub($freightDelta, $tdsDelta, 2);

        $branch = Branch::query()->findOrFail($hire->branch_id);
        $this->prepare($company);

        $expense = $this->ledgers->requireByName($company, 'Transporter Freight Expense');
        $payable = $this->ledgers->requireByName($company, 'Transporter Payables');
        $zero = $this->zeroAmount();

        $lines = array_values(array_filter([
            $this->signedJournalLine($expense->id, $freightDelta, $zero, 'Lorry Hire edit freight change'),
            $this->signedJournalLine($payable->id, bcmul($payableDelta, '-1', 2), $zero, 'Lorry Hire edit payable change'),
            bccomp($tdsDelta, '0', 2) !== 0
                ? $this->signedJournalLine(
                    $this->ledgers->requireByName($company, 'TDS Payable')->id,
                    bcmul($tdsDelta, '-1', 2),
                    $zero,
                    'Lorry Hire edit TDS change',
                )
                : null,
        ]));

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Journal,
                'voucher_date' => $revision->created_at?->toDateString() ?? now()->toDateString(),
                'narration' => Str::limit('Lorry Hire edit ('.$hire->hire_number.'): '.$revision->remark, 1990),
                'branch_id' => $branch->id,
                'source_type' => LorryHireRevision::class,
                'source_id' => $revision->id,
                'posting_purpose' => 'hire_revised',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowControlled,
        );
    }

    public function postBrokerAdjustment(
        BrokerAdjustment $adjustment,
        LorryHire $hire,
        User $actor,
        ?string $freightDelta = null,
        bool $allowBypass = false,
    ): void {
        $company = Company::query()->findOrFail($hire->company_id);
        $allowControlled = $allowBypass || $this->hasPostedLink($company, LorryHire::class, $hire->id, 'hire_recognized');
        if (! $this->shouldAutoPost($company) && ! $allowControlled) {
            return;
        }

        $branch = Branch::query()->findOrFail($hire->branch_id);
        $this->prepare($company);

        $expense = $this->ledgers->requireByName($company, 'Transporter Freight Expense');
        $payable = $this->ledgers->requireByName($company, 'Transporter Payables');
        $zero = $this->zeroAmount();

        $type = $adjustment->type;
        if ($type === BrokerAdjustmentType::UnloadingWeight) {
            $delta = number_format((float) ($freightDelta ?? 0), 2, '.', '');
            if (bccomp($delta, '0', 2) === 0) {
                return;
            }
            $absDelta = number_format(abs((float) $delta), 2, '.', '');
            $lines = bccomp($delta, '0', 2) > 0
                ? [
                    ['gl_ledger_id' => $expense->id, 'debit_amount' => $absDelta, 'credit_amount' => $zero, 'narration' => 'Unloading weight freight increase'],
                    ['gl_ledger_id' => $payable->id, 'debit_amount' => $zero, 'credit_amount' => $absDelta, 'narration' => 'Unloading weight payable increase'],
                ]
                : [
                    ['gl_ledger_id' => $payable->id, 'debit_amount' => $absDelta, 'credit_amount' => $zero, 'narration' => 'Unloading weight freight reduction'],
                    ['gl_ledger_id' => $expense->id, 'debit_amount' => $zero, 'credit_amount' => $absDelta, 'narration' => 'Unloading weight freight reduction'],
                ];
        } else {
            $amount = $this->money($adjustment->amount);
            if (bccomp($amount, '0', 2) !== 1) {
                return;
            }
            $isCreditToTransporter = in_array($type, [BrokerAdjustmentType::Detention, BrokerAdjustmentType::Deviation], true);
            $lines = $isCreditToTransporter
                ? [
                    ['gl_ledger_id' => $expense->id, 'debit_amount' => $amount, 'credit_amount' => $zero, 'narration' => 'Broker '.$type->value.' on '.$hire->hire_number],
                    ['gl_ledger_id' => $payable->id, 'debit_amount' => $zero, 'credit_amount' => $amount, 'narration' => 'Broker '.$type->value.' payable'],
                ]
                : [
                    ['gl_ledger_id' => $payable->id, 'debit_amount' => $amount, 'credit_amount' => $zero, 'narration' => 'Broker '.$type->value.' recovery on '.$hire->hire_number],
                    ['gl_ledger_id' => $expense->id, 'debit_amount' => $zero, 'credit_amount' => $amount, 'narration' => 'Broker '.$type->value.' recovery'],
                ];
        }

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Journal,
                'voucher_date' => $adjustment->occurred_at?->toDateString() ?? now()->toDateString(),
                'narration' => 'Broker adjustment '.$type->value.' ('.$hire->hire_number.')',
                'branch_id' => $branch->id,
                'source_type' => BrokerAdjustment::class,
                'source_id' => $adjustment->id,
                'posting_purpose' => 'broker_adjustment',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowControlled,
        );
    }

    /**
     * Debit for a positive signed amount, credit for a negative one; null when zero.
     *
     * @param  numeric-string  $signedDebit
     * @return array{gl_ledger_id: string, debit_amount: string, credit_amount: string, narration: string}|null
     */
    private function signedJournalLine(string $ledgerId, string $signedDebit, string $zero, string $narration): ?array
    {
        $comparison = bccomp($signedDebit, '0', 2);
        if ($comparison === 0) {
            return null;
        }
        $amount = $comparison > 0 ? $signedDebit : bcmul($signedDebit, '-1', 2);

        return [
            'gl_ledger_id' => $ledgerId,
            'debit_amount' => $comparison > 0 ? $amount : $zero,
            'credit_amount' => $comparison > 0 ? $zero : $amount,
            'narration' => $narration,
        ];
    }

    public function postTransporterVoucherPaid(TransporterVoucher $voucher, User $actor, bool $allowBypass = false): void
    {
        $company = Company::query()->findOrFail($voucher->company_id);
        $allowControlled = $allowBypass
            || (filled($voucher->lorry_hire_id) && $this->hasPostedLink($company, LorryHire::class, $voucher->lorry_hire_id, 'hire_recognized'));
        if (! $this->shouldAutoPost($company) && ! $allowControlled) {
            return;
        }

        $branch = Branch::query()->findOrFail($voucher->branch_id);
        $this->prepare($company);

        $amount = $this->money($voucher->amount);
        if (bccomp($amount, '0', 2) !== 1) {
            return;
        }

        $payable = $this->ledgers->requireByName($company, 'Transporter Payables');
        $advanceLedger = $this->ledgers->requireByName($company, 'Transporter Advances');
        $mode = $voucher->payment_mode instanceof VoucherPaymentMode
            ? $voucher->payment_mode
            : VoucherPaymentMode::tryFrom((string) $voucher->payment_mode);
        $paymentType = $voucher->payment_type;

        if ($mode === VoucherPaymentMode::AdjustInAdvance) {
            $debitLedger = $payable;
            $debitNarration = 'Settle transporter payable via advance '.$voucher->voucher_number;
            $creditLedger = $advanceLedger;
            $creditNarration = 'Adjust in advance '.$voucher->voucher_number;
        } elseif ($paymentType === VoucherPaymentType::Advance) {
            $debitLedger = $advanceLedger;
            $debitNarration = 'Transporter advance '.$voucher->voucher_number;
            $creditLedger = $this->resolveCashOrBank(
                $company,
                $voucher->bank_cash_account_id,
                $mode !== null ? $mode->value : 'cash',
            );
            $creditNarration = 'Transporter advance payment '.$voucher->voucher_number;
        } else {
            $debitLedger = $payable;
            $debitNarration = 'Settle transporter payable '.$voucher->voucher_number;
            $creditLedger = $this->resolveCashOrBank(
                $company,
                $voucher->bank_cash_account_id,
                $mode !== null ? $mode->value : 'cash',
            );
            $creditNarration = 'Transporter payment '.$voucher->voucher_number;
        }

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => $mode === VoucherPaymentMode::AdjustInAdvance
                    ? AccountingVoucherType::Journal
                    : AccountingVoucherType::Payment,
                'voucher_date' => $voucher->voucher_date?->toDateString() ?? now()->toDateString(),
                'narration' => 'Transporter voucher '.$voucher->voucher_number,
                'branch_id' => $branch->id,
                'source_type' => TransporterVoucher::class,
                'source_id' => $voucher->id,
                'posting_purpose' => 'transporter_voucher_paid',
            ],
            lines: [
                ['gl_ledger_id' => $debitLedger->id, 'debit_amount' => $amount, 'credit_amount' => $this->zeroAmount(), 'narration' => $debitNarration],
                ['gl_ledger_id' => $creditLedger->id, 'debit_amount' => $this->zeroAmount(), 'credit_amount' => $amount, 'narration' => $creditNarration],
            ],
            branch: $branch,
            allowControlledCorrection: $allowControlled,
        );
    }

    public function postTripInvoiceIssued(TripInvoice $invoice, User $actor, bool $allowBypass = false): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        if (! $allowBypass && ! $this->shouldAutoPost($company)) {
            return;
        }

        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->prepare($company);

        $receivable = $this->ledgers->requireByName($company, 'Customer Receivables');
        $freightIncome = $this->ledgers->requireByName($company, 'Freight Income');

        $taxable = $this->money($invoice->taxable_total);
        $gst = $this->money($invoice->gst_amount);
        $total = $this->money($invoice->invoice_total);
        if (bccomp($total, '0', 2) !== 1) {
            return;
        }

        $zero = $this->zeroAmount();
        $lines = [
            ['gl_ledger_id' => $receivable->id, 'debit_amount' => $total, 'credit_amount' => $zero, 'narration' => 'OwnFleet invoice '.$invoice->invoice_number],
            ['gl_ledger_id' => $freightIncome->id, 'debit_amount' => $zero, 'credit_amount' => $taxable, 'narration' => 'OwnFleet freight revenue'],
            ...$this->buildOutputGstCreditLines($company, $invoice->gst_rate_snapshot ?? [], $gst),
        ];

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Sales,
                'voucher_date' => $invoice->invoice_date->toDateString(),
                'narration' => 'OwnFleet invoice '.$invoice->invoice_number,
                'branch_id' => $branch->id,
                'source_type' => TripInvoice::class,
                'source_id' => $invoice->id,
                'posting_purpose' => 'trip_invoice_issued',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowBypass,
        );
    }

    public function postTripInvoiceCancelled(TripInvoice $invoice, User $actor, string $remark): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $this->reverseBySource(
            $company,
            TripInvoice::class,
            $invoice->id,
            'trip_invoice_issued',
            $actor,
            'Cancel OwnFleet invoice '.$invoice->invoice_number.': '.$remark,
        );
    }

    public function postTripInvoicePayment(
        TripInvoicePayment $payment,
        TripInvoice $invoice,
        User $actor,
        bool $allowBypass = false,
    ): void {
        $company = Company::query()->findOrFail($invoice->company_id);
        $allowControlled = $allowBypass || $this->hasPostedLink($company, TripInvoice::class, $invoice->id, 'trip_invoice_issued');
        if (! $this->shouldAutoPost($company) && ! $allowControlled) {
            return;
        }

        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->prepare($company);
        $payment->loadMissing('paymentMode');

        $receivable = $this->ledgers->requireByName($company, 'Customer Receivables');
        $modeName = strtolower((string) ($payment->paymentMode !== null ? $payment->paymentMode->name : ''));
        $cashOrBank = $this->resolveCashOrBank($company, null, $modeName);
        $amount = $this->money($payment->amount_received);
        $tds = $this->money($payment->tds_amount);
        $deduction = bcadd($this->money($payment->shortage_damage), $this->money($payment->discount), 2);

        $zero = $this->zeroAmount();
        $lines = [];
        if (bccomp($amount, '0', 2) === 1) {
            $lines[] = ['gl_ledger_id' => $cashOrBank->id, 'debit_amount' => $amount, 'credit_amount' => $zero, 'narration' => 'Receipt '.$invoice->invoice_number];
        }
        if (bccomp($tds, '0', 2) === 1) {
            $tdsLedger = $this->ledgers->requireByName($company, 'TDS Receivable');
            $lines[] = ['gl_ledger_id' => $tdsLedger->id, 'debit_amount' => $tds, 'credit_amount' => $zero, 'narration' => 'Customer TDS '.$invoice->invoice_number];
        }
        if (bccomp($deduction, '0', 2) === 1) {
            $freightIncome = $this->ledgers->requireByName($company, 'Freight Income');
            $lines[] = ['gl_ledger_id' => $freightIncome->id, 'debit_amount' => $deduction, 'credit_amount' => $zero, 'narration' => 'Shortage / discount '.$invoice->invoice_number];
        }

        $credit = bcadd($amount, bcadd($tds, $deduction, 2), 2);
        if (bccomp($credit, '0', 2) !== 1) {
            return;
        }
        $lines[] = ['gl_ledger_id' => $receivable->id, 'debit_amount' => $zero, 'credit_amount' => $credit, 'narration' => 'Settle invoice '.$invoice->invoice_number];

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Receipt,
                'voucher_date' => $payment->payment_date->toDateString(),
                'narration' => 'OwnFleet receipt for '.$invoice->invoice_number,
                'branch_id' => $branch->id,
                'source_type' => TripInvoicePayment::class,
                'source_id' => $payment->id,
                'posting_purpose' => 'trip_invoice_payment',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowControlled,
        );
    }

    public function postTripExpense(TripExpense $expense, User $actor, bool $allowBypass = false): void
    {
        $company = Company::query()->findOrFail($expense->company_id);
        if (! $allowBypass && ! $this->shouldAutoPost($company)) {
            return;
        }

        $expense->loadMissing(['trip', 'paymentMode', 'category']);
        $branchId = $expense->trip?->branch_id;
        if ($branchId === null) {
            return;
        }
        $branch = Branch::query()->findOrFail($branchId);
        $this->prepare($company);

        $amount = $this->money($expense->amount);
        if (bccomp($amount, '0', 2) !== 1) {
            return;
        }

        $expenseLedger = $this->mapTripExpenseLedger($company, $expense);
        if ((bool) $expense->is_credit_payable) {
            $creditLedger = $this->ledgers->requireByName($company, 'Vendor Payables');
            $voucherType = AccountingVoucherType::Purchase;
            $creditNarration = 'Vendor credit payable';
        } else {
            $modeName = strtolower((string) ($expense->paymentMode !== null ? $expense->paymentMode->name : ''));
            $creditLedger = $this->resolveCashOrBank(
                $company,
                $expense->paid_from_bank_cash_account_id,
                $modeName !== '' ? $modeName : 'cash',
            );
            $voucherType = AccountingVoucherType::Payment;
            $creditNarration = 'Trip expense payment';
        }

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => $voucherType,
                'voucher_date' => $expense->expense_date->toDateString(),
                'narration' => 'Trip expense '.$expense->id,
                'branch_id' => $branch->id,
                'source_type' => TripExpense::class,
                'source_id' => $expense->id,
                'posting_purpose' => 'trip_expense',
            ],
            lines: [
                ['gl_ledger_id' => $expenseLedger->id, 'debit_amount' => $amount, 'credit_amount' => $this->zeroAmount(), 'narration' => 'OwnFleet trip expense'],
                ['gl_ledger_id' => $creditLedger->id, 'debit_amount' => $this->zeroAmount(), 'credit_amount' => $amount, 'narration' => $creditNarration],
            ],
            branch: $branch,
            allowControlledCorrection: $allowBypass,
        );
    }

    public function postTripExpenseDeleted(TripExpense $expense, User $actor): void
    {
        $company = Company::query()->findOrFail($expense->company_id);
        $this->reverseBySource(
            $company,
            TripExpense::class,
            $expense->id,
            'trip_expense',
            $actor,
            'Reverse deleted Trip expense '.$expense->id,
        );
    }

    public function postVendorExpensePayment(VendorExpensePayment $payment, User $actor, bool $allowBypass = false): void
    {
        $company = Company::query()->findOrFail($payment->company_id);
        $allowControlled = $allowBypass
            || ($payment->trip_expense_id !== null && $this->hasPostedLink($company, TripExpense::class, $payment->trip_expense_id, 'trip_expense'));
        if (! $this->shouldAutoPost($company) && ! $allowControlled) {
            return;
        }

        $branch = Branch::query()->findOrFail($payment->branch_id);
        $this->prepare($company);

        $amount = $this->money($payment->amount);
        if (bccomp($amount, '0', 2) !== 1) {
            return;
        }

        $payable = $this->ledgers->requireByName($company, 'Vendor Payables');
        $cashOrBank = $this->resolveCashOrBank($company, $payment->bank_cash_account_id, $payment->payment_mode);

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Payment,
                'voucher_date' => $payment->payment_date->toDateString(),
                'narration' => 'Vendor payable settlement '.$payment->id,
                'branch_id' => $branch->id,
                'source_type' => VendorExpensePayment::class,
                'source_id' => $payment->id,
                'posting_purpose' => 'vendor_expense_payment',
            ],
            lines: [
                ['gl_ledger_id' => $payable->id, 'debit_amount' => $amount, 'credit_amount' => $this->zeroAmount(), 'narration' => 'Settle vendor payable'],
                ['gl_ledger_id' => $cashOrBank->id, 'debit_amount' => $this->zeroAmount(), 'credit_amount' => $amount, 'narration' => 'Vendor payment'],
            ],
            branch: $branch,
            allowControlledCorrection: $allowControlled,
        );
    }

    public function postDriverAdvanceEntry(DriverAdvanceEntry $entry, User $actor, bool $allowBypass = false): void
    {
        $company = Company::query()->findOrFail($entry->company_id);
        if (! $allowBypass && ! $this->shouldAutoPost($company)) {
            return;
        }

        $branch = Branch::query()->findOrFail($entry->branch_id);
        $this->prepare($company);

        $amount = $this->money($entry->amount);
        if (bccomp($amount, '0', 2) !== 1) {
            return;
        }

        $driverAdvances = $this->ledgers->requireByName($company, 'Driver Advances');
        $zero = $this->zeroAmount();

        if ($entry->entry_type === 'advance') {
            $creditLedger = $this->resolveCashOrBank($company, $entry->bank_cash_account_id, $entry->payment_mode);
            $voucherType = AccountingVoucherType::Payment;
            $lines = [
                ['gl_ledger_id' => $driverAdvances->id, 'debit_amount' => $amount, 'credit_amount' => $zero, 'narration' => 'Driver advance issued'],
                ['gl_ledger_id' => $creditLedger->id, 'debit_amount' => $zero, 'credit_amount' => $amount, 'narration' => 'Driver advance paid'],
            ];
        } elseif ($entry->entry_type === 'recovery') {
            $debitLedger = $this->resolveCashOrBank($company, $entry->bank_cash_account_id, $entry->payment_mode);
            $voucherType = AccountingVoucherType::Receipt;
            $lines = [
                ['gl_ledger_id' => $debitLedger->id, 'debit_amount' => $amount, 'credit_amount' => $zero, 'narration' => 'Driver advance recovered'],
                ['gl_ledger_id' => $driverAdvances->id, 'debit_amount' => $zero, 'credit_amount' => $amount, 'narration' => 'Driver advance recovery'],
            ];
        } else {
            $driverExpenses = $this->ledgers->requireByName($company, 'Driver Expenses');
            $voucherType = AccountingVoucherType::Journal;
            $lines = [
                ['gl_ledger_id' => $driverExpenses->id, 'debit_amount' => $amount, 'credit_amount' => $zero, 'narration' => 'Driver trip allowance/expense settled'],
                ['gl_ledger_id' => $driverAdvances->id, 'debit_amount' => $zero, 'credit_amount' => $amount, 'narration' => 'Adjust against driver advance'],
            ];
        }

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => $voucherType,
                'voucher_date' => $entry->entry_date->toDateString(),
                'narration' => 'Driver '.$entry->entry_type.' '.$entry->id,
                'branch_id' => $branch->id,
                'source_type' => DriverAdvanceEntry::class,
                'source_id' => $entry->id,
                'posting_purpose' => 'driver_advance_'.$entry->entry_type,
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowBypass,
        );
    }

    public function postPartloadPaidBooking(LorryReceipt $lr, User $actor, bool $allowBypass = false): void
    {
        if ($lr->workflow_context !== LrWorkflowContext::Partload
            || $lr->freight_payment_term !== PartloadFreightPaymentTerm::Paid) {
            return;
        }

        $company = Company::query()->findOrFail($lr->company_id);
        if (! $allowBypass && ! $this->shouldAutoPost($company)) {
            return;
        }

        $branch = Branch::query()->findOrFail($lr->branch_id);
        $this->prepare($company);

        $total = bcadd($this->money($lr->freight_total), $this->money($lr->additional_charges_total), 2);
        if (bccomp($total, '0', 2) !== 1) {
            return;
        }

        $cash = $this->ledgers->requireByName($company, 'Cash');
        $freightIncome = $this->ledgers->requireByName($company, 'Freight Income');

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Receipt,
                'voucher_date' => $lr->lr_date?->toDateString() ?? now()->toDateString(),
                'narration' => 'Partload Paid Bilty '.$lr->lr_number,
                'branch_id' => $branch->id,
                'source_type' => LorryReceipt::class,
                'source_id' => $lr->id,
                'posting_purpose' => 'partload_paid_booking',
            ],
            lines: [
                ['gl_ledger_id' => $cash->id, 'debit_amount' => $total, 'credit_amount' => $this->zeroAmount(), 'narration' => 'Paid booking receipt '.$lr->lr_number],
                ['gl_ledger_id' => $freightIncome->id, 'debit_amount' => $this->zeroAmount(), 'credit_amount' => $total, 'narration' => 'Partload paid freight '.$lr->lr_number],
            ],
            branch: $branch,
            allowControlledCorrection: $allowBypass,
        );
    }

    public function postPartloadPaidBookingCancelled(LorryReceipt $lr, User $actor, string $remark): void
    {
        $company = Company::query()->findOrFail($lr->company_id);
        $this->reverseBySource(
            $company,
            LorryReceipt::class,
            $lr->id,
            'partload_paid_booking',
            $actor,
            'Cancel Paid Partload LR '.$lr->lr_number.': '.$remark,
        );
    }

    public function postPartloadDeliveryReceipt(PartloadDeliveryReceipt $receipt, User $actor, bool $allowBypass = false): void
    {
        $company = Company::query()->findOrFail($receipt->company_id);
        if (! $allowBypass && ! $this->shouldAutoPost($company)) {
            return;
        }

        $branch = Branch::query()->findOrFail($receipt->branch_id);
        $this->prepare($company);

        $final = $this->money($receipt->final_amount);
        if (bccomp($final, '0', 2) !== 1) {
            return;
        }

        $gst = $this->money($receipt->gst_amount);
        $taxable = bccomp($final, $gst, 2) === 1 ? bcsub($final, $gst, 2) : $final;
        $zero = $this->zeroAmount();
        $freightIncome = $this->ledgers->requireByName($company, 'Freight Income');

        $mode = $receipt->payment_mode;

        if ($mode === PartloadDeliveryPaymentMode::Due) {
            $debitLedger = $this->ledgers->requireByName($company, 'Customer Receivables');
            $debitNarration = 'Partload DR due '.$receipt->dr_number;
            $voucherType = AccountingVoucherType::Sales;
        } else {
            $debitLedger = $this->resolveCashOrBank(
                $company,
                $receipt->bank_cash_account_id,
                $mode->value,
            );
            $debitNarration = 'Partload DR collection '.$receipt->dr_number;
            $voucherType = AccountingVoucherType::Receipt;
        }

        $lines = [
            ['gl_ledger_id' => $debitLedger->id, 'debit_amount' => $final, 'credit_amount' => $zero, 'narration' => $debitNarration],
            ['gl_ledger_id' => $freightIncome->id, 'debit_amount' => $zero, 'credit_amount' => $taxable, 'narration' => 'Partload freight income'],
            ...$this->splitOutputGstCredits($company, $gst),
        ];

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => $voucherType,
                'voucher_date' => $receipt->dr_date?->toDateString() ?? now()->toDateString(),
                'narration' => 'Partload Delivery Receipt '.$receipt->dr_number,
                'branch_id' => $branch->id,
                'source_type' => PartloadDeliveryReceipt::class,
                'source_id' => $receipt->id,
                'posting_purpose' => 'partload_delivery_receipt',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowBypass,
        );
    }

    public function postPartloadChallanDispatched(PartloadChallan $challan, User $actor, bool $allowBypass = false): void
    {
        $company = Company::query()->findOrFail($challan->company_id);
        if (! $allowBypass && ! $this->shouldAutoPost($company)) {
            return;
        }

        $branch = Branch::query()->findOrFail($challan->branch_id);
        $this->prepare($company);

        $lines = $this->buildMovementExpenseLines(
            company: $company,
            ownership: $challan->ownership_mode,
            truckFreight: $this->money($challan->truck_freight),
            movementExtras: $this->money(
                bcadd(
                    bcadd($this->money($challan->labour_charge), $this->money($challan->kat), 2),
                    bcadd(
                        bcadd($this->money($challan->home_delivery_charge), $this->money($challan->crossing_freight), 2),
                        $this->money($challan->cartage_dalali_pf),
                        2,
                    ),
                    2,
                ),
            ),
            cashPayment: $this->money($challan->cash_payment),
            labelPrefix: 'Challan '.$challan->challan_number,
        );

        if ($lines === []) {
            return;
        }

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Payment,
                'voucher_date' => ($challan->dispatched_at !== null
                    ? $challan->dispatched_at->toDateString()
                    : ($challan->challan_date !== null
                        ? $challan->challan_date->toDateString()
                        : now()->toDateString())),
                'narration' => 'Partload Challan dispatch '.$challan->challan_number,
                'branch_id' => $branch->id,
                'source_type' => PartloadChallan::class,
                'source_id' => $challan->id,
                'posting_purpose' => 'partload_challan_dispatched',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowBypass,
        );
    }

    public function postPartloadChallanCancelled(PartloadChallan $challan, User $actor, string $remark): void
    {
        $company = Company::query()->findOrFail($challan->company_id);
        $this->reverseBySource(
            $company,
            PartloadChallan::class,
            $challan->id,
            'partload_challan_dispatched',
            $actor,
            'Cancel Partload Challan '.$challan->challan_number.': '.$remark,
        );
    }

    public function postPartloadMainChallanDispatched(PartloadMainChallan $main, User $actor, bool $allowBypass = false): void
    {
        $company = Company::query()->findOrFail($main->company_id);
        if (! $allowBypass && ! $this->shouldAutoPost($company)) {
            return;
        }

        $branch = Branch::query()->findOrFail($main->branch_id);
        $this->prepare($company);

        $lines = $this->buildMovementExpenseLines(
            company: $company,
            ownership: $main->ownership_mode,
            truckFreight: $this->money($main->truck_freight),
            movementExtras: $this->money(
                bcadd(
                    bcadd($this->money($main->labour_charge), $this->money($main->kat), 2),
                    bcadd(
                        bcadd($this->money($main->home_delivery_charge), $this->money($main->other_charge), 2),
                        bcadd($this->money($main->crossing_freight), $this->money($main->cartage_dalali_pf), 2),
                        2,
                    ),
                    2,
                ),
            ),
            cashPayment: $this->money($main->cash_paid),
            labelPrefix: 'Main Challan '.$main->main_challan_number,
        );

        if ($lines === []) {
            return;
        }

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Payment,
                'voucher_date' => ($main->dispatched_at !== null
                    ? $main->dispatched_at->toDateString()
                    : ($main->main_challan_date !== null
                        ? $main->main_challan_date->toDateString()
                        : now()->toDateString())),
                'narration' => 'Partload Main Challan dispatch '.$main->main_challan_number,
                'branch_id' => $branch->id,
                'source_type' => PartloadMainChallan::class,
                'source_id' => $main->id,
                'posting_purpose' => 'partload_main_challan_dispatched',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowBypass,
        );
    }

    public function postPartloadInvoiceIssued(PartloadInvoice $invoice, User $actor, bool $allowBypass = false): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        if (! $allowBypass && ! $this->shouldAutoPost($company)) {
            return;
        }

        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->prepare($company);

        $invoice->loadMissing('lines.deliveryReceipt');

        // Prevent double revenue recognition for Due Delivery Receipts / Paid LRs that already booked GL.
        $alreadyRecognizedReceivable = '0.00';
        foreach ($invoice->lines as $line) {
            if ($line->partload_delivery_receipt_id !== null
                && $this->hasPostedLink($company, PartloadDeliveryReceipt::class, $line->partload_delivery_receipt_id, 'partload_delivery_receipt')) {
                $dr = $line->deliveryReceipt;
                if ($dr !== null && $dr->payment_mode === PartloadDeliveryPaymentMode::Due) {
                    $alreadyRecognizedReceivable = bcadd($alreadyRecognizedReceivable, $this->money($dr->final_amount), 2);
                }
            } elseif ($line->lorry_receipt_id !== null
                && $this->hasPostedLink($company, LorryReceipt::class, $line->lorry_receipt_id, 'partload_paid_booking')) {
                $alreadyRecognizedReceivable = bcadd($alreadyRecognizedReceivable, $this->money($line->line_total), 2);
            }
        }

        $total = $this->money($invoice->invoice_total);
        $incrementalTotal = bcsub($total, $alreadyRecognizedReceivable, 2);
        if (bccomp($incrementalTotal, '0', 2) !== 1) {
            return;
        }

        $gst = $this->money($invoice->gst_amount);
        $taxable = bcsub($incrementalTotal, $gst, 2);
        if (bccomp($taxable, '0', 2) < 0) {
            $taxable = $incrementalTotal;
            $gst = '0.00';
        }

        $receivable = $this->ledgers->requireByName($company, 'Customer Receivables');
        $freightIncome = $this->ledgers->requireByName($company, 'Freight Income');
        $zero = $this->zeroAmount();

        $lines = [
            ['gl_ledger_id' => $receivable->id, 'debit_amount' => $incrementalTotal, 'credit_amount' => $zero, 'narration' => 'Partload invoice '.$invoice->invoice_number],
        ];
        if (bccomp($taxable, '0', 2) === 1) {
            $lines[] = ['gl_ledger_id' => $freightIncome->id, 'debit_amount' => $zero, 'credit_amount' => $taxable, 'narration' => 'Partload freight billing'];
        }
        $lines = [...$lines, ...$this->buildOutputGstCreditLines($company, $invoice->gst_rate_snapshot ?? [], $gst)];

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Sales,
                'voucher_date' => $invoice->invoice_date->toDateString(),
                'narration' => 'Partload invoice '.$invoice->invoice_number,
                'branch_id' => $branch->id,
                'source_type' => PartloadInvoice::class,
                'source_id' => $invoice->id,
                'posting_purpose' => 'partload_invoice_issued',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowBypass,
        );
    }

    public function postPartloadInvoiceCancelled(PartloadInvoice $invoice, User $actor, string $remark): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $this->reverseBySource(
            $company,
            PartloadInvoice::class,
            $invoice->id,
            'partload_invoice_issued',
            $actor,
            'Cancel Partload invoice '.$invoice->invoice_number.': '.$remark,
        );
    }

    public function postPartloadInvoicePayment(
        PartloadInvoicePayment $payment,
        PartloadInvoice $invoice,
        User $actor,
        bool $allowBypass = false,
    ): void {
        $company = Company::query()->findOrFail($invoice->company_id);
        $allowControlled = $allowBypass || $this->hasPostedLink($company, PartloadInvoice::class, $invoice->id, 'partload_invoice_issued');
        if (! $this->shouldAutoPost($company) && ! $allowControlled) {
            return;
        }

        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->prepare($company);

        $receivable = $this->ledgers->requireByName($company, 'Customer Receivables');
        $cashOrBank = $this->resolveCashOrBank($company, $payment->bank_cash_account_id, $payment->payment_mode);
        $amount = $this->money($payment->amount_received);
        $tds = $this->money($payment->tds_amount);
        $deduction = $this->money($payment->deduction_amount);

        $zero = $this->zeroAmount();
        $lines = [];
        if (bccomp($amount, '0', 2) === 1) {
            $lines[] = ['gl_ledger_id' => $cashOrBank->id, 'debit_amount' => $amount, 'credit_amount' => $zero, 'narration' => 'Partload receipt '.$invoice->invoice_number];
        }
        if (bccomp($tds, '0', 2) === 1) {
            $tdsLedger = $this->ledgers->requireByName($company, 'TDS Receivable');
            $lines[] = ['gl_ledger_id' => $tdsLedger->id, 'debit_amount' => $tds, 'credit_amount' => $zero, 'narration' => 'Customer TDS '.$invoice->invoice_number];
        }
        if (bccomp($deduction, '0', 2) === 1) {
            $freightIncome = $this->ledgers->requireByName($company, 'Freight Income');
            $lines[] = ['gl_ledger_id' => $freightIncome->id, 'debit_amount' => $deduction, 'credit_amount' => $zero, 'narration' => 'Invoice deduction '.$invoice->invoice_number];
        }

        $credit = bcadd($amount, bcadd($tds, $deduction, 2), 2);
        if (bccomp($credit, '0', 2) !== 1) {
            return;
        }
        $lines[] = ['gl_ledger_id' => $receivable->id, 'debit_amount' => $zero, 'credit_amount' => $credit, 'narration' => 'Settle Partload invoice '.$invoice->invoice_number];

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Receipt,
                'voucher_date' => $payment->payment_date->toDateString(),
                'narration' => 'Partload receipt for '.$invoice->invoice_number,
                'branch_id' => $branch->id,
                'source_type' => PartloadInvoicePayment::class,
                'source_id' => $payment->id,
                'posting_purpose' => 'partload_invoice_payment',
            ],
            lines: $lines,
            branch: $branch,
            allowControlledCorrection: $allowControlled,
        );
    }

    /**
     * Scoped Customer-side subscription software expense posting (§31).
     * Never records platform billing into Customer transport revenue ledgers.
     */
    public function postSubscriptionExpenseForCustomer(
        SubscriptionInvoice $invoice,
        Company $company,
        User $actor,
    ): ?AccountingVoucher {
        if (! $this->shouldAutoPost($company)) {
            return null;
        }

        $this->prepare($company);
        $taxable = $this->money($invoice->taxable_amount ?: $invoice->amount);
        $cgst = $this->money($invoice->cgst_amount ?? 0);
        $sgst = $this->money($invoice->sgst_amount ?? 0);
        $igst = $this->money($invoice->igst_amount ?? 0);
        $grand = $this->money($invoice->amount);
        if (bccomp($grand, '0', 2) !== 1) {
            return null;
        }

        $expense = $this->ledgers->requireByName($company, 'Software Subscription Expense');
        $bank = $this->ledgers->requireByName($company, 'Bank');
        $zero = $this->zeroAmount();

        $lines = [
            ['gl_ledger_id' => $expense->id, 'debit_amount' => $taxable, 'credit_amount' => $zero, 'narration' => 'TruckBill subscription '.$invoice->invoice_number],
        ];
        if (bccomp($cgst, '0', 2) === 1) {
            $lines[] = ['gl_ledger_id' => $this->ledgers->requireByName($company, 'Input CGST')->id, 'debit_amount' => $cgst, 'credit_amount' => $zero, 'narration' => 'Input CGST'];
        }
        if (bccomp($sgst, '0', 2) === 1) {
            $lines[] = ['gl_ledger_id' => $this->ledgers->requireByName($company, 'Input SGST')->id, 'debit_amount' => $sgst, 'credit_amount' => $zero, 'narration' => 'Input SGST'];
        }
        if (bccomp($igst, '0', 2) === 1) {
            $lines[] = ['gl_ledger_id' => $this->ledgers->requireByName($company, 'Input IGST')->id, 'debit_amount' => $igst, 'credit_amount' => $zero, 'narration' => 'Input IGST'];
        }
        $lines[] = ['gl_ledger_id' => $bank->id, 'debit_amount' => $zero, 'credit_amount' => $grand, 'narration' => 'Platform subscription payment '.$invoice->invoice_number];

        return $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Payment,
                'voucher_date' => ($invoice->issued_at?->toDateString() ?? now()->toDateString()),
                'narration' => 'TruckBill Subscription Invoice '.$invoice->invoice_number,
                'branch_id' => null,
                'source_type' => SubscriptionInvoice::class,
                'source_id' => $invoice->id,
                'posting_purpose' => 'customer_subscription_expense',
            ],
            lines: $lines,
        );
    }

    private function reverseBySource(
        Company $company,
        string $sourceType,
        string $sourceId,
        string $purpose,
        User $actor,
        string $remark,
    ): void {
        $link = AccountingPostingLink::query()
            ->where('company_id', $company->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('posting_purpose', $purpose)
            ->where('status', AccountingPostingStatus::Posted->value)
            ->first();

        if ($link?->accounting_voucher_id === null) {
            return;
        }

        $voucher = AccountingVoucher::query()->find($link->accounting_voucher_id);
        if ($voucher === null || $voucher->status->value === 'reversed') {
            return;
        }

        $this->posting->reverse($voucher, $actor, $remark, allowControlledCorrection: true);
        $link->forceFill(['status' => AccountingPostingStatus::Reversed])->save();
    }

    /**
     * @param  numeric-string  $truckFreight
     * @param  numeric-string  $movementExtras
     * @param  numeric-string  $cashPayment
     * @return list<array{gl_ledger_id: string, debit_amount: numeric-string, credit_amount: numeric-string, narration: string}>
     */
    private function buildMovementExpenseLines(
        Company $company,
        VehicleOwnership $ownership,
        string $truckFreight,
        string $movementExtras,
        string $cashPayment,
        string $labelPrefix,
    ): array {
        $zero = $this->zeroAmount();
        $lines = [];

        if (bccomp($truckFreight, '0', 2) === 1) {
            if ($ownership === VehicleOwnership::Market) {
                $expense = $this->ledgers->requireByName($company, 'Transporter Freight Expense');
                $credit = $this->ledgers->requireByName($company, 'Transporter Payables');
            } else {
                $expense = $this->ledgers->requireByName($company, 'Vehicle Expenses');
                $credit = $this->ledgers->requireByName($company, 'Cash');
            }
            $lines[] = ['gl_ledger_id' => $expense->id, 'debit_amount' => $truckFreight, 'credit_amount' => $zero, 'narration' => $labelPrefix.' truck freight'];
            $lines[] = ['gl_ledger_id' => $credit->id, 'debit_amount' => $zero, 'credit_amount' => $truckFreight, 'narration' => $labelPrefix.' truck freight'];
        }

        if (bccomp($movementExtras, '0', 2) === 1) {
            $loading = $this->ledgers->requireByName($company, 'Loading and Unloading Expenses');
            $cash = $this->ledgers->requireByName($company, 'Cash');
            $lines[] = ['gl_ledger_id' => $loading->id, 'debit_amount' => $movementExtras, 'credit_amount' => $zero, 'narration' => $labelPrefix.' movement charges'];
            $lines[] = ['gl_ledger_id' => $cash->id, 'debit_amount' => $zero, 'credit_amount' => $movementExtras, 'narration' => $labelPrefix.' movement charges'];
        }

        if (bccomp($cashPayment, '0', 2) === 1 && bccomp($truckFreight, '0', 2) !== 1 && bccomp($movementExtras, '0', 2) !== 1) {
            $vehicleExpense = $this->ledgers->requireByName($company, 'Vehicle Expenses');
            $cash = $this->ledgers->requireByName($company, 'Cash');
            $lines[] = ['gl_ledger_id' => $vehicleExpense->id, 'debit_amount' => $cashPayment, 'credit_amount' => $zero, 'narration' => $labelPrefix.' cash payment'];
            $lines[] = ['gl_ledger_id' => $cash->id, 'debit_amount' => $zero, 'credit_amount' => $cashPayment, 'narration' => $labelPrefix.' cash payment'];
        }

        return $lines;
    }

    private function prepare(Company $company): void
    {
        $this->coa->ensureDefaults($company);
        $this->periods->ensureCurrentFinancialYear($company);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{gl_ledger_id: string, debit_amount: numeric-string, credit_amount: numeric-string, narration: string}>
     */
    private function buildOutputGstCreditLines(Company $company, array $snapshot, string $gstAmount): array
    {
        $gstAmount = $this->money($gstAmount);
        if (bccomp($gstAmount, '0', 2) !== 1) {
            return [];
        }

        $igst = $this->money($snapshot['igst_amount'] ?? 0);
        $cgst = $this->money($snapshot['cgst_amount'] ?? 0);
        $sgst = $this->money($snapshot['sgst_amount'] ?? 0);

        $zero = $this->zeroAmount();

        if (bccomp($igst, '0', 2) === 1) {
            return [[
                'gl_ledger_id' => $this->ledgers->requireByName($company, 'Output IGST')->id,
                'debit_amount' => $zero,
                'credit_amount' => $igst,
                'narration' => 'Output IGST',
            ]];
        }

        if (bccomp($cgst, '0', 2) === 1 || bccomp($sgst, '0', 2) === 1) {
            $lines = [];
            if (bccomp($cgst, '0', 2) === 1) {
                $lines[] = [
                    'gl_ledger_id' => $this->ledgers->requireByName($company, 'Output CGST')->id,
                    'debit_amount' => $zero,
                    'credit_amount' => $cgst,
                    'narration' => 'Output CGST',
                ];
            }
            if (bccomp($sgst, '0', 2) === 1) {
                $lines[] = [
                    'gl_ledger_id' => $this->ledgers->requireByName($company, 'Output SGST')->id,
                    'debit_amount' => $zero,
                    'credit_amount' => $sgst,
                    'narration' => 'Output SGST',
                ];
            }

            return $lines;
        }

        return $this->splitOutputGstCredits($company, $gstAmount);
    }

    /**
     * @return list<array{gl_ledger_id: string, debit_amount: numeric-string, credit_amount: numeric-string, narration: string}>
     */
    private function splitOutputGstCredits(Company $company, string $gstAmount): array
    {
        $gstAmount = $this->money($gstAmount);
        if (bccomp($gstAmount, '0', 2) !== 1) {
            return [];
        }

        $zero = $this->zeroAmount();
        $half = bcdiv($gstAmount, '2', 2);
        $remainder = bcsub($gstAmount, $half, 2);

        return [
            [
                'gl_ledger_id' => $this->ledgers->requireByName($company, 'Output CGST')->id,
                'debit_amount' => $zero,
                'credit_amount' => $half,
                'narration' => 'Output CGST',
            ],
            [
                'gl_ledger_id' => $this->ledgers->requireByName($company, 'Output SGST')->id,
                'debit_amount' => $zero,
                'credit_amount' => $remainder,
                'narration' => 'Output SGST',
            ],
        ];
    }

    private function resolveCashOrBank(Company $company, mixed $bankCashAccountId, mixed $paymentMode): GlLedger
    {
        $mode = strtolower(trim((string) ($paymentMode ?? '')));
        if ($mode === 'cash' || (! filled($bankCashAccountId) && $mode === '')) {
            return $this->ledgers->requireByName($company, 'Cash');
        }

        return $this->ledgers->requireByName($company, 'Bank');
    }

    private function mapTripExpenseLedger(Company $company, TripExpense $expense): GlLedger
    {
        $expense->loadMissing('category');
        $category = strtolower((string) ($expense->category !== null ? $expense->category->name : ''));

        $name = match (true) {
            str_contains($category, 'diesel') || str_contains($category, 'fuel') => 'Diesel Expenses',
            str_contains($category, 'toll') || str_contains($category, 'fastag') => 'Toll and FASTag Expenses',
            str_contains($category, 'driver') => 'Driver Expenses',
            str_contains($category, 'load') || str_contains($category, 'unload') => 'Loading and Unloading Expenses',
            default => 'Vehicle Expenses',
        };

        return $this->ledgers->requireByName($company, $name);
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /**
     * @return numeric-string
     */
    private function zeroAmount(): string
    {
        return '0.00';
    }
}
