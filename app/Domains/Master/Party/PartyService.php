<?php

namespace App\Domains\Master\Party;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Geography\Concerns\ResolvesAddressGeography;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\Exceptions\PartyDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Domains\Tax\TransporterTdsCalculationService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Database\CaseInsensitiveLike;
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
    use ResolvesAddressGeography;

    public function __construct(
        private readonly CompanyAccessService $access = new CompanyAccessService,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PartyRoleAttributeValidator $roleAttributes = new PartyRoleAttributeValidator,
        private readonly TransporterTdsCalculationService $transporterTds = new TransporterTdsCalculationService,
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

        $attributes = $this->withResolvedGeography($attributes);
        $normalizedRoles = $this->normalizeRolePayloads($roles, $attributes);
        $this->roleAttributes->validatePartyAttributes(
            $attributes,
            array_column($normalizedRoles, 'role'),
        );

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $attributes, $normalizedRoles): Party {
            return $this->withTenant($actor, $company, $branch, function () use ($company, $branch, $attributes, $normalizedRoles): Party {
                $party = Party::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch?->id,
                    'name' => $attributes['name'],
                    'contact_person' => $attributes['contact_person'] ?? null,
                    'contact_number' => $attributes['contact_number'] ?? null,
                    'email' => $attributes['email'] ?? null,
                    'gstin' => isset($attributes['gstin']) && filled($attributes['gstin'])
                        ? strtoupper(trim((string) $attributes['gstin']))
                        : null,
                    'pan' => isset($attributes['pan']) && filled($attributes['pan'])
                        ? strtoupper(trim((string) $attributes['pan']))
                        : null,
                    'is_gst_applicable' => $this->resolveGstApplicableOnCreate(
                        $attributes,
                        array_column($normalizedRoles, 'role'),
                    ),
                    'state' => $attributes['state'] ?? null,
                    'state_id' => $attributes['state_id'] ?? null,
                    'city' => $attributes['city'] ?? null,
                    'city_id' => $attributes['city_id'] ?? null,
                    'address' => $attributes['address'] ?? null,
                ]);

                foreach ($normalizedRoles as $rolePayload) {
                    PartyRole::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'party_id' => $party->id,
                        'role' => $rolePayload['role'],
                        'role_attributes' => $rolePayload['attributes'],
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
     * @param  list<array{role: string, attributes?: array<string, mixed>}>|null  $roles
     */
    public function update(
        User $actor,
        Party $party,
        array $attributes,
        string $remark,
        ?array $roles = null,
    ): Party {
        $company = $party->company()->firstOrFail();
        $branch = $party->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($party->branch_id)->first()
            : null;

        $this->assertCanMutate($actor, $company, $branch ?? $this->resolveActorBranch($actor, $company));

        $attributes = $this->withResolvedGeography($attributes);

        $mergedPartyAttributes = array_merge($party->only([
            'name',
            'contact_person',
            'contact_number',
            'email',
            'gstin',
            'pan',
            'is_gst_applicable',
            'state',
            'state_id',
            'city',
            'city_id',
            'address',
        ]), collect($attributes)->only([
            'name',
            'contact_person',
            'contact_number',
            'email',
            'gstin',
            'pan',
            'is_gst_applicable',
            'state',
            'state_id',
            'city',
            'city_id',
            'address',
        ])->all());

        $normalizedRoles = null;
        if ($roles !== null) {
            $normalizedRoles = $this->normalizeRolePayloads($roles, $mergedPartyAttributes);
            $this->roleAttributes->validatePartyAttributes(
                $mergedPartyAttributes,
                array_column($normalizedRoles, 'role'),
            );
        } else {
            $this->roleAttributes->validatePartyAttributes(
                $mergedPartyAttributes,
                array_values($party->roles()->whereNull('deactivated_at')->pluck('role')->all()),
            );
        }

        return $this->auditedTransaction->run(function () use ($actor, $party, $company, $branch, $attributes, $remark, $normalizedRoles): Party {
            return $this->withTenant($actor, $company, $branch, function () use ($party, $company, $attributes, $remark, $normalizedRoles): Party {
                $before = $party->load('roles')->toArray();
                $fill = collect($attributes)->only([
                    'name',
                    'contact_person',
                    'contact_number',
                    'email',
                    'gstin',
                    'pan',
                    'is_gst_applicable',
                    'state',
                    'state_id',
                    'city',
                    'city_id',
                    'address',
                ])->all();

                if (isset($fill['gstin']) && filled($fill['gstin'])) {
                    $fill['gstin'] = strtoupper(trim((string) $fill['gstin']));
                }
                if (isset($fill['pan']) && filled($fill['pan'])) {
                    $fill['pan'] = strtoupper(trim((string) $fill['pan']));
                }

                $party->fill($fill);
                $party->save();

                if ($normalizedRoles !== null) {
                    $this->applyRoleSync($party, $company, $normalizedRoles, $remark);
                }

                $this->audit->write(
                    AuditAction::Updated,
                    Party::class,
                    $party->id,
                    remark: $remark,
                    before: $before,
                    after: $party->fresh(['roles'])?->toArray(),
                );

                return $party->fresh(['roles']) ?? $party;
            });
        });
    }

    /**
     * Add or update active roles without deactivating omitted roles.
     *
     * @param  list<array{role: string, attributes?: array<string, mixed>}>  $roles
     */
    public function syncRoles(User $actor, Party $party, array $roles, string $remark): Party
    {
        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => 'At least one party role is required.']);
        }

        $company = $party->company()->firstOrFail();
        $branch = $party->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($party->branch_id)->first()
            : null;

        $this->assertCanMutate($actor, $company, $branch ?? $this->resolveActorBranch($actor, $company));

        $partyAttributes = $party->only([
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
        ]);

        $normalizedRoles = $this->normalizeRolePayloads($roles, $partyAttributes);
        $this->roleAttributes->validatePartyAttributes(
            $partyAttributes,
            array_column($normalizedRoles, 'role'),
        );

        return $this->auditedTransaction->run(function () use ($actor, $party, $company, $branch, $normalizedRoles, $remark): Party {
            return $this->withTenant($actor, $company, $branch, function () use ($party, $company, $normalizedRoles, $remark): Party {
                $this->applyRoleSync($party, $company, $normalizedRoles, $remark);

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

        $partyAttributes = $party->only([
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
        ]);

        $normalized = $this->roleAttributes->validateAndNormalize($role, $roleAttributes, $partyAttributes);
        $normalized = $this->applyTransporterTdsRates($role, $normalized, $partyAttributes);
        $this->roleAttributes->validatePartyAttributes(
            $partyAttributes,
            array_values(array_merge(
                $party->roles()->whereNull('deactivated_at')->pluck('role')->all(),
                [$role->value],
            )),
        );

        return $this->auditedTransaction->run(function () use ($actor, $party, $company, $branch, $role, $normalized, $remark): PartyRole {
            return $this->withTenant($actor, $company, $branch, function () use ($party, $company, $role, $normalized, $remark): PartyRole {
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
                    $existing->role_attributes = $normalized;
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
                    'role_attributes' => $normalized,
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
            CaseInsensitiveLike::whereAnyContains($builder, [
                'name',
                'contact_number',
                'email',
                'pan',
                'gstin',
            ], $query);
        }

        if ($role !== null && $role !== '') {
            $builder->whereHas('roles', function ($q) use ($role): void {
                $q->where('role', $role)->whereNull('deactivated_at');
            });
        }

        return $builder->paginate($perPage);
    }

    /**
     * @param  list<array{role: string, attributes: array<string, mixed>}>  $normalizedRoles
     */
    private function applyRoleSync(Party $party, Company $company, array $normalizedRoles, string $remark): void
    {
        foreach ($normalizedRoles as $rolePayload) {
            $existing = PartyRole::query()
                ->where('party_id', $party->id)
                ->where('role', $rolePayload['role'])
                ->first();

            if ($existing !== null && $existing->isActive()) {
                $before = $existing->toArray();
                $existing->role_attributes = $rolePayload['attributes'];
                $existing->save();

                $this->audit->write(
                    AuditAction::Updated,
                    PartyRole::class,
                    $existing->id,
                    remark: $remark,
                    before: $before,
                    after: $existing->fresh()?->toArray(),
                );

                continue;
            }

            if ($existing !== null) {
                $before = $existing->toArray();
                $existing->deactivated_at = null;
                $existing->role_attributes = $rolePayload['attributes'];
                $existing->save();

                $this->audit->write(
                    AuditAction::Updated,
                    PartyRole::class,
                    $existing->id,
                    remark: $remark,
                    before: $before,
                    after: $existing->fresh()?->toArray(),
                );

                continue;
            }

            $created = PartyRole::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'party_id' => $party->id,
                'role' => $rolePayload['role'],
                'role_attributes' => $rolePayload['attributes'],
            ]);

            $this->audit->write(
                AuditAction::Created,
                PartyRole::class,
                $created->id,
                remark: $remark,
                before: null,
                after: $created->toArray(),
            );
        }
    }

    /**
     * @param  list<array{role?: string, attributes?: array<string, mixed>}>  $roles
     * @param  array<string, mixed>  $partyAttributes
     * @return list<array{role: string, attributes: array<string, mixed>}>
     */
    private function normalizeRolePayloads(array $roles, array $partyAttributes): array
    {
        $seen = [];
        $normalized = [];

        foreach ($roles as $rolePayload) {
            $role = $rolePayload['role'] ?? null;
            if (! is_string($role) || PartyRoleType::tryFrom($role) === null) {
                throw ValidationException::withMessages(['roles' => 'Invalid party role.']);
            }

            if (isset($seen[$role])) {
                throw ValidationException::withMessages(['roles' => 'Duplicate party role: '.$role.'.']);
            }
            $seen[$role] = true;

            $roleType = PartyRoleType::from($role);
            $attributes = $this->roleAttributes->validateAndNormalize(
                $roleType,
                $rolePayload['attributes'] ?? [],
                $partyAttributes,
            );
            $attributes = $this->applyTransporterTdsRates($roleType, $attributes, $partyAttributes);

            $normalized[] = [
                'role' => $role,
                'attributes' => $attributes,
            ];
        }

        return $normalized;
    }

    /**
     * Persist last calculated / applicable TDS rates for transporter roles.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $partyAttributes
     * @return array<string, mixed>
     */
    private function applyTransporterTdsRates(
        PartyRoleType $role,
        array $attributes,
        array $partyAttributes,
    ): array {
        if ($role !== PartyRoleType::Transporter) {
            return $attributes;
        }

        $context = $this->transporterTds->buildContextFromTransporter($partyAttributes, $attributes);
        $override = filter_var($attributes['tds_override'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? (bool) ($attributes['tds_override'] ?? false);

        try {
            $result = $this->transporterTds->calculate(
                $context,
                overrideRate: $override && isset($attributes['tds_percentage'])
                    ? (string) $attributes['tds_percentage']
                    : null,
                overrideRemark: $override ? ($attributes['tds_adjustment_remark'] ?? null) : null,
                allowOverride: $override,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (TaxDomainException) {
            // Optional enrichment — reference TDS rules may not be seeded yet.
            return $attributes;
        }

        $attributes['calculated_tds_rate'] = $result->calculatedRate;
        $attributes['applicable_tds_rate'] = $result->applicableRate;

        return $attributes;
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
     * GST applicability is only meaningful for customer-family and vendor roles.
     * Driver/transporter-only creates must not inherit a silent "yes" default.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $roleValues
     */
    private function resolveGstApplicableOnCreate(array $attributes, array $roleValues): bool
    {
        if (array_key_exists('is_gst_applicable', $attributes)) {
            return (bool) $attributes['is_gst_applicable'];
        }

        $roles = array_values(array_unique(array_filter($roleValues, is_string(...))));
        $needsGstRoles = array_intersect($roles, [
            PartyRoleType::Customer->value,
            PartyRoleType::Consignor->value,
            PartyRoleType::Consignee->value,
            PartyRoleType::GeneralVendor->value,
            PartyRoleType::PumpVendor->value,
        ]);

        return $needsGstRoles !== [];
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
