<?php

namespace App\Domains\Master\Party;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\Exceptions\PartyDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\PartyRole;
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
 * Party master multi-role service (P3-WS-1).
 *
 * Company Data Visibility setting remains Deferred — callers choose shared
 * (branch_id null) vs branch-scoped (branch_id set) explicitly.
 */
final class PartyService
{
    public function __construct(
        private readonly CompanyAccessService $access = new CompanyAccessService,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{role: string, attributes?: array<string, mixed>}>  $roles
     */
    public function create(
        User $actor,
        Company $company,
        ?Branch $branch,
        array $attributes,
        array $roles,
        string $remark = 'Create party',
    ): Party {
        $this->assertCanMutate($actor, $company, $branch);

        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => 'At least one party role is required.']);
        }

        if ($branch !== null && $branch->company_id !== $company->id) {
            throw new PartyDomainException('Branch does not belong to company.');
        }

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $attributes, $roles): Party {
            return $this->withTenant($actor, $company, $branch, function () use ($company, $branch, $attributes, $roles): Party {
                $party = Party::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch?->id,
                    'name' => $attributes['name'],
                    'contact_person' => $attributes['contact_person'] ?? null,
                    'contact_number' => $attributes['contact_number'] ?? null,
                    'email' => $attributes['email'] ?? null,
                    'gstin' => $attributes['gstin'] ?? null,
                    'pan' => $attributes['pan'] ?? null,
                    'is_gst_applicable' => $attributes['is_gst_applicable'] ?? true,
                    'state' => $attributes['state'] ?? null,
                    'city' => $attributes['city'] ?? null,
                    'address' => $attributes['address'] ?? null,
                ]);

                foreach ($roles as $rolePayload) {
                    $this->assertValidRolePayload($rolePayload);
                    PartyRole::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'party_id' => $party->id,
                        'role' => $rolePayload['role'],
                        'role_attributes' => $rolePayload['attributes'] ?? [],
                    ]);
                }

                $this->audit->write(
                    AuditAction::Created,
                    Party::class,
                    $party->id,
                    remark: null,
                    before: null,
                    after: $party->load('roles')->toArray(),
                );

                return $party->fresh(['roles']) ?? $party;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Party $party, array $attributes, string $remark): Party
    {
        $company = $party->company()->firstOrFail();
        $branch = $party->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($party->branch_id)->first()
            : null;

        $this->assertCanMutate($actor, $company, $branch ?? $this->resolveActorBranch($actor, $company));

        return $this->auditedTransaction->run(function () use ($actor, $party, $company, $branch, $attributes, $remark): Party {
            return $this->withTenant($actor, $company, $branch, function () use ($party, $attributes, $remark): Party {
                $before = $party->toArray();
                $party->fill(collect($attributes)->only([
                    'name',
                    'contact_person',
                    'contact_number',
                    'email',
                    'gstin',
                    'pan',
                    'is_gst_applicable',
                    'state',
                    'city',
                    'address',
                ])->all());
                $party->save();

                $this->audit->write(
                    AuditAction::Updated,
                    Party::class,
                    $party->id,
                    remark: $remark,
                    before: $before,
                    after: $party->fresh()?->toArray(),
                );

                return $party->fresh(['roles']) ?? $party;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $roleAttributes
     */
    public function assignRole(
        User $actor,
        Party $party,
        PartyRoleType $role,
        array $roleAttributes,
        string $remark,
    ): PartyRole {
        $company = $party->company()->firstOrFail();
        $branch = $party->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($party->branch_id)->first()
            : null;

        $this->assertCanMutate($actor, $company, $branch ?? $this->resolveActorBranch($actor, $company));

        return $this->auditedTransaction->run(function () use ($actor, $party, $company, $branch, $role, $roleAttributes, $remark): PartyRole {
            return $this->withTenant($actor, $company, $branch, function () use ($party, $company, $role, $roleAttributes, $remark): PartyRole {
                $existing = PartyRole::query()
                    ->where('party_id', $party->id)
                    ->where('role', $role->value)
                    ->first();

                if ($existing !== null && $existing->isActive()) {
                    throw ValidationException::withMessages(['role' => 'Role is already assigned.']);
                }

                if ($existing !== null) {
                    $before = $existing->toArray();
                    $existing->deactivated_at = null;
                    $existing->role_attributes = $roleAttributes;
                    $existing->save();

                    $this->audit->write(
                        AuditAction::Updated,
                        PartyRole::class,
                        $existing->id,
                        remark: $remark,
                        before: $before,
                        after: $existing->fresh()?->toArray(),
                    );

                    return $existing->fresh() ?? $existing;
                }

                $created = PartyRole::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'party_id' => $party->id,
                    'role' => $role->value,
                    'role_attributes' => $roleAttributes,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    PartyRole::class,
                    $created->id,
                    remark: null,
                    before: null,
                    after: $created->toArray(),
                );

                return $created;
            });
        });
    }

    public function removeRole(User $actor, PartyRole $partyRole, string $remark): PartyRole
    {
        $party = $partyRole->party()->firstOrFail();
        $company = $party->company()->firstOrFail();
        $branch = $party->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($party->branch_id)->first()
            : null;

        $this->assertCanMutate($actor, $company, $branch ?? $this->resolveActorBranch($actor, $company));

        $activeCount = PartyRole::query()
            ->where('party_id', $party->id)
            ->whereNull('deactivated_at')
            ->count();

        if ($activeCount <= 1 && $partyRole->isActive()) {
            throw ValidationException::withMessages(['role' => 'Cannot remove the last active party role.']);
        }

        return $this->auditedTransaction->run(function () use ($actor, $partyRole, $company, $branch, $remark): PartyRole {
            return $this->withTenant($actor, $company, $branch, function () use ($partyRole, $remark): PartyRole {
                $before = $partyRole->toArray();
                // Retain role details on removal (Rules §6).
                $partyRole->deactivated_at = now();
                $partyRole->save();

                $this->audit->write(
                    AuditAction::Updated,
                    PartyRole::class,
                    $partyRole->id,
                    remark: $remark,
                    before: $before,
                    after: $partyRole->fresh()?->toArray(),
                );

                return $partyRole->fresh() ?? $partyRole;
            });
        });
    }

    public function softDelete(User $actor, Party $party, string $remark): void
    {
        $company = $party->company()->firstOrFail();
        $branch = $party->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($party->branch_id)->first()
            : null;

        $this->assertCanDelete($actor, $company, $branch ?? $this->resolveActorBranch($actor, $company));

        $this->auditedTransaction->run(function () use ($actor, $party, $company, $branch, $remark): void {
            $this->withTenant($actor, $company, $branch, function () use ($party, $remark): void {
                $before = $party->toArray();
                $party->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    Party::class,
                    $party->id,
                    remark: $remark,
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @return LengthAwarePaginator<int, Party>
     */
    public function search(
        User $actor,
        Company $company,
        Branch $branch,
        ?string $query = null,
        ?string $role = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->assertCanView($actor, $company, $branch);

        $builder = Party::query()
            ->visibleInBranch($company->id, $branch->id)
            ->with(['roles' => fn ($q) => $q->whereNull('deactivated_at')])
            ->orderBy('name');

        if ($query !== null && trim($query) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($query)).'%';
            $builder->where(function ($inner) use ($term): void {
                $inner->where('name', 'ilike', $term)
                    ->orWhere('contact_number', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term)
                    ->orWhere('pan', 'ilike', $term)
                    ->orWhere('gstin', 'ilike', $term);
            });
        }

        if ($role !== null && $role !== '') {
            $builder->whereHas('roles', function ($q) use ($role): void {
                $q->where('role', $role)->whereNull('deactivated_at');
            });
        }

        return $builder->paginate($perPage);
    }

    private function assertCanView(User $actor, Company $company, Branch $branch): void
    {
        $this->assertCompanyBranchAccess($actor, $company, $branch);

        if ($this->access->isCompanyOwner($actor, $company)) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_VIEW, $company, $branch)) {
            throw new PartyDomainException('Not authorized to view parties.');
        }
    }

    private function assertCanMutate(User $actor, Company $company, ?Branch $branch): void
    {
        if ($branch === null) {
            // Shared company-wide party: Owner or member with create on any accessible branch.
            if ($this->access->isCompanyOwner($actor, $company) && $company->isActive()) {
                return;
            }

            $membership = $this->access->activeMembership($actor, $company);
            if ($membership === null || ! $company->isActive()) {
                throw new PartyDomainException('Not authorized to manage parties.');
            }

            $anyBranch = $company->branches()->whereNull('deactivated_at')->get()
                ->first(fn (Branch $b) => $this->authorizer->allowsCustomer(
                    $actor,
                    PermissionCodes::PLATFORM_CREATE,
                    $company,
                    $b,
                ));

            if ($anyBranch === null) {
                throw new PartyDomainException('Not authorized to manage parties.');
            }

            return;
        }

        $this->assertCompanyBranchAccess($actor, $company, $branch);

        if ($this->access->isCompanyOwner($actor, $company)) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_CREATE, $company, $branch)
            && ! $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_EDIT, $company, $branch)) {
            throw new PartyDomainException('Not authorized to manage parties.');
        }
    }

    private function assertCanDelete(User $actor, Company $company, ?Branch $branch): void
    {
        if ($branch === null) {
            if ($this->access->isCompanyOwner($actor, $company) && $company->isActive()) {
                return;
            }

            throw new PartyDomainException('Not authorized to delete parties.');
        }

        $this->assertCompanyBranchAccess($actor, $company, $branch);

        if ($this->access->isCompanyOwner($actor, $company)) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_DELETE, $company, $branch)) {
            throw new PartyDomainException('Not authorized to delete parties.');
        }
    }

    private function assertCompanyBranchAccess(User $actor, Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new PartyDomainException('Branch does not belong to company.');
        }

        if (! $company->isActive()) {
            throw new PartyDomainException('Company is not active.');
        }

        if (! $this->access->isCompanyOwner($actor, $company)
            && $this->access->activeMembership($actor, $company) === null) {
            throw new PartyDomainException('Company access denied.');
        }

        if (! $this->access->canAccessBranch($actor, $company, $branch)) {
            throw new PartyDomainException('Branch access denied.');
        }
    }

    private function resolveActorBranch(User $actor, Company $company): ?Branch
    {
        if ($this->access->isCompanyOwner($actor, $company)) {
            return $company->branches()->whereNull('deactivated_at')->orderBy('name')->first();
        }

        return $company->branches()->whereNull('deactivated_at')->get()
            ->first(fn (Branch $b) => $this->access->canAccessBranch($actor, $company, $b));
    }

    /**
     * @param  array{role?: string, attributes?: array<string, mixed>}  $rolePayload
     */
    private function assertValidRolePayload(array $rolePayload): void
    {
        $role = $rolePayload['role'] ?? null;
        if (! is_string($role) || PartyRoleType::tryFrom($role) === null) {
            throw ValidationException::withMessages(['roles' => 'Invalid party role.']);
        }

        $this->validateRoleAttributes(PartyRoleType::from($role), $rolePayload['attributes'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function validateRoleAttributes(PartyRoleType $role, array $attributes): void
    {
        if ($role === PartyRoleType::Transporter) {
            foreach (['pan', 'pan_status', 'declaration'] as $required) {
                if (! filled($attributes[$required] ?? null)) {
                    throw ValidationException::withMessages([
                        "roles.attributes.{$required}" => "Transporter requires {$required}.",
                    ]);
                }
            }
        }

        if ($role === PartyRoleType::Driver) {
            $type = $attributes['driver_type'] ?? null;
            if (! in_array($type, ['permanent', 'market'], true)) {
                throw ValidationException::withMessages([
                    'roles.attributes.driver_type' => 'Driver type must be permanent or market.',
                ]);
            }
            if ($type === 'permanent' && ! filled($attributes['license_number'] ?? null)) {
                throw ValidationException::withMessages([
                    'roles.attributes.license_number' => 'Permanent drivers require a license number.',
                ]);
            }
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(User $actor, Company $company, ?Branch $branch, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::set(new TenantContext(
            companyId: $company->id,
            branchId: $branch?->id,
            ownerId: (string) $company->owner_user_id,
            isInternal: $actor->isInternal(),
            isOwnerScope: $this->access->isCompanyOwner($actor, $company),
        ));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::set($previous);
            }
        }
    }
}
