<?php

namespace App\Domains\Broker\Voucher;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Broker\Enums\BookBalanceEntryType;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartySearch;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\TransporterBookBalanceEntry;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Presentation\Money;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transporter book-balance ledger entries (Rules §11, §38 — signed amounts, idempotent source).
 */
final class TransporterBookBalanceService
{
    public const SOURCE_VOUCHER_ADJUST = 'transporter_voucher_adjust';

    public const SOURCE_LORRY_HIRE_CANCEL = 'lorry_hire_cancellation';

    public const SOURCE_MANUAL_TRANSACTION = 'manual_book_balance';

    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly OperationalAccountingPoster $accounting = new OperationalAccountingPoster,
        private readonly PartySearch $partySearch = new PartySearch,
    ) {}

    /**
     * @param  array{
     *     transporter_party_id: string,
     *     lorry_hire_id?: string|null,
     *     transporter_voucher_id?: string|null,
     *     amount: numeric-string|float|int,
     *     entry_type: BookBalanceEntryType|string,
     *     entry_date?: string|null,
     *     payment_mode?: string|null,
     *     bank_cash_account_id?: string|null,
     *     reference_number?: string|null,
     *     source_type: string,
     *     source_id: string,
     *     remark?: string|null,
     * }  $payload
     */
    public function createSignedEntry(
        Company $company,
        Branch $branch,
        User $actor,
        array $payload,
    ): TransporterBookBalanceEntry {
        $this->assertBrokerEntitled($company);

        $entryType = $payload['entry_type'] instanceof BookBalanceEntryType
            ? $payload['entry_type']
            : BookBalanceEntryType::from((string) $payload['entry_type']);

        $amount = $this->signedMoney($payload['amount'], 'amount');
        $sourceType = trim((string) $payload['source_type']);
        $sourceId = trim((string) $payload['source_id']);

        if ($sourceType === '' || $sourceId === '') {
            throw ValidationException::withMessages([
                'source_type' => 'Book balance source reference is required.',
            ]);
        }

        $entryDate = filled($payload['entry_date'] ?? null)
            ? CarbonImmutable::parse((string) $payload['entry_date'])->toDateString()
            : $this->defaultEntryDate($company);

        return $this->auditedTransaction->run(function () use (
            $company,
            $branch,
            $actor,
            $payload,
            $entryType,
            $amount,
            $entryDate,
            $sourceType,
            $sourceId,
        ): TransporterBookBalanceEntry {
            return $this->withTenant($company, $branch, function () use (
                $company,
                $branch,
                $actor,
                $payload,
                $entryType,
                $amount,
                $entryDate,
                $sourceType,
                $sourceId,
            ): TransporterBookBalanceEntry {
                $existing = TransporterBookBalanceEntry::query()
                    ->where('company_id', $company->id)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $idempotencyKey = $sourceType.':'.$sourceId;

                $entry = TransporterBookBalanceEntry::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'transporter_party_id' => (string) $payload['transporter_party_id'],
                    'lorry_hire_id' => filled($payload['lorry_hire_id'] ?? null)
                        ? (string) $payload['lorry_hire_id']
                        : null,
                    'transporter_voucher_id' => filled($payload['transporter_voucher_id'] ?? null)
                        ? (string) $payload['transporter_voucher_id']
                        : null,
                    'amount' => $amount,
                    'entry_type' => $entryType,
                    'entry_date' => $entryDate,
                    'payment_mode' => filled($payload['payment_mode'] ?? null)
                        ? trim((string) $payload['payment_mode'])
                        : null,
                    'bank_cash_account_id' => filled($payload['bank_cash_account_id'] ?? null)
                        ? (string) $payload['bank_cash_account_id']
                        : null,
                    'reference_number' => filled($payload['reference_number'] ?? null)
                        ? trim((string) $payload['reference_number'])
                        : null,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'idempotency_key' => $idempotencyKey,
                    'remark' => filled($payload['remark'] ?? null) ? trim((string) $payload['remark']) : null,
                    'created_by_user_id' => $actor->id,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    TransporterBookBalanceEntry::class,
                    $entry->id,
                    remark: 'Transporter book balance entry',
                    before: null,
                    after: $entry->toArray(),
                );

                return $entry;
            });
        });
    }

    /**
     * Record a manual Book Balance transaction (Advance, Refund/Recovery, Adjustment, Opening Balance)
     * without requiring an existing Lorry Hire.
     *
     * @param  array{
     *     transporter_party_id?: mixed,
     *     transaction_type?: mixed,
     *     adjustment_direction?: mixed,
     *     amount?: mixed,
     *     entry_date?: mixed,
     *     payment_mode?: mixed,
     *     bank_cash_account_id?: mixed,
     *     reference_number?: mixed,
     *     remark?: mixed,
     *     idempotency_key?: mixed,
     * }  $payload
     */
    public function recordTransaction(
        Company $company,
        Branch $branch,
        User $actor,
        array $payload,
    ): TransporterBookBalanceEntry {
        $this->ensureTenantMatches($company, $branch);
        $this->assertCanManageBookBalance($actor, $company, $branch);
        $this->assertBrokerEntitled($company);

        $transporterPartyId = trim((string) ($payload['transporter_party_id'] ?? ''));
        if ($transporterPartyId === '' || ! Str::isUuid($transporterPartyId)) {
            throw ValidationException::withMessages([
                'transporter_party_id' => 'Select a valid transporter.',
            ]);
        }

        $transporter = $this->resolveEligibleTransporter($company, $branch, $transporterPartyId);

        $transactionType = strtolower(trim((string) ($payload['transaction_type'] ?? '')));
        $direction = strtolower(trim((string) ($payload['adjustment_direction'] ?? '')));
        $amount = $this->positiveAmount($payload['amount'] ?? null, 'amount');
        $entryDate = $this->validatedEntryDate($company, $payload['entry_date'] ?? null);

        $paymentMode = filled($payload['payment_mode'] ?? null)
            ? strtolower(trim((string) $payload['payment_mode']))
            : null;
        $bankCashAccountId = filled($payload['bank_cash_account_id'] ?? null)
            ? trim((string) $payload['bank_cash_account_id'])
            : null;
        $referenceNumber = filled($payload['reference_number'] ?? null)
            ? trim((string) $payload['reference_number'])
            : null;
        $remark = filled($payload['remark'] ?? null)
            ? trim((string) $payload['remark'])
            : null;

        [$entryType, $signedAmount] = match ($transactionType) {
            'advance' => [BookBalanceEntryType::DirectAdvance, $amount],
            'recovery' => [BookBalanceEntryType::BalanceRecovery, bcmul($amount, '-1', 2)],
            'opening_balance' => [BookBalanceEntryType::OpeningBalance, $amount],
            'adjustment' => $this->resolveAdjustmentEntry($direction, $amount),
            default => throw ValidationException::withMessages([
                'transaction_type' => 'Select a valid transaction type.',
            ]),
        };

        if (in_array($transactionType, ['advance', 'recovery'], true)) {
            if (! in_array($paymentMode, ['cash', 'bank'], true)) {
                throw ValidationException::withMessages([
                    'payment_mode' => 'Select Cash or Bank as the payment mode.',
                ]);
            }
        } elseif ($transactionType === 'adjustment') {
            if ($paymentMode !== null && ! in_array($paymentMode, ['cash', 'bank'], true)) {
                throw ValidationException::withMessages([
                    'payment_mode' => 'Payment mode must be Cash, Bank, or Non-cash adjustment.',
                ]);
            }
            if ($remark === null) {
                throw ValidationException::withMessages([
                    'remark' => 'Remark is required for a balance adjustment.',
                ]);
            }
        } else {
            // Opening balance brings forward prior operational balance against Capital in GL.
            $paymentMode = null;
            $bankCashAccountId = null;
        }

        if ($paymentMode === 'bank') {
            $bankCashAccountId = $this->resolveBankAccountId($company, $branch, $bankCashAccountId);
        } else {
            $bankCashAccountId = null;
        }

        $clientKey = filled($payload['idempotency_key'] ?? null)
            ? trim((string) $payload['idempotency_key'])
            : null;

        return $this->auditedTransaction->run(function () use (
            $company,
            $branch,
            $actor,
            $transporter,
            $entryType,
            $signedAmount,
            $amount,
            $entryDate,
            $paymentMode,
            $bankCashAccountId,
            $referenceNumber,
            $remark,
            $clientKey,
        ): TransporterBookBalanceEntry {
            return $this->withTenant($company, $branch, function () use (
                $company,
                $branch,
                $actor,
                $transporter,
                $entryType,
                $signedAmount,
                $amount,
                $entryDate,
                $paymentMode,
                $bankCashAccountId,
                $referenceNumber,
                $remark,
                $clientKey,
            ): TransporterBookBalanceEntry {
                $this->lockTransporter($company, $transporter->id);

                if ($clientKey !== null) {
                    $existing = TransporterBookBalanceEntry::query()
                        ->where('company_id', $company->id)
                        ->where('idempotency_key', $clientKey)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        return $existing;
                    }
                }

                if (bccomp($signedAmount, '0', 2) === -1) {
                    $this->assertAdjustmentWithinBalance($company, $branch, $transporter->id, $amount, 'amount');
                }

                $sourceId = (string) Str::uuid();
                $idempotencyKey = $clientKey ?? self::SOURCE_MANUAL_TRANSACTION.':'.$sourceId;

                $entry = TransporterBookBalanceEntry::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'transporter_party_id' => $transporter->id,
                    'lorry_hire_id' => null,
                    'transporter_voucher_id' => null,
                    'amount' => $signedAmount,
                    'entry_type' => $entryType,
                    'entry_date' => $entryDate,
                    'payment_mode' => $paymentMode,
                    'bank_cash_account_id' => $bankCashAccountId,
                    'reference_number' => $referenceNumber,
                    'source_type' => self::SOURCE_MANUAL_TRANSACTION,
                    'source_id' => $sourceId,
                    'idempotency_key' => $idempotencyKey,
                    'remark' => $remark,
                    'created_by_user_id' => $actor->id,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    TransporterBookBalanceEntry::class,
                    $entry->id,
                    remark: $remark ?? $entryType->label().' for '.$transporter->name,
                    before: null,
                    after: $entry->toArray(),
                );

                $this->accounting->postTransporterBookBalanceEntry($entry, $actor);

                return $entry->fresh(['transporter', 'bankCashAccount', 'createdBy']) ?? $entry;
            });
        });
    }

    /**
     * @return LengthAwarePaginator<int, TransporterBookBalanceEntry>
     */
    public function statementForTransporter(
        Company $company,
        Branch $branch,
        string $transporterPartyId,
        int $perPage = 25,
    ): LengthAwarePaginator {
        return TransporterBookBalanceEntry::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('transporter_party_id', $transporterPartyId)
            ->whereNull('deleted_at')
            ->with(['lorryHire', 'voucher', 'bankCashAccount', 'createdBy'])
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentEntriesForTransporter(
        Company $company,
        Branch $branch,
        string $transporterPartyId,
        int $limit = 10,
    ): array {
        $entries = TransporterBookBalanceEntry::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('transporter_party_id', $transporterPartyId)
            ->whereNull('deleted_at')
            ->with(['lorryHire', 'voucher', 'bankCashAccount'])
            ->orderByRaw('COALESCE(entry_date, CAST(created_at AS DATE)) DESC')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min(25, $limit)))
            ->get();

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->formatStatementRow($entry, null);
        }

        return $rows;
    }

    /**
     * Chronological ledger statement with Opening Balance, Debit, Credit, Running Balance, Closing Balance.
     *
     * Accounting convention aligns with the "Transporter Advances" Asset ledger:
     * Debit (+) = Advance / Opening / Positive addition;
     * Credit (-) = Adjust in Advance / Refund / Recovery / Negative deduction.
     *
     * @return array{
     *     transporter: array{id: string, name: string, short_name: string|null, contact_number: string|null, city: string|null, state: string|null},
     *     from_date: string|null,
     *     to_date: string|null,
     *     opening_balance: numeric-string,
     *     total_debit: numeric-string,
     *     total_credit: numeric-string,
     *     closing_balance: numeric-string,
     *     current_balance: numeric-string,
     *     available_balance: numeric-string,
     *     entries: list<array{
     *         id: string,
     *         transaction_date: string,
     *         entry_date_iso: string,
     *         entry_type: string,
     *         transaction_type: string,
     *         particulars: string,
     *         reference_number: string|null,
     *         payment_mode: string|null,
     *         bank_cash_account_name: string|null,
     *         hire_number: string|null,
     *         voucher_number: string|null,
     *         debit: numeric-string,
     *         credit: numeric-string,
     *         amount: numeric-string,
     *         running_balance: numeric-string,
     *         remark: string|null,
     *     }>,
     * }
     */
    public function statementReport(
        Company $company,
        Branch $branch,
        string $transporterPartyId,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        $this->ensureTenantMatches($company, $branch);

        $transporter = $this->resolveEligibleTransporter($company, $branch, $transporterPartyId, requireActiveRole: false);
        $from = filled($fromDate) ? CarbonImmutable::parse((string) $fromDate)->toDateString() : null;
        $to = filled($toDate) ? CarbonImmutable::parse((string) $toDate)->toDateString() : null;

        $baseQuery = TransporterBookBalanceEntry::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('transporter_party_id', $transporter->id)
            ->whereNull('deleted_at');

        $openingBalance = '0.00';
        if ($from !== null) {
            $priorSum = (clone $baseQuery)
                ->whereRaw('COALESCE(entry_date, CAST(created_at AS DATE)) < ?', [$from])
                ->sum('amount');
            $openingBalance = $this->signedMoney($priorSum, 'amount');
        }

        $entriesQuery = (clone $baseQuery)
            ->with(['lorryHire', 'voucher', 'bankCashAccount'])
            ->when($from !== null, fn ($query) => $query->whereRaw('COALESCE(entry_date, CAST(created_at AS DATE)) >= ?', [$from]))
            ->when($to !== null, fn ($query) => $query->whereRaw('COALESCE(entry_date, CAST(created_at AS DATE)) <= ?', [$to]))
            ->orderByRaw('COALESCE(entry_date, CAST(created_at AS DATE)) ASC')
            ->orderBy('created_at')
            ->orderBy('id');

        $running = $openingBalance;
        $totalDebit = '0.00';
        $totalCredit = '0.00';
        $rows = [];

        foreach ($entriesQuery->get() as $entry) {
            $signed = $this->signedMoney($entry->amount, 'amount');
            $isCredit = bccomp($signed, '0', 2) === -1;
            $abs = $isCredit ? bcmul($signed, '-1', 2) : $signed;

            $debit = $isCredit ? '0.00' : $abs;
            $credit = $isCredit ? $abs : '0.00';

            $totalDebit = bcadd($totalDebit, $debit, 2);
            $totalCredit = bcadd($totalCredit, $credit, 2);
            $running = bcadd($running, $signed, 2);

            $rows[] = $this->formatStatementRow($entry, $running, $debit, $credit, $signed);
        }

        $currentBalance = $this->runningBalanceForTransporter($company, $branch, $transporter->id);
        $availableBalance = bccomp($currentBalance, '0', 2) === 1 ? $currentBalance : '0.00';

        return [
            'transporter' => [
                'id' => $transporter->id,
                'name' => $transporter->name,
                'short_name' => $transporter->short_name,
                'contact_number' => $transporter->contact_number,
                'city' => $transporter->city,
                'state' => $transporter->state,
            ],
            'from_date' => $from,
            'to_date' => $to,
            'opening_balance' => $openingBalance,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => $running,
            'current_balance' => $currentBalance,
            'available_balance' => $availableBalance,
            'entries' => $rows,
        ];
    }

    /**
     * Batched transporter book balances for register/summary tables (avoids N+1).
     *
     * @param  list<string>  $transporterPartyIds
     * @return array<string, numeric-string>
     */
    public function balancesForTransporters(
        Company $company,
        Branch $branch,
        array $transporterPartyIds,
    ): array {
        $ids = array_values(array_unique(array_filter($transporterPartyIds, static fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }

        /** @var array<string, numeric-string> $map */
        $map = array_fill_keys($ids, '0.00');

        $rows = DB::table('transporter_book_balance_entries')
            ->selectRaw('transporter_party_id, COALESCE(SUM(amount), 0) AS total_balance')
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->whereIn('transporter_party_id', $ids)
            ->groupBy('transporter_party_id')
            ->get();

        foreach ($rows as $row) {
            $id = (string) $row->transporter_party_id;
            $raw = $row->total_balance ?? '0';
            $map[$id] = is_numeric($raw) ? number_format((float) $raw, 2, '.', '') : '0.00';
        }

        return $map;
    }

    /**
     * Summary rows for active branch-visible Transporters on the Book Balance register.
     *
     * @return list<array{
     *     id: string,
     *     name: string,
     *     short_name: string|null,
     *     contact_number: string|null,
     *     city: string|null,
     *     state: string|null,
     *     book_balance: numeric-string,
     *     available_balance: numeric-string,
     *     last_entry_date: string|null,
     * }>
     */
    public function transporterDirectorySummaries(
        Company $company,
        Branch $branch,
        ?string $query = null,
    ): array {
        $this->ensureTenantMatches($company, $branch);

        $builder = Party::query()
            ->visibleInBranch($company->id, $branch->id)
            ->whereNull('deleted_at')
            ->whereHas('roles', function ($roleQuery): void {
                $roleQuery->where('role', PartyRoleType::Transporter->value)
                    ->whereNull('deleted_at')
                    ->whereNull('deactivated_at');
            });

        $transporters = $this->partySearch
            ->apply($builder, $query ?? '')
            ->limit(250)
            ->get(['id', 'name', 'short_name', 'contact_number', 'city', 'state']);

        $ids = array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            $transporters->pluck('id')->all(),
        ));
        $balances = $this->balancesForTransporters($company, $branch, $ids);

        $lastDates = [];
        if ($ids !== []) {
            $dateRows = DB::table('transporter_book_balance_entries')
                ->selectRaw('transporter_party_id, MAX(COALESCE(entry_date, CAST(created_at AS DATE))) AS last_date')
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->whereNull('deleted_at')
                ->whereIn('transporter_party_id', $ids)
                ->groupBy('transporter_party_id')
                ->get();

            foreach ($dateRows as $row) {
                if (filled($row->last_date)) {
                    $lastDates[(string) $row->transporter_party_id] = CarbonImmutable::parse((string) $row->last_date)->format('d-m-Y');
                }
            }
        }

        $summaries = [];
        foreach ($transporters as $party) {
            $balance = $balances[$party->id] ?? '0.00';
            $available = bccomp($balance, '0', 2) === 1 ? $balance : '0.00';
            $summaries[] = [
                'id' => $party->id,
                'name' => $party->name,
                'short_name' => $party->short_name,
                'contact_number' => $party->contact_number,
                'city' => $party->city,
                'state' => $party->state,
                'book_balance' => $balance,
                'available_balance' => $available,
                'last_entry_date' => $lastDates[$party->id] ?? null,
            ];
        }

        usort($summaries, static function (array $left, array $right): int {
            $leftHas = bccomp($left['book_balance'], '0', 2) !== 0 ? 1 : 0;
            $rightHas = bccomp($right['book_balance'], '0', 2) !== 0 ? 1 : 0;
            if ($leftHas !== $rightHas) {
                return $rightHas <=> $leftHas;
            }

            $balanceCompare = bccomp($right['book_balance'], $left['book_balance'], 2);
            if ($balanceCompare !== 0) {
                return $balanceCompare;
            }

            return strcasecmp($left['name'], $right['name']);
        });

        return $summaries;
    }

    /**
     * @return LengthAwarePaginator<int, TransporterBookBalanceEntry>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $transporterPartyId = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $builder = TransporterBookBalanceEntry::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['lorryHire', 'voucher', 'bankCashAccount'])
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at');

        if (filled($transporterPartyId)) {
            $builder->where('transporter_party_id', $transporterPartyId);
        }

        return $builder->paginate($perPage);
    }

    /**
     * @return numeric-string
     */
    public function runningBalanceForTransporter(Company $company, Branch $branch, string $transporterPartyId): string
    {
        $total = '0.00';
        $rows = TransporterBookBalanceEntry::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('transporter_party_id', $transporterPartyId)
            ->whereNull('deleted_at')
            ->orderByRaw('COALESCE(entry_date, CAST(created_at AS DATE)) ASC')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['amount']);

        foreach ($rows as $row) {
            $total = bcadd($total, $this->signedMoney($row->amount, 'amount'), 2);
        }

        return $total;
    }

    /**
     * Book Balance an Adjust in Advance voucher or recovery may consume; never negative.
     *
     * @return numeric-string
     */
    public function availableBalance(Company $company, Branch $branch, string $transporterPartyId): string
    {
        $running = $this->runningBalanceForTransporter($company, $branch, $transporterPartyId);

        return bccomp($running, '0', 2) === 1 ? $running : '0.00';
    }

    /**
     * Serializes Book Balance consumption for a transporter until the surrounding transaction ends.
     */
    public function lockTransporter(Company $company, string $transporterPartyId): void
    {
        Party::query()
            ->where('company_id', $company->id)
            ->whereKey($transporterPartyId)
            ->lockForUpdate()
            ->first(['id']);
    }

    /**
     * Rejects an adjustment above the available Book Balance. Call inside the transaction after lockTransporter().
     *
     * @param  numeric-string  $amount
     */
    public function assertAdjustmentWithinBalance(
        Company $company,
        Branch $branch,
        string $transporterPartyId,
        string $amount,
        string $field = 'amount',
    ): void {
        $available = $this->availableBalance($company, $branch, $transporterPartyId);
        if (bccomp($amount, $available, 2) === 1) {
            throw ValidationException::withMessages([
                $field => self::exceedsBalanceMessage('Adjustment amount', $available),
            ]);
        }
    }

    /**
     * @param  numeric-string  $available
     */
    public static function exceedsBalanceMessage(string $subject, string $available): string
    {
        return $subject.' cannot exceed the available Book Balance of '.Money::inr($available).'.';
    }

    public function assertCanViewBookBalance(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_BOOK_BALANCE_VIEW, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to view transporter book balance.');
        }
    }

    public function assertCanManageBookBalance(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_BOOK_BALANCE_MANAGE, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to record transporter book balance transactions.');
        }
    }

    public function defaultEntryDate(Company $company): string
    {
        $timezone = $company->timezone ?? null;
        $tz = is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Kolkata';

        return CarbonImmutable::now($tz)->toDateString();
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

    private function resolveEligibleTransporter(
        Company $company,
        Branch $branch,
        string $transporterPartyId,
        bool $requireActiveRole = true,
    ): Party {
        $query = Party::query()
            ->visibleInBranch($company->id, $branch->id)
            ->whereKey($transporterPartyId)
            ->whereNull('deleted_at');

        if ($requireActiveRole) {
            $query->whereHas('roles', function ($roleQuery): void {
                $roleQuery->where('role', PartyRoleType::Transporter->value)
                    ->whereNull('deleted_at')
                    ->whereNull('deactivated_at');
            });
        } else {
            $query->whereHas('roles', function ($roleQuery): void {
                $roleQuery->where('role', PartyRoleType::Transporter->value)
                    ->whereNull('deleted_at');
            });
        }

        $party = $query->first();
        if ($party === null) {
            throw ValidationException::withMessages([
                'transporter_party_id' => 'Transporter must be an active Transporter party visible in this branch.',
            ]);
        }

        return $party;
    }

    /**
     * @param  numeric-string  $amount
     * @return array{0: BookBalanceEntryType, 1: numeric-string}
     */
    private function resolveAdjustmentEntry(string $direction, string $amount): array
    {
        return match ($direction) {
            'add' => [BookBalanceEntryType::BalanceAdjustment, $amount],
            'deduct' => [BookBalanceEntryType::BalanceAdjustment, bcmul($amount, '-1', 2)],
            default => throw ValidationException::withMessages([
                'adjustment_direction' => 'Select whether the balance adjustment adds to or deducts from Book Balance.',
            ]),
        };
    }

    private function validatedEntryDate(Company $company, mixed $rawDate): string
    {
        if (! filled($rawDate)) {
            throw ValidationException::withMessages([
                'entry_date' => 'Transaction date is required.',
            ]);
        }

        try {
            $date = CarbonImmutable::parse((string) $rawDate)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'entry_date' => 'Enter a valid transaction date.',
            ]);
        }

        $today = $this->defaultEntryDate($company);
        if ($date > $today) {
            throw ValidationException::withMessages([
                'entry_date' => 'Transaction date cannot be in the future.',
            ]);
        }

        return $date;
    }

    private function resolveBankAccountId(Company $company, Branch $branch, ?string $bankCashAccountId): string
    {
        if ($bankCashAccountId === null || $bankCashAccountId === '' || ! Str::isUuid($bankCashAccountId)) {
            throw ValidationException::withMessages([
                'bank_cash_account_id' => 'Select a bank account.',
            ]);
        }

        $account = BankCashAccount::query()
            ->where('company_id', $company->id)
            ->where(function ($query) use ($branch): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->whereKey($bankCashAccountId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first(['id']);

        if ($account === null) {
            throw ValidationException::withMessages([
                'bank_cash_account_id' => 'Selected bank account is not active in this branch.',
            ]);
        }

        return $account->id;
    }

    /**
     * @param  numeric-string|null  $running
     * @param  numeric-string|null  $debit
     * @param  numeric-string|null  $credit
     * @param  numeric-string|null  $signed
     * @return array{
     *     id: string,
     *     transaction_date: string,
     *     entry_date_iso: string,
     *     entry_type: string,
     *     transaction_type: string,
     *     particulars: string,
     *     reference_number: string|null,
     *     payment_mode: string|null,
     *     bank_cash_account_name: string|null,
     *     hire_number: string|null,
     *     voucher_number: string|null,
     *     debit: numeric-string,
     *     credit: numeric-string,
     *     amount: numeric-string,
     *     running_balance: numeric-string,
     *     remark: string|null,
     * }
     */
    private function formatStatementRow(
        TransporterBookBalanceEntry $entry,
        ?string $running,
        ?string $debit = null,
        ?string $credit = null,
        ?string $signed = null,
    ): array {
        $signedAmount = $signed ?? $this->signedMoney($entry->amount, 'amount');
        $isCredit = bccomp($signedAmount, '0', 2) === -1;
        $abs = $isCredit ? bcmul($signedAmount, '-1', 2) : $signedAmount;
        $debitAmount = $debit ?? ($isCredit ? '0.00' : $abs);
        $creditAmount = $credit ?? ($isCredit ? $abs : '0.00');

        $date = $entry->entry_date ?? $entry->created_at;
        $paymentModeLabel = match ($entry->payment_mode) {
            'bank' => $entry->bankCashAccount?->name ? 'Bank ('.$entry->bankCashAccount->name.')' : 'Bank',
            'cash' => 'Cash',
            'adjust_in_advance' => 'Adjust in Advance',
            'paid_to_pump' => 'Paid to Pump',
            default => null,
        };

        $particularsParts = [];
        if (filled($entry->remark)) {
            $particularsParts[] = (string) $entry->remark;
        } elseif ($entry->lorryHire !== null) {
            $particularsParts[] = 'Lorry Hire '.$entry->lorryHire->hire_number;
        } else {
            $particularsParts[] = $entry->entry_type->label();
        }
        if ($paymentModeLabel !== null && ! str_contains(strtolower($particularsParts[0]), strtolower($paymentModeLabel))) {
            $particularsParts[] = $paymentModeLabel;
        }

        $reference = $entry->reference_number;
        if (! filled($reference) && $entry->voucher !== null) {
            $reference = $entry->voucher->voucher_number;
        }
        if (! filled($reference) && $entry->lorryHire !== null) {
            $reference = $entry->lorryHire->hire_number;
        }

        return [
            'id' => $entry->id,
            'transaction_date' => $date !== null ? $date->format('d-m-Y') : '—',
            'entry_date_iso' => $date !== null ? $date->toDateString() : '',
            'entry_type' => $entry->entry_type->value,
            'transaction_type' => $entry->entry_type->label(),
            'particulars' => implode(' · ', $particularsParts),
            'reference_number' => filled($reference) ? (string) $reference : null,
            'payment_mode' => $entry->payment_mode,
            'bank_cash_account_name' => $entry->bankCashAccount?->name,
            'hire_number' => $entry->lorryHire?->hire_number,
            'voucher_number' => $entry->voucher?->voucher_number,
            'debit' => $debitAmount,
            'credit' => $creditAmount,
            'amount' => $signedAmount,
            'running_balance' => $running ?? $signedAmount,
            'remark' => $entry->remark,
        ];
    }

    /**
     * @return list<array{id: string, name: string, account_kind: string}>
     */
    public function activeBankAccounts(Company $company, Branch $branch): array
    {
        $rows = BankCashAccount::query()
            ->where('company_id', $company->id)
            ->where(function ($query) use ($branch): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'account_kind']);

        $accounts = [];
        foreach ($rows as $account) {
            $accounts[] = [
                'id' => (string) $account->id,
                'name' => (string) $account->name,
                'account_kind' => (string) $account->account_kind,
            ];
        }

        return $accounts;
    }

    /**
     * @return numeric-string
     */
    private function positiveAmount(mixed $value, string $field): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages([$field => 'Enter the transaction amount.']);
        }

        $formatted = number_format((float) $value, 2, '.', '');
        if (bccomp($formatted, '0', 2) !== 1) {
            throw ValidationException::withMessages([$field => 'Amount must be greater than zero.']);
        }

        return $formatted;
    }

    /**
     * @return numeric-string
     */
    private function signedMoney(mixed $value, string $field): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([$field => 'A valid signed amount is required.']);
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
