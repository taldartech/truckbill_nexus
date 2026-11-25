<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Enums\AccountingVoucherStatus;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Models\AccountingVoucher;
use App\Models\AccountingVoucherLine;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GlLedger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Branch/Company accounting workspace queries — Day Book, Ledger, Trial Balance.
 */
final class AccountingWorkspaceService
{
    public function __construct(
        private readonly ChartOfAccountsBootstrapService $coa = new ChartOfAccountsBootstrapService,
        private readonly AccountingPeriodService $periods = new AccountingPeriodService,
    ) {}

    /**
     * @return array{groups: int, ledgers: int, period_label: string|null}
     */
    public function bootstrap(Company $company): array
    {
        $seeded = $this->coa->ensureDefaults($company);
        $period = $this->periods->ensureCurrentFinancialYear($company);

        return [
            'groups' => $seeded['groups'],
            'ledgers' => $seeded['ledgers'],
            'period_label' => $period->label,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, AccountingVoucher>
     */
    public function dayBook(
        Company $company,
        ?Branch $branch = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $voucherType = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $builder = AccountingVoucher::query()
            ->where('company_id', $company->id)
            ->where('status', AccountingVoucherStatus::Posted->value)
            ->whereNull('deleted_at')
            ->with(['lines.ledger', 'branch'])
            ->orderByDesc('voucher_date')
            ->orderByDesc('created_at');

        if ($branch !== null) {
            $builder->where(function ($query) use ($branch): void {
                $query->where('branch_id', $branch->id)->orWhereNull('branch_id');
            });
        }

        if (filled($fromDate)) {
            $builder->whereDate('voucher_date', '>=', $fromDate);
        }

        if (filled($toDate)) {
            $builder->whereDate('voucher_date', '<=', $toDate);
        }

        if (filled($voucherType)) {
            $builder->where('voucher_type', $voucherType);
        }

        return $builder->paginate($perPage);
    }

    /**
     * @return array{
     *     ledger: GlLedger,
     *     opening_debit: string,
     *     opening_credit: string,
     *     rows: list<array{
     *         date: string,
     *         voucher_type: string,
     *         voucher_number: string,
     *         particulars: string,
     *         debit: string,
     *         credit: string,
     *         balance: string,
     *         balance_side: string,
     *         voucher_id: string
     *     }>,
     *     closing_debit: string,
     *     closing_credit: string
     * }
     */
    public function ledgerStatement(
        Company $company,
        GlLedger $ledger,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?Branch $branch = null,
    ): array {
        if ($ledger->company_id !== $company->id) {
            throw new Exceptions\AccountingDomainException('Ledger does not belong to company.');
        }

        $from = filled($fromDate)
            ? CarbonImmutable::parse($fromDate)->startOfDay()
            : $this->periods->ensureCurrentFinancialYear($company)->starts_on->toImmutable()->startOfDay();
        $to = filled($toDate)
            ? CarbonImmutable::parse($toDate)->endOfDay()
            : CarbonImmutable::now('Asia/Kolkata')->endOfDay();

        $openingQuery = AccountingVoucherLine::query()
            ->join('accounting_vouchers', 'accounting_vouchers.id', '=', 'accounting_voucher_lines.accounting_voucher_id')
            ->where('accounting_voucher_lines.company_id', $company->id)
            ->where('accounting_voucher_lines.gl_ledger_id', $ledger->id)
            ->where('accounting_vouchers.status', AccountingVoucherStatus::Posted->value)
            ->whereNull('accounting_vouchers.deleted_at')
            ->whereDate('accounting_vouchers.voucher_date', '<', $from->toDateString());

        if ($branch !== null) {
            $openingQuery->where(function ($query) use ($branch): void {
                $query->where('accounting_vouchers.branch_id', $branch->id)
                    ->orWhereNull('accounting_vouchers.branch_id');
            });
        }

        $openingDebit = (string) ($openingQuery->clone()->sum('accounting_voucher_lines.debit_amount') ?: '0');
        $openingCredit = (string) ($openingQuery->clone()->sum('accounting_voucher_lines.credit_amount') ?: '0');
        $openingDebit = number_format((float) $openingDebit, 2, '.', '');
        $openingCredit = number_format((float) $openingCredit, 2, '.', '');

        $linesQuery = AccountingVoucherLine::query()
            ->join('accounting_vouchers', 'accounting_vouchers.id', '=', 'accounting_voucher_lines.accounting_voucher_id')
            ->where('accounting_voucher_lines.company_id', $company->id)
            ->where('accounting_voucher_lines.gl_ledger_id', $ledger->id)
            ->where('accounting_vouchers.status', AccountingVoucherStatus::Posted->value)
            ->whereNull('accounting_vouchers.deleted_at')
            ->whereDate('accounting_vouchers.voucher_date', '>=', $from->toDateString())
            ->whereDate('accounting_vouchers.voucher_date', '<=', $to->toDateString())
            ->orderBy('accounting_vouchers.voucher_date')
            ->orderBy('accounting_vouchers.created_at')
            ->select([
                'accounting_voucher_lines.*',
                'accounting_vouchers.voucher_date',
                'accounting_vouchers.voucher_type',
                'accounting_vouchers.voucher_number',
                'accounting_vouchers.narration as voucher_narration',
            ]);

        if ($branch !== null) {
            $linesQuery->where(function ($query) use ($branch): void {
                $query->where('accounting_vouchers.branch_id', $branch->id)
                    ->orWhereNull('accounting_vouchers.branch_id');
            });
        }

        $running = bcsub($openingDebit, $openingCredit, 2);
        $rows = [];
        $periodDebit = '0.00';
        $periodCredit = '0.00';

        foreach ($linesQuery->get() as $line) {
            $debit = number_format((float) $line->debit_amount, 2, '.', '');
            $credit = number_format((float) $line->credit_amount, 2, '.', '');
            $periodDebit = bcadd($periodDebit, $debit, 2);
            $periodCredit = bcadd($periodCredit, $credit, 2);
            $running = bcadd($running, bcsub($debit, $credit, 2), 2);
            $side = bccomp($running, '0', 2) >= 0 ? 'Dr' : 'Cr';
            $rows[] = [
                'date' => CarbonImmutable::parse((string) $line->voucher_date)->toDateString(),
                'voucher_type' => (string) $line->voucher_type,
                'voucher_number' => (string) $line->voucher_number,
                'particulars' => (string) ($line->narration ?: $line->voucher_narration ?: $ledger->name),
                'debit' => $debit,
                'credit' => $credit,
                'balance' => number_format(abs((float) $running), 2, '.', ''),
                'balance_side' => $side,
                'voucher_id' => (string) $line->accounting_voucher_id,
            ];
        }

        $closing = bcadd(bcsub($openingDebit, $openingCredit, 2), bcsub($periodDebit, $periodCredit, 2), 2);
        $closingDebit = bccomp($closing, '0', 2) >= 0 ? $closing : '0.00';
        $closingCredit = bccomp($closing, '0', 2) < 0 ? number_format(abs((float) $closing), 2, '.', '') : '0.00';

        return [
            'ledger' => $ledger,
            'opening_debit' => $openingDebit,
            'opening_credit' => $openingCredit,
            'rows' => $rows,
            'closing_debit' => $closingDebit,
            'closing_credit' => $closingCredit,
        ];
    }

    /**
     * @return list<array{
     *     ledger_id: string,
     *     ledger_name: string,
     *     group_name: string,
     *     opening_debit: string,
     *     opening_credit: string,
     *     period_debit: string,
     *     period_credit: string,
     *     closing_debit: string,
     *     closing_credit: string
     * }>
     */
    public function trialBalance(
        Company $company,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?Branch $branch = null,
    ): array {
        $from = filled($fromDate)
            ? CarbonImmutable::parse($fromDate)->toDateString()
            : $this->periods->ensureCurrentFinancialYear($company)->starts_on->toDateString();
        $to = filled($toDate)
            ? CarbonImmutable::parse($toDate)->toDateString()
            : CarbonImmutable::now('Asia/Kolkata')->toDateString();

        $ledgers = GlLedger::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->with('accountGroup')
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($ledgers as $ledger) {
            $statement = $this->ledgerStatement($company, $ledger, $from, $to, $branch);
            $periodDebit = '0.00';
            $periodCredit = '0.00';
            foreach ($statement['rows'] as $row) {
                $periodDebit = bcadd($periodDebit, $row['debit'], 2);
                $periodCredit = bcadd($periodCredit, $row['credit'], 2);
            }

            if (
                bccomp($statement['opening_debit'], '0', 2) === 0
                && bccomp($statement['opening_credit'], '0', 2) === 0
                && bccomp($periodDebit, '0', 2) === 0
                && bccomp($periodCredit, '0', 2) === 0
            ) {
                continue;
            }

            $rows[] = [
                'ledger_id' => $ledger->id,
                'ledger_name' => $ledger->name,
                'group_name' => $ledger->accountGroup?->name ?? '',
                'opening_debit' => $statement['opening_debit'],
                'opening_credit' => $statement['opening_credit'],
                'period_debit' => $periodDebit,
                'period_credit' => $periodCredit,
                'closing_debit' => $statement['closing_debit'],
                'closing_credit' => $statement['closing_credit'],
            ];
        }

        return $rows;
    }

    /**
     * @return Collection<int, GlLedger>
     */
    public function activeLedgers(Company $company): Collection
    {
        return GlLedger::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->with('accountGroup')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function voucherTypeOptions(): array
    {
        return collect(AccountingVoucherType::cases())
            ->map(fn (AccountingVoucherType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ])
            ->values()
            ->all();
    }
}
