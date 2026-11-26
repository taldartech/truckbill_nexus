<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Enums\AccountingPostingStatus;
use App\Domains\Accounting\Enums\AccountingVoucherStatus;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Accounting\Exceptions\AccountingDomainException;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\AccountingPostingLink;
use App\Models\AccountingVoucher;
use App\Models\AccountingVoucherLine;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GlLedger;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Posts balanced, immutable accounting vouchers with source-event idempotency.
 */
final class VoucherPostingService
{
    public function __construct(
        private readonly AuditedTransaction $audited = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly AccountingPeriodService $periods = new AccountingPeriodService,
    ) {}

    /**
     * @param  list<array{
     *     gl_ledger_id: string,
     *     debit_amount?: numeric-string|float|int|string|null,
     *     credit_amount?: numeric-string|float|int|string|null,
     *     narration?: string|null,
     *     branch_id?: string|null
     * }>  $lines
     * @param  array{
     *     voucher_type: AccountingVoucherType|string,
     *     voucher_date: CarbonImmutable|string,
     *     narration?: string|null,
     *     branch_id?: string|null,
     *     voucher_number?: string|null,
     *     source_type?: string|null,
     *     source_id?: string|null,
     *     posting_purpose?: string|null,
     * }  $header
     */
    public function postManual(
        Company $company,
        User $actor,
        array $header,
        array $lines,
        ?Branch $branch = null,
        bool $allowControlledCorrection = false,
    ): AccountingVoucher {
        if (! $allowControlledCorrection
            && ! app(CompanyCapabilityResolver::class)->allowsMutate($company, CompanyCapabilities::ACCOUNTING)) {
            throw new AccountingDomainException('Accounting add-on entitlement is required to post accounting vouchers.');
        }

        $type = $header['voucher_type'] instanceof AccountingVoucherType
            ? $header['voucher_type']
            : AccountingVoucherType::from((string) $header['voucher_type']);

        $date = $header['voucher_date'] instanceof CarbonImmutable
            ? $header['voucher_date']
            : CarbonImmutable::parse((string) $header['voucher_date'], 'Asia/Kolkata');

        $period = $this->periods->periodForDate($company, $date);
        $this->periods->assertOpenForPosting($period);

        $normalized = $this->normalizeLines($company, $lines);
        [$totalDebit, $totalCredit] = $this->totals($normalized);
        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw ValidationException::withMessages([
                'lines' => 'Accounting voucher debit and credit totals must balance.',
            ]);
        }
        if (bccomp($totalDebit, '0', 2) !== 1) {
            throw ValidationException::withMessages([
                'lines' => 'Accounting voucher must have a positive total amount.',
            ]);
        }

        $sourceType = $header['source_type'] ?? null;
        $sourceId = $header['source_id'] ?? null;
        $purpose = $header['posting_purpose'] ?? 'manual';

        if (is_string($sourceType) && $sourceType !== '' && is_string($sourceId) && $sourceId !== '') {
            $existing = AccountingPostingLink::query()
                ->where('company_id', $company->id)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('posting_purpose', $purpose)
                ->where('status', AccountingPostingStatus::Posted->value)
                ->first();
            if ($existing?->accounting_voucher_id) {
                $voucher = AccountingVoucher::query()->find($existing->accounting_voucher_id);
                if ($voucher !== null) {
                    return $voucher->load('lines');
                }
            }
        }

        $branchId = $header['branch_id'] ?? $branch?->id;

