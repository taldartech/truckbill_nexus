<?php

namespace App\Domains\Broker\Voucher;

use App\Domains\Broker\Enums\BookBalanceEntryType;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\TransporterBookBalanceEntry;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transporter book-balance ledger entries (Rules §11 — signed amounts, idempotent source).
 */
final class TransporterBookBalanceService
{
    public const SOURCE_VOUCHER_ADJUST = 'transporter_voucher_adjust';

    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
    ) {}

    /**
     * @param  array{
     *     transporter_party_id: string,
     *     lorry_hire_id?: string|null,
     *     transporter_voucher_id?: string|null,
     *     amount: numeric-string|float|int,
     *     entry_type: BookBalanceEntryType|string,
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

        return $this->auditedTransaction->run(function () use (
            $company,
            $branch,
            $actor,
            $payload,
            $entryType,
            $amount,
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
                    'entry_date' => now()->toDateString(),
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
            ->with(['lorryHire'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
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
            ->with(['lorryHire'])
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
            ->orderBy('created_at')
            ->get();

        foreach ($rows as $row) {
            $total = bcadd($total, $this->signedMoney($row->amount, 'amount'), 2);
        }

        return $total;
    }

    public function assertCanViewBookBalance(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_BOOK_BALANCE_VIEW, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to view transporter book balance.');
        }
    }

    private function assertBrokerEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Broker)) {
            throw new BrokerDomainException('Broker module is not entitled for this company.');
        }
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
