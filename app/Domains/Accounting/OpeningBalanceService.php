<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Models\AccountingVoucher;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Controlled opening-balance cutover into the shared GL (Opening voucher type).
 */
final class OpeningBalanceService
{
    public function __construct(
        private readonly VoucherPostingService $posting = new VoucherPostingService,
        private readonly ChartOfAccountsBootstrapService $coa = new ChartOfAccountsBootstrapService,
        private readonly AccountingPeriodService $periods = new AccountingPeriodService,
    ) {}

    /**
     * @param  list<array{gl_ledger_id: string, debit_amount?: numeric-string|float|int|null, credit_amount?: numeric-string|float|int|null, narration?: string|null}>  $lines
     * @return array{total_debit: string, total_credit: string, balanced: bool, line_count: int, as_of: string}
     */
    public function dryRun(
        Company $company,
        array $lines,
        ?string $asOfDate = null,
    ): array {
        $this->coa->ensureDefaults($company);
        $asOf = filled($asOfDate)
            ? CarbonImmutable::parse($asOfDate)->toDateString()
            : $this->periods->ensureCurrentFinancialYear($company)->starts_on->toDateString();

        $normalized = $this->normalize($lines);
        $debit = '0.00';
        $credit = '0.00';
        foreach ($normalized as $line) {
            $debit = bcadd($debit, $line['debit_amount'], 2);
            $credit = bcadd($credit, $line['credit_amount'], 2);
        }

        return [
            'total_debit' => $debit,
            'total_credit' => $credit,
            'balanced' => bccomp($debit, $credit, 2) === 0 && bccomp($debit, '0', 2) === 1,
            'line_count' => count($normalized),
            'as_of' => $asOf,
        ];
    }

    /**
     * @param  list<array{gl_ledger_id: string, debit_amount?: numeric-string|float|int|null, credit_amount?: numeric-string|float|int|null, narration?: string|null}>  $lines
     */
    public function post(
        Company $company,
        User $actor,
        array $lines,
        ?Branch $branch = null,
        ?string $asOfDate = null,
        ?string $cutoverKey = null,
    ): AccountingVoucher {
        $preview = $this->dryRun($company, $lines, $asOfDate);
        if (! $preview['balanced']) {
            throw ValidationException::withMessages([
                'lines' => 'Opening balances must balance. Debit '.$preview['total_debit'].' vs Credit '.$preview['total_credit'].'.',
            ]);
        }

        $key = filled($cutoverKey) ? trim((string) $cutoverKey) : 'default';

        return $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Opening,
                'voucher_date' => $preview['as_of'],
                'narration' => 'Opening balances '.$preview['as_of'],
                'branch_id' => $branch?->id,
                'source_type' => 'accounting.opening_balance',
                'source_id' => $company->id.':'.$key,
                'posting_purpose' => 'opening_balance',
            ],
            lines: $this->normalize($lines),
            branch: $branch,
        );
    }

    /**
     * @param  list<array{gl_ledger_id: string, debit_amount?: numeric-string|float|int|null, credit_amount?: numeric-string|float|int|null, narration?: string|null}>  $lines
     * @return list<array{gl_ledger_id: string, debit_amount: numeric-string, credit_amount: numeric-string, narration: string}>
     */
    private function normalize(array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'At least one opening balance line is required.']);
        }

        $normalized = [];
        foreach ($lines as $index => $line) {
            $ledgerId = (string) $line['gl_ledger_id'];
            if ($ledgerId === '') {
                throw ValidationException::withMessages([
                    "lines.$index.gl_ledger_id" => 'Ledger is required.',
                ]);
            }

            $debit = number_format((float) ($line['debit_amount'] ?? 0), 2, '.', '');
            $credit = number_format((float) ($line['credit_amount'] ?? 0), 2, '.', '');
            if (bccomp($debit, '0', 2) === 1 && bccomp($credit, '0', 2) === 1) {
                throw ValidationException::withMessages([
                    "lines.$index" => 'A line cannot have both debit and credit.',
                ]);
            }
            if (bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0) {
                continue;
            }

            $normalized[] = [
                'gl_ledger_id' => $ledgerId,
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                'narration' => trim((string) ($line['narration'] ?? 'Opening balance')),
            ];
        }

        if ($normalized === []) {
            throw ValidationException::withMessages(['lines' => 'Opening balance lines must include non-zero amounts.']);
        }

        return $normalized;
    }
}
