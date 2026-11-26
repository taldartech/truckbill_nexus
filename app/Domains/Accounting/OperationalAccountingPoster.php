<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
use App\Models\Branch;
use App\Models\BrokerInvoice;
use App\Models\BrokerInvoicePayment;
use App\Models\Company;
use App\Models\GlLedger;
use App\Models\LorryHire;
use App\Models\PartloadChallan;
use App\Models\PartloadDeliveryReceipt;
use App\Models\TransporterVoucher;
use App\Models\TripExpense;
use App\Models\User;

/**
 * Translates operational financial events into the shared accounting engine (ADR-010).
 */
final class OperationalAccountingPoster
{
    public function __construct(
        private readonly VoucherPostingService $posting = new VoucherPostingService,
        private readonly SystemLedgerResolver $ledgers = new SystemLedgerResolver,
        private readonly AccountingPeriodService $periods = new AccountingPeriodService,
        private readonly ChartOfAccountsBootstrapService $coa = new ChartOfAccountsBootstrapService,
    ) {}

    public function postBrokerInvoiceIssued(BrokerInvoice $invoice, User $actor): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->prepare($company);

        $receivable = $this->ledgers->requireByName($company, 'Customer Receivables');
        $freightIncome = $this->ledgers->requireByName($company, 'Freight Income');

        $taxable = $this->money($invoice->taxable_total);
        $gst = $this->money($invoice->gst_amount);
        $total = $this->money($invoice->invoice_total);

        $zero = $this->zeroAmount();
        $lines = [
            ['gl_ledger_id' => $receivable->id, 'debit_amount' => $total, 'credit_amount' => $zero, 'narration' => 'Customer invoice '.$invoice->invoice_number],
            ['gl_ledger_id' => $freightIncome->id, 'debit_amount' => $zero, 'credit_amount' => $taxable, 'narration' => 'Freight revenue'],
        ];