        try {
            return $this->audited->run(function () use (
                $company,
                $actor,
                $type,
                $date,
                $period,
                $normalized,
                $totalDebit,
                $totalCredit,
                $header,
                $sourceType,
                $sourceId,
                $purpose,
                $branchId,
            ): AccountingVoucher {
                return $this->withTenant($company, $branchId, function () use (
                    $company,
                    $actor,
                    $type,
                    $date,
                    $period,
                    $normalized,
                    $totalDebit,
                    $totalCredit,
                    $header,
                    $sourceType,
                    $sourceId,
                    $purpose,
                    $branchId,
                ): AccountingVoucher {
                    $number = trim((string) ($header['voucher_number'] ?? ''));
                    if ($number === '') {
                        $number = $this->nextNumber($company, $type);
                    }

                    $voucher = AccountingVoucher::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branchId,
                        'accounting_period_id' => $period->id,
                        'voucher_type' => $type,
                        'voucher_number' => $number,
                        'voucher_date' => $date->toDateString(),
                        'status' => AccountingVoucherStatus::Posted,
                        'narration' => $header['narration'] ?? null,
                        'total_debit' => $totalDebit,
                        'total_credit' => $totalCredit,
                        'posted_at' => now(),
                        'posted_by_user_id' => $actor->id,
                        'created_by_user_id' => $actor->id,
                    ]);

                    foreach ($normalized as $index => $line) {
                        AccountingVoucherLine::query()->create([
                            'id' => (string) Str::uuid(),
                            'company_id' => $company->id,
                            'accounting_voucher_id' => $voucher->id,
                            'gl_ledger_id' => $line['gl_ledger_id'],
                            'branch_id' => $line['branch_id'] ?? $branchId,
                            'line_no' => $index + 1,
                            'debit_amount' => $line['debit_amount'],
                            'credit_amount' => $line['credit_amount'],
                            'narration' => $line['narration'],
                        ]);
                    }

                    if (is_string($sourceType) && $sourceType !== '' && is_string($sourceId) && $sourceId !== '') {
                        AccountingPostingLink::query()->create([
                            'id' => (string) Str::uuid(),
                            'company_id' => $company->id,
                            'source_type' => $sourceType,
                            'source_id' => $sourceId,
                            'posting_purpose' => $purpose,
                            'accounting_voucher_id' => $voucher->id,
                            'status' => AccountingPostingStatus::Posted,
                        ]);
                    }

                    $this->audit->write(
                        AuditAction::Created,
                        AccountingVoucher::class,
                        $voucher->id,
                        remark: null,
                        before: null,
                        after: [
                            'voucher_number' => $voucher->voucher_number,
                            'voucher_type' => $type->value,
                            'total_debit' => $totalDebit,
                            'total_credit' => $totalCredit,
                            'status' => AccountingVoucherStatus::Posted->value,
                        ],
                    );

                    return $voucher->load('lines');
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            if (is_string($sourceType) && $sourceType !== '' && is_string($sourceId) && $sourceId !== '') {
                $link = AccountingPostingLink::query()
                    ->where('company_id', $company->id)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->where('posting_purpose', $purpose)
                    ->first();
                if ($link?->accounting_voucher_id) {
                    $voucher = AccountingVoucher::query()->find($link->accounting_voucher_id);
                    if ($voucher !== null) {
                        return $voucher->load('lines');
                    }
                }
            }

            throw new AccountingDomainException('Unable to post accounting voucher due to a uniqueness conflict.', 0, $e);
        }
    }

    public function reverse(
        AccountingVoucher $voucher,
        User $actor,
        string $remark,
        bool $allowControlledCorrection = false,
    ): AccountingVoucher {
        if ($voucher->status !== AccountingVoucherStatus::Posted) {
            throw new AccountingDomainException('Only posted vouchers can be reversed.');
        }

        $company = Company::query()->findOrFail($voucher->company_id);
        if (! $allowControlledCorrection
            && ! app(CompanyCapabilityResolver::class)->allowsMutate($company, CompanyCapabilities::ACCOUNTING)) {
            throw new AccountingDomainException('Accounting add-on entitlement is required to reverse accounting vouchers.');
        }

        $voucher->loadMissing('lines');
        $period = $this->periods->periodForDate(
            $company,
            $voucher->voucher_date->toDateString(),
        );
        $this->periods->assertOpenForPosting($period);

        $reversalLines = $voucher->lines->map(fn (AccountingVoucherLine $line): array => [
            'gl_ledger_id' => $line->gl_ledger_id,
            'debit_amount' => $this->money($line->credit_amount, 'debit_amount'),
            'credit_amount' => $this->money($line->debit_amount, 'credit_amount'),
            'narration' => 'Reversal of '.$voucher->voucher_number,
            'branch_id' => $line->branch_id,
        ])->values()->all();

        $reversalLines = array_values($reversalLines);

        $reversal = $this->postManual(
            $company,
            $actor,
            [
                'voucher_type' => $voucher->voucher_type,
                'voucher_date' => $voucher->voucher_date->toDateString(),
                'narration' => $remark,
                'branch_id' => $voucher->branch_id,
                'source_type' => 'accounting.voucher.reversal',
                'source_id' => $voucher->id,
                'posting_purpose' => 'reversal',
            ],
            $reversalLines,
            allowControlledCorrection: $allowControlledCorrection,
        );

        return $this->audited->run(function () use ($voucher, $reversal, $remark, $company): AccountingVoucher {
            return $this->withTenant($company, $voucher->branch_id, function () use ($voucher, $reversal, $remark): AccountingVoucher {
                $reversal->forceFill([
                    'reversal_of_voucher_id' => $voucher->id,
                ])->save();

                $before = $voucher->toArray();
                $voucher->forceFill([
                    'status' => AccountingVoucherStatus::Reversed,
                ])->save();

                $this->audit->write(
                    AuditAction::Updated,
                    AccountingVoucher::class,
                    $voucher->id,
                    remark: $remark,
                    before: $before,
                    after: $voucher->fresh()?->toArray(),
                );

                return $reversal->fresh(['lines']);
            });
        });
    }

    private function withTenant(Company $company, ?string $branchId, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: $branchId,
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

    /**
     * @param  list<array{
     *     gl_ledger_id: string,
     *     debit_amount?: numeric-string|float|int|string|null,
     *     credit_amount?: numeric-string|float|int|string|null,
     *     narration?: string|null,
     *     branch_id?: string|null
     * }>  $lines
     * @return list<array{gl_ledger_id: string, debit_amount: numeric-string, credit_amount: numeric-string, narration: string|null, branch_id: string|null}>
     */
    private function normalizeLines(Company $company, array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'At least two accounting lines are required.']);
        }

