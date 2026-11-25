<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Models\AccountingVoucher;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GlLedger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Contra transfers between Cash and Bank (and same-nature ledgers).
 */
final class FundTransferService
{
    public function __construct(
        private readonly VoucherPostingService $posting = new VoucherPostingService,
        private readonly ChartOfAccountsBootstrapService $coa = new ChartOfAccountsBootstrapService,
        private readonly SystemLedgerResolver $ledgers = new SystemLedgerResolver,
    ) {}

    /**
     * @param  array{
     *     from_ledger: 'cash'|'bank'|string,
     *     to_ledger: 'cash'|'bank'|string,
     *     amount: numeric-string|float|int,
     *     voucher_date?: string|null,
     *     narration?: string|null,
     *     idempotency_key?: string|null
     * }  $payload
     */
    public function transfer(
        Company $company,
        User $actor,
        array $payload,
        ?Branch $branch = null,
    ): AccountingVoucher {
        $this->coa->ensureDefaults($company);

        $amount = number_format((float) ($payload['amount'] ?? 0), 2, '.', '');
        if (bccomp($amount, '0', 2) !== 1) {
            throw ValidationException::withMessages(['amount' => 'Transfer amount must be positive.']);
        }

        $from = $this->resolveLedger($company, (string) ($payload['from_ledger'] ?? ''));
        $to = $this->resolveLedger($company, (string) ($payload['to_ledger'] ?? ''));
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_ledger' => 'From and To ledgers must differ.']);
        }

        $date = filled($payload['voucher_date'] ?? null)
            ? CarbonImmutable::parse((string) $payload['voucher_date'])->toDateString()
            : CarbonImmutable::now('Asia/Kolkata')->toDateString();

        $key = filled($payload['idempotency_key'] ?? null)
            ? trim((string) $payload['idempotency_key'])
            : (string) Str::uuid();

        return $this->posting->postManual(
            company: $company,
            actor: $actor,
            header: [
                'voucher_type' => AccountingVoucherType::Contra,
                'voucher_date' => $date,
                'narration' => filled($payload['narration'] ?? null)
                    ? trim((string) $payload['narration'])
                    : 'Fund transfer '.$from->name.' → '.$to->name,
                'branch_id' => $branch?->id,
                'source_type' => 'accounting.fund_transfer',
                'source_id' => $company->id.':'.$key,
                'posting_purpose' => 'fund_transfer',
            ],
            lines: [
                ['gl_ledger_id' => $to->id, 'debit_amount' => $amount, 'credit_amount' => 0, 'narration' => 'Transfer in'],
                ['gl_ledger_id' => $from->id, 'debit_amount' => 0, 'credit_amount' => $amount, 'narration' => 'Transfer out'],
            ],
            branch: $branch,
        );
    }

    private function resolveLedger(Company $company, string $key): GlLedger
    {
        $normalized = strtolower(trim($key));
        $name = match ($normalized) {
            'cash' => 'Cash',
            'bank' => 'Bank',
            default => null,
        };

        if ($name !== null) {
            return $this->ledgers->requireByName($company, $name);
        }

        if ($normalized === '') {
            throw ValidationException::withMessages(['from_ledger' => 'Ledger is required.']);
        }

        return $this->ledgers->requireByName($company, $key);
    }
}