        $lines = [...$lines, ...$this->outputGstCreditLines($company, $invoice, $gst)];

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
        );
    }

    public function postBrokerInvoicePayment(BrokerInvoicePayment $payment, BrokerInvoice $invoice, User $actor): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);
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
        );
    }

    public function postLorryHireRecognized(LorryHire $hire, User $actor): void
    {
        $company = Company::query()->findOrFail($hire->company_id);
        $branch = Branch::query()->findOrFail($hire->branch_id);
        $this->prepare($company);

        $expense = $this->ledgers->requireByName($company, 'Transporter Freight Expense');
        $payable = $this->ledgers->requireByName($company, 'Transporter Payables');
        $freight = $this->money($hire->freight_total);
        if (bccomp($freight, '0', 2) !== 1) {
            return;
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
            lines: [
                ['gl_ledger_id' => $expense->id, 'debit_amount' => $freight, 'credit_amount' => $this->zeroAmount(), 'narration' => 'Transporter freight'],
                ['gl_ledger_id' => $payable->id, 'debit_amount' => $this->zeroAmount(), 'credit_amount' => $freight, 'narration' => 'Transporter payable'],
            ],
            branch: $branch,
        );
    }

    public function postTransporterVoucherPaid(TransporterVoucher $voucher, User $actor): void
    {
        $company = Company::query()->findOrFail($voucher->company_id);
        $branch = Branch::query()->findOrFail($voucher->branch_id);
        $this->prepare($company);

        $amount = $this->money($voucher->amount);
        if (bccomp($amount, '0', 2) !== 1) {
            return;
        }

        $payable = $this->ledgers->requireByName($company, 'Transporter Payables');
        $mode = $voucher->payment_mode instanceof VoucherPaymentMode
            ? $voucher->payment_mode
            : VoucherPaymentMode::tryFrom((string) $voucher->payment_mode);

        if ($mode === VoucherPaymentMode::AdjustInAdvance) {
            $advance = $this->ledgers->requireByName($company, 'Transporter Advances');
            $creditLedger = $advance;
            $creditNarration = 'Adjust in advance '.$voucher->voucher_number;
        } else {
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
                'voucher_type' => AccountingVoucherType::Payment,
                'voucher_date' => $voucher->voucher_date?->toDateString() ?? now()->toDateString(),
                'narration' => 'Transporter voucher '.$voucher->voucher_number,
                'branch_id' => $branch->id,
                'source_type' => TransporterVoucher::class,
                'source_id' => $voucher->id,
                'posting_purpose' => 'transporter_voucher_paid',
            ],
            lines: [
                ['gl_ledger_id' => $payable->id, 'debit_amount' => $amount, 'credit_amount' => $this->zeroAmount(), 'narration' => 'Settle transporter payable'],
                ['gl_ledger_id' => $creditLedger->id, 'debit_amount' => $this->zeroAmount(), 'credit_amount' => $amount, 'narration' => $creditNarration],
            ],
            branch: $branch,
        );
    }

    public function postTripExpense(TripExpense $expense, User $actor): void
    {
        $company = Company::query()->findOrFail($expense->company_id);
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
        $modeName = strtolower((string) ($expense->paymentMode !== null ? $expense->paymentMode->name : ''));
        $creditLedger = $this->resolveCashOrBank(
            $company,
            $expense->paid_from_bank_cash_account_id,
            $modeName !== '' ? $modeName : 'cash',
        );

        $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Payment,
                'voucher_date' => $expense->expense_date->toDateString(),
                'narration' => 'Trip expense '.$expense->id,
                'branch_id' => $branch->id,
                'source_type' => TripExpense::class,
                'source_id' => $expense->id,
                'posting_purpose' => 'trip_expense',
            ],
            lines: [
                ['gl_ledger_id' => $expenseLedger->id, 'debit_amount' => $amount, 'credit_amount' => $this->zeroAmount()],
                ['gl_ledger_id' => $creditLedger->id, 'debit_amount' => $this->zeroAmount(), 'credit_amount' => $amount],
            ],
            branch: $branch,
        );
    }

    public function postPartloadDeliveryReceipt(PartloadDeliveryReceipt $receipt, User $actor): void
    {
        $company = Company::query()->findOrFail($receipt->company_id);
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
        );
    }

    public function postPartloadChallanDispatched(PartloadChallan $challan, User $actor): void
    {
        $company = Company::query()->findOrFail($challan->company_id);
        $branch = Branch::query()->findOrFail($challan->branch_id);
        $this->prepare($company);

        $truckFreight = $this->money($challan->truck_freight);
        $movementExtras = $this->money(
            bcadd(
                bcadd($this->money($challan->labour_charge), $this->money($challan->kat), 2),
                bcadd(
                    bcadd($this->money($challan->home_delivery_charge), $this->money($challan->crossing_freight), 2),
                    $this->money($challan->cartage_dalali_pf),
                    2,
                ),
                2,
            ),
        );
        $cashPayment = $this->money($challan->cash_payment);

        $ownership = $challan->ownership_mode;
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
            $lines[] = ['gl_ledger_id' => $expense->id, 'debit_amount' => $truckFreight, 'credit_amount' => $zero, 'narration' => 'Challan truck freight'];
            $lines[] = ['gl_ledger_id' => $credit->id, 'debit_amount' => $zero, 'credit_amount' => $truckFreight, 'narration' => 'Challan truck freight'];
        }

        if (bccomp($movementExtras, '0', 2) === 1) {
            $loading = $this->ledgers->requireByName($company, 'Loading and Unloading Expenses');
            $cash = $this->ledgers->requireByName($company, 'Cash');
            $lines[] = ['gl_ledger_id' => $loading->id, 'debit_amount' => $movementExtras, 'credit_amount' => $zero, 'narration' => 'Challan movement charges'];
            $lines[] = ['gl_ledger_id' => $cash->id, 'debit_amount' => $zero, 'credit_amount' => $movementExtras, 'narration' => 'Challan movement charges'];
        }

        if (bccomp($cashPayment, '0', 2) === 1 && bccomp($truckFreight, '0', 2) !== 1 && bccomp($movementExtras, '0', 2) !== 1) {
            $vehicleExpense = $this->ledgers->requireByName($company, 'Vehicle Expenses');
            $cash = $this->ledgers->requireByName($company, 'Cash');
            $lines[] = ['gl_ledger_id' => $vehicleExpense->id, 'debit_amount' => $cashPayment, 'credit_amount' => $zero, 'narration' => 'Challan cash payment'];
            $lines[] = ['gl_ledger_id' => $cash->id, 'debit_amount' => $zero, 'credit_amount' => $cashPayment, 'narration' => 'Challan cash payment'];
        }

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
        );
    }

    private function prepare(Company $company): void
    {
        $this->coa->ensureDefaults($company);
        $this->periods->ensureCurrentFinancialYear($company);
    }

    /**
     * @return list<array{gl_ledger_id: string, debit_amount: numeric-string, credit_amount: numeric-string, narration: string}>
     */
    private function outputGstCreditLines(Company $company, BrokerInvoice $invoice, string $gstAmount): array
    {
        return $this->buildOutputGstCreditLines($company, $invoice, $gstAmount);
    }

    /**
     * @return list<array{gl_ledger_id: string, debit_amount: numeric-string, credit_amount: numeric-string, narration: string}>
     */
    private function buildOutputGstCreditLines(Company $company, BrokerInvoice $invoice, string $gstAmount): array
    {
        $gstAmount = $this->money($gstAmount);

        if (bccomp($gstAmount, '0', 2) !== 1) {
            return [];
        }

        $snapshot = $invoice->gst_rate_snapshot ?? [];
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
