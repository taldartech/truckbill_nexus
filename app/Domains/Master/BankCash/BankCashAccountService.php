<?php

namespace App\Domains\Master\BankCash;

use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Support\MasterAccess;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BankCashAccountService
{
    public function __construct(
        private readonly MasterAccess $access = new MasterAccess,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, Company $company, ?Branch $branch, array $attributes, string $remark = 'Create bank/cash account'): BankCashAccount
    {
        $this->access->assertCanMutate($actor, $company, $branch);
        $this->assertBranchBelongs($company, $branch);
        $kind = $this->normalizeKind((string) ($attributes['account_kind'] ?? ''));
        $this->validateKindFields($kind, $attributes);

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $attributes, $kind, $remark): BankCashAccount {
            return $this->access->withTenant($actor, $company, $branch, function () use ($company, $branch, $attributes, $kind, $remark): BankCashAccount {
                $account = BankCashAccount::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch?->id,
                    'account_kind' => $kind,
                    'name' => trim((string) $attributes['name']),
                    'bank_name' => $kind === 'bank' ? ($attributes['bank_name'] ?? null) : null,
                    'account_number' => $kind === 'bank' ? ($attributes['account_number'] ?? null) : null,
                    'ifsc' => $kind === 'bank' ? ($attributes['ifsc'] ?? null) : null,
                    'account_holder_name' => $kind === 'bank' ? ($attributes['account_holder_name'] ?? null) : null,
                    'is_active' => $attributes['is_active'] ?? true,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    BankCashAccount::class,
                    $account->id,
                    remark: $remark,
                    before: null,
                    after: $this->auditablePayload($account),
                );

                return $account;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, BankCashAccount $account, array $attributes, string $remark): BankCashAccount
    {
        $company = $account->company()->firstOrFail();
        $branch = $account->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($account->branch_id)->first()
            : null;

        $this->access->assertCanEdit($actor, $company, $branch);

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $account, $attributes, $remark): BankCashAccount {
            return $this->access->withTenant($actor, $company, $branch, function () use ($account, $attributes, $remark): BankCashAccount {
                $before = $this->auditablePayload($account);
                $account->fill(collect($attributes)->only([
                    'name',
                    'bank_name',
                    'account_number',
                    'ifsc',
                    'account_holder_name',
                    'is_active',
                ])->all());
                $account->save();

                $this->audit->write(
                    AuditAction::Updated,
                    BankCashAccount::class,
                    $account->id,
                    remark: $remark,
                    before: $before,
                    after: $this->auditablePayload($account->fresh() ?? $account),
                );

                return $account->fresh() ?? $account;
            });
        });
    }

    public function softDelete(User $actor, BankCashAccount $account, string $remark): void
    {
        $company = $account->company()->firstOrFail();
        $branch = $account->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($account->branch_id)->first()
            : null;

        $this->access->assertCanDelete($actor, $company, $branch);

        $this->auditedTransaction->run(function () use ($actor, $company, $branch, $account, $remark): void {
            $this->access->withTenant($actor, $company, $branch, function () use ($account, $remark): void {
                $before = $this->auditablePayload($account);
                $account->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    BankCashAccount::class,
                    $account->id,
                    remark: $remark,
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @return LengthAwarePaginator<int, BankCashAccount>
     */
    public function search(
        User $actor,
        Company $company,
        Branch $branch,
        ?string $query = null,
        ?string $accountKind = null,
        ?bool $isActive = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->access->assertCanView($actor, $company, $branch);

        $builder = BankCashAccount::query()
            ->visibleInBranch($company->id, $branch->id)
            ->orderBy('name');

        if ($query !== null && trim($query) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($query)).'%';
            $builder->where(function ($inner) use ($term): void {
                $inner->where('name', 'ilike', $term)
                    ->orWhere('bank_name', 'ilike', $term)
                    ->orWhere('account_holder_name', 'ilike', $term);
            });
        }

        if ($accountKind !== null && $accountKind !== '') {
            $builder->where('account_kind', $this->normalizeKind($accountKind));
        }

        if ($isActive !== null) {
            $builder->where('is_active', $isActive);
        }

        return $builder->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(BankCashAccount $account): array
    {
        return [
            'id' => $account->id,
            'company_id' => $account->company_id,
            'branch_id' => $account->branch_id,
            'account_kind' => $account->account_kind,
            'name' => $account->name,
            'bank_name' => $account->bank_name,
            'account_number_masked' => $account->maskedAccountNumber(),
            'ifsc' => $account->ifsc,
            'account_holder_name' => $account->account_holder_name,
            'is_active' => $account->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function validateKindFields(string $kind, array $attributes): void
    {
        if (! isset($attributes['name']) || trim((string) $attributes['name']) === '') {
            throw ValidationException::withMessages(['name' => 'Account name is required.']);
        }

        if ($kind === 'bank' && (! isset($attributes['account_number']) || trim((string) $attributes['account_number']) === '')) {
            throw ValidationException::withMessages(['account_number' => 'Bank account number is required.']);
        }
    }

    private function normalizeKind(string $kind): string
    {
        $normalized = strtolower(trim($kind));
        if (! in_array($normalized, ['bank', 'cash'], true)) {
            throw ValidationException::withMessages(['account_kind' => 'Account kind must be bank or cash.']);
        }

        return $normalized;
    }

    private function assertBranchBelongs(Company $company, ?Branch $branch): void
    {
        if ($branch !== null && $branch->company_id !== $company->id) {
            throw new MasterDomainException('Branch does not belong to company.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditablePayload(BankCashAccount $account): array
    {
        $payload = $account->toArray();
        if (isset($payload['account_number']) && is_string($payload['account_number'])) {
            $payload['account_number'] = $account->maskedAccountNumber();
        }

        return $payload;
    }
}
