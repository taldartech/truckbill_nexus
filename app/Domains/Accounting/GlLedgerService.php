<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Enums\AccountNature;
use App\Models\AccountGroup;
use App\Models\Company;
use App\Models\GlLedger;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GlLedgerService
{
    public function __construct(
        private readonly AuditedTransaction $audited = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly ChartOfAccountsBootstrapService $coa = new ChartOfAccountsBootstrapService,
    ) {}

    /**
     * @param  array{name: string, account_group_id: string, nature?: string|null}  $payload
     */
    public function create(Company $company, User $actor, array $payload): GlLedger
    {
        $this->coa->ensureDefaults($company);

        $name = trim((string) $payload['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Ledger name is required.']);
        }

        $group = AccountGroup::query()
            ->where('company_id', $company->id)
            ->whereKey((string) $payload['account_group_id'])
            ->whereNull('deleted_at')
            ->first();

        if ($group === null) {
            throw ValidationException::withMessages(['account_group_id' => 'Account group was not found.']);
        }

        $exists = GlLedger::query()
            ->where('company_id', $company->id)
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'A ledger with this name already exists.']);
        }

        $nature = filled($payload['nature'] ?? null)
            ? AccountNature::from((string) $payload['nature'])
            : $group->nature;

        return $this->audited->run(function () use ($company, $name, $group, $nature): GlLedger {
            $previous = TenantContextHolder::get();
            TenantContextHolder::inject(new TenantContext(
                companyId: $company->id,
                branchId: null,
                ownerId: (string) $company->owner_user_id,
                isInternal: false,
                isOwnerScope: true,
            ));

            try {
                $ledger = GlLedger::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'account_group_id' => $group->id,
                    'name' => $name,
                    'nature' => $nature,
                    'is_system' => false,
                    'is_active' => true,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    GlLedger::class,
                    $ledger->id,
                    remark: 'Create accounting ledger',
                    before: null,
                    after: ['name' => $ledger->name, 'account_group_id' => $group->id],
                );

                return $ledger;
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::inject($previous);
                }
            }
        });
    }

    /**
     * @return list<array{id: string, name: string, nature: string}>
     */
    public function groupOptions(Company $company): array
    {
        $this->coa->ensureDefaults($company);

        $options = [];
        foreach (AccountGroup::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'nature']) as $group) {
            $options[] = [
                'id' => $group->id,
                'name' => $group->name,
                'nature' => $group->nature->value,
            ];
        }

        return $options;
    }
}
