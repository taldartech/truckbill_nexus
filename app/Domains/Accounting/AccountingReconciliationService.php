<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Enums\AccountingPostingStatus;
use App\Domains\Broker\Enums\BrokerInvoiceStatus;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceStatus;
use App\Domains\Partload\Enums\PartloadChallanStatus;
use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Partload\Enums\PartloadMainChallanStatus;
use App\Models\AccountingActivationState;
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
use App\Models\VendorExpensePayment;

/**
 * Operational balances vs General Ledger reconciliation + voucher source drill-down (§30).
 */
final class AccountingReconciliationService
{
    public function __construct(
        private readonly AccountingWorkspaceService $workspace = new AccountingWorkspaceService,
        private readonly AccountingActivationLifecycleService $activation = new AccountingActivationLifecycleService,
    ) {}

    /**
     * @return array{
     *     balances: list<array{
     *         key: string,
     *         label: string,
     *         operational_amount: string,
     *         gl_amount: string,
     *         variance: string,
     *         reconciled: bool,
     *         explanation: string
     *     }>,
     *     profitability: array{
     *         operational_revenue: string,
     *         gl_income: string,
     *         operational_expenses: string,
     *         gl_expenses: string,
     *         operational_profit: string,
     *         gl_net_profit: string,
     *         profit_variance: string,
     *         explanation: string
     *     },
     *     unposted_operational_count: int,
     *     cutover_date: string|null
     * }
     */
    public function reconcile(
        Company $company,
        ?Branch $branch = null,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        $this->workspace->bootstrap($company);
        $state = AccountingActivationState::query()->where('company_id', $company->id)->first();
        $catchup = $this->activation->catchUpPreview($company);
        $unposted = (int) $catchup['unposted_count'];

        $opSummary = $this->activation->operationalCutoverSummary($company);

        $glReceivables = $this->glSignedBalance($company, 'Customer Receivables');
        $glTransporterPayables = $this->glCreditBalance($company, 'Transporter Payables');
        $glVendorPayables = $this->glCreditBalance($company, 'Vendor Payables');
        $glDriverAdvances = $this->glSignedBalance($company, 'Driver Advances');

        $rows = [
            $this->buildBalanceRow(
                'customer_receivables',
                'Customer Receivables',
                $opSummary['customer_receivables'],
                $glReceivables,
                $unposted,
                $state,
            ),
            $this->buildBalanceRow(
                'transporter_payables',
                'Transporter Payables',
                $opSummary['transporter_payables'],
                $glTransporterPayables,
                $unposted,
                $state,
            ),
            $this->buildBalanceRow(
                'vendor_payables',
                'Vendor Payables',
                $opSummary['vendor_payables'],
                $glVendorPayables,
                $unposted,
                $state,
            ),
            $this->buildBalanceRow(
                'driver_advances',
                'Driver Advances',
                $opSummary['driver_advances'],
                $glDriverAdvances,
                $unposted,
                $state,
            ),
        ];

        $pnl = $this->workspace->profitAndLoss($company, $fromDate, $toDate, $branch);

        $brokerRev = $this->money(BrokerInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', BrokerInvoiceStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->sum('taxable_total'));
        $tripRev = $this->money(TripInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', TripInvoiceStatus::Pending->value)
            ->whereNull('deleted_at')
            ->sum('taxable_total'));
        $partloadInvRev = $this->money(PartloadInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', 'issued')
            ->whereNull('deleted_at')
            ->sum('taxable_total'));
        $partloadPaidRev = $this->money(LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('workflow_context', 'partload')
            ->where('freight_payment_term', PartloadFreightPaymentTerm::Paid->value)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->sum('freight_total'));
        $partloadDirectDrRev = $this->money(PartloadDeliveryReceipt::query()
            ->where('company_id', $company->id)
            ->where('payment_mode', '!=', PartloadDeliveryPaymentMode::Due->value)
            ->whereNull('deleted_at')
            ->sum('final_amount'));

        $operationalRevenue = bcadd(
            bcadd($brokerRev, $tripRev, 2),
            bcadd($partloadInvRev, bcadd($partloadPaidRev, $partloadDirectDrRev, 2), 2),
            2,
        );

        $hireCost = $this->money(LorryHire::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', LorryHireStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->sum('freight_total'));
        $tripCost = $this->money(TripExpense::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->sum('amount'));
        $challanCost = $this->money(PartloadChallan::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [PartloadChallanStatus::Dispatched->value, PartloadChallanStatus::Received->value])
            ->whereNull('deleted_at')
            ->sum('total_amount'));
        $mainChallanCost = $this->money(PartloadMainChallan::query()
            ->where('company_id', $company->id)
            ->where('status', PartloadMainChallanStatus::Dispatched->value)
            ->whereNull('deleted_at')
            ->sum('total_amount'));

        $operationalExpenses = bcadd(bcadd($hireCost, $tripCost, 2), bcadd($challanCost, $mainChallanCost, 2), 2);
        $operationalProfit = bcsub($operationalRevenue, $operationalExpenses, 2);
        $glProfit = $this->money($pnl['net_profit']);
        $profitVariance = bcsub($operationalProfit, $glProfit, 2);

        $profitExplanation = bccomp($profitVariance, '0.00', 2) === 0
            ? 'Operational profitability reconciles with General Ledger P&L.'
            : ($unposted > 0
                ? "{$unposted} operational event(s) occurred while Accounting was inactive or pre-cutover; run catch-up if within active period."
                : 'Variance reflects pre-cutover operational history, manual GL journal adjustments, or non-operational Indirect Expenses (e.g. SaaS subscription invoices).');

        return [
            'balances' => $rows,
            'profitability' => [
                'operational_revenue' => $operationalRevenue,
                'gl_income' => $this->money($pnl['total_income']),
                'operational_expenses' => $operationalExpenses,
                'gl_expenses' => $this->money($pnl['total_expenses']),
                'operational_profit' => $operationalProfit,
                'gl_net_profit' => $glProfit,
                'profit_variance' => $profitVariance,
                'explanation' => $profitExplanation,
            ],
            'unposted_operational_count' => $unposted,
            'cutover_date' => $state?->cutover_date->toDateString(),
        ];
    }

    /**
     * Resolve authoritative operational/platform source document link for a posted voucher (§30).
     *
     * @return array{source_type: string, source_id: string, posting_purpose: string, label: string, url: string|null}|null
     */
    public function sourceDocumentDrillDown(
        Company $company,
        AccountingVoucher $voucher,
        bool $maySeeFreight,
    ): ?array {
        if (! $maySeeFreight) {
            return null;
        }

        /** @var AccountingPostingLink|null $link */
        $link = AccountingPostingLink::query()
            ->where('company_id', $company->id)
            ->where('accounting_voucher_id', $voucher->id)
            ->where('status', AccountingPostingStatus::Posted->value)
            ->first();

        if ($link === null) {
            return null;
        }

        [$label, $url] = match ($link->source_type) {
            BrokerInvoice::class, 'broker_invoice' => [
                'Broker Customer Invoice',
                route('branch.broker.invoices.show', [$link->source_id], absolute: false),
            ],
            BrokerInvoicePayment::class, 'broker_invoice_payment' => [
                'Broker Invoice Receipt',
                null,
            ],
            LorryHire::class, 'lorry_hire' => [
                'Broker Lorry Hire',
                route('branch.broker.lorry-hires.show', [$link->source_id], absolute: false),
            ],
            BrokerAdjustment::class, 'broker_adjustment', TransporterVoucher::class, 'transporter_voucher' => [
                'Broker Lorry Hire / Voucher',
                null,
            ],
            TripInvoice::class, 'trip_invoice' => [
                'OwnFleet Trip Invoice',
                route('branch.trip-invoices.show', [$link->source_id], absolute: false),
            ],
            TripInvoicePayment::class, 'trip_invoice_payment' => [
                'OwnFleet Invoice Receipt',
                null,
            ],
            PartloadInvoice::class, 'partload_invoice' => [
                'Partload Customer Invoice',
                route('branch.partload.invoices.show', [$link->source_id], absolute: false),
            ],
            PartloadInvoicePayment::class, 'partload_invoice_payment' => [
                'Partload Invoice Receipt',
                null,
            ],
            PartloadChallan::class, 'partload_challan' => [
                'Partload Challan',
                route('branch.partload.challans.show', [$link->source_id], absolute: false),
            ],
            PartloadMainChallan::class, 'partload_main_challan' => [
                'Partload Main Challan',
                route('branch.partload.main-challans.show', [$link->source_id], absolute: false),
            ],
            PartloadDeliveryReceipt::class, 'partload_dr' => ['Partload Delivery Receipt', null],
            LorryReceipt::class, 'partload_paid_lr' => [
                'Partload Paid Bilty',
                route('branch.lorry-receipts.show', [$link->source_id], absolute: false),
            ],
            TripExpense::class, 'trip_expense' => ['OwnFleet Trip Expense', null],
            VendorExpensePayment::class, 'vendor_expense_payment' => ['OwnFleet Vendor Payment', null],
            DriverAdvanceEntry::class, 'driver_advance' => ['OwnFleet Driver Advance Entry', null],
            SubscriptionInvoice::class, 'subscription_invoice' => ['Platform Subscription Expense', null],
            default => [class_basename($link->source_type), null],
        };

        return [
            'source_type' => $link->source_type,
            'source_id' => $link->source_id,
            'posting_purpose' => $link->posting_purpose,
            'label' => $label,
            'url' => $url,
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     operational_amount: string,
     *     gl_amount: string,
     *     variance: string,
     *     reconciled: bool,
     *     explanation: string
     * }
     */
    private function buildBalanceRow(
        string $key,
        string $label,
        string $operationalAmount,
        string $glAmount,
        int $unpostedCount,
        ?AccountingActivationState $state,
    ): array {
        $op = $this->money($operationalAmount);
        $gl = $this->money($glAmount);
        $variance = bcsub($op, $gl, 2);
        $reconciled = bccomp($variance, '0.00', 2) === 0;

        $explanation = $reconciled
            ? 'Operational ledger and General Ledger reconcile cleanly.'
            : ($unpostedCount > 0
                ? "Variance may include {$unpostedCount} unposted operational event(s) awaiting catch-up."
                : ($state !== null && $state->opening_voucher_id === null
                    ? 'Pre-cutover balances have not been brought into GL via Opening Balance Cutover yet.'
                    : 'Variance stems from manual GL entries, tax adjustments, or pre-cutover history.'));

        return [
            'key' => $key,
            'label' => $label,
            'operational_amount' => $op,
            'gl_amount' => $gl,
            'variance' => $variance,
            'reconciled' => $reconciled,
            'explanation' => $explanation,
        ];
    }

    private function glSignedBalance(Company $company, string $ledgerName): string
    {
        $ledger = GlLedger::query()->where('company_id', $company->id)->where('name', $ledgerName)->whereNull('deleted_at')->first();
        if ($ledger === null) {
            return '0.00';
        }

        $stmt = $this->workspace->ledgerStatement($company, $ledger, '1970-01-01', null, null);

        return bcsub($stmt['closing_debit'], $stmt['closing_credit'], 2);
    }

    private function glCreditBalance(Company $company, string $ledgerName): string
    {
        $ledger = GlLedger::query()->where('company_id', $company->id)->where('name', $ledgerName)->whereNull('deleted_at')->first();
        if ($ledger === null) {
            return '0.00';
        }

        $stmt = $this->workspace->ledgerStatement($company, $ledger, '1970-01-01', null, null);

        return bcsub($stmt['closing_credit'], $stmt['closing_debit'], 2);
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        /** @var numeric-string $formatted */
        $formatted = number_format((float) ($value ?? 0), 2, '.', '');

        return $formatted;
    }
}