        $normalized = [];
        foreach ($lines as $index => $line) {
            $ledgerId = (string) $line['gl_ledger_id'];
            $ledger = GlLedger::query()
                ->where('company_id', $company->id)
                ->whereKey($ledgerId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first();
            if ($ledger === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.gl_ledger_id" => 'Ledger not found for this company.',
                ]);
            }

            $debit = $this->money($line['debit_amount'] ?? 0, "lines.{$index}.debit_amount");
            $credit = $this->money($line['credit_amount'] ?? 0, "lines.{$index}.credit_amount");

            if (bccomp($debit, '0', 2) === 1 && bccomp($credit, '0', 2) === 1) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => 'A line cannot have both debit and credit amounts.',
                ]);
            }
            if (bccomp($debit, '0', 2) !== 1 && bccomp($credit, '0', 2) !== 1) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => 'A line must have either a debit or a credit amount.',
                ]);
            }

            $normalized[] = [
                'gl_ledger_id' => $ledger->id,
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                'narration' => isset($line['narration']) ? trim((string) $line['narration']) : null,
                'branch_id' => $line['branch_id'] ?? null,
            ];
        }

        if (count($normalized) < 2) {
            throw ValidationException::withMessages(['lines' => 'At least two accounting lines are required.']);
        }

        return $normalized;
    }

    /**
     * @param  list<array{debit_amount: numeric-string, credit_amount: numeric-string}>  $lines
     * @return array{0: numeric-string, 1: numeric-string}
     */
    private function totals(array $lines): array
    {
        $debit = '0.00';
        $credit = '0.00';
        foreach ($lines as $line) {
            $debit = bcadd($debit, $line['debit_amount'], 2);
            $credit = bcadd($credit, $line['credit_amount'], 2);
        }

        return [$debit, $credit];
    }

    private function nextNumber(Company $company, AccountingVoucherType $type): string
    {
        $prefix = strtoupper(substr($type->value, 0, 3));
        $count = AccountingVoucher::query()
            ->where('company_id', $company->id)
            ->where('voucher_type', $type->value)
            ->count() + 1;

        return sprintf('%s-%06d', $prefix, $count);
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([$field => 'A valid amount is required.']);
        }

        return number_format((float) $value, 2, '.', '');
    }
}
