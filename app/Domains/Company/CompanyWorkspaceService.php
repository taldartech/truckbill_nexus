<?php

namespace App\Domains\Company;

use App\Domains\Company\Enums\BranchGstMode;
use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Geography\GeographyLookupService;
use App\Domains\Master\BankCash\BankCashAccountService;
use App\Domains\Subscription\Lifecycle\SubscriptionTrialService;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use InvalidArgumentException;

/**
 * Company / branch / membership mutations with atomic audit (P2-WS-2).
 *
 * Ownership transfer: OwnershipTransferService (P2-WS-7). Permission Master roles are P2-WS-3.
 */
final class CompanyWorkspaceService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
        private readonly CompanyAccessService $access = new CompanyAccessService,
        private readonly SubscriptionTrialService $trials = new SubscriptionTrialService,
        private readonly CompanyProfileValidator $profiles = new CompanyProfileValidator,
        private readonly BankCashAccountService $bankCash = new BankCashAccountService,
        private readonly MasterDataVisibilityResolver $masterVisibility = new MasterDataVisibilityResolver,
        private readonly GeographyLookupService $geography = new GeographyLookupService,
    ) {}

    /**
     * @param  array<string, mixed>  $profile  PRD §7 fields when $requireCompleteProfile is true
     */
    public function createCompany(
        User $owner,
        string $name,
        ?string $initialBranchName = 'Head Office',
        array $profile = [],
        bool $requireCompleteProfile = false,
    ): Company {
        $this->assertCustomerIdentity($owner);

        if (! $owner->isActive()) {
            throw new CompanyDomainException('Owner identity must be active.');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('Company name is required.');
        }

        $normalized = $this->profiles->normalizeAndValidate(
            $this->defaultProfileFromOwner($owner, $profile),
            $requireCompleteProfile,
        );

        return $this->auditedTransaction->run(function () use ($owner, $name, $initialBranchName, $normalized): Company {
            $company = new Company;
            $company->forceFill([
                'name' => trim($name),
                'owner_user_id' => $owner->id,
                'deactivated_at' => null,
                'pan' => $normalized['pan'] !== '' ? $normalized['pan'] : null,
                'registered_address' => $normalized['registered_address'] !== '' ? $normalized['registered_address'] : null,
                'state' => $normalized['state'] !== '' ? $normalized['state'] : null,
                'state_id' => $normalized['state_id'],
                'city' => $normalized['city'] !== '' ? $normalized['city'] : null,
                'city_id' => $normalized['city_id'],
                'pin' => $normalized['pin'] !== '' ? $normalized['pin'] : null,
                'contact_person' => $normalized['contact_person'] !== '' ? $normalized['contact_person'] : null,
                'mobile' => $normalized['mobile'] !== '' ? $normalized['mobile'] : null,
                'email' => $normalized['email'] !== '' ? $normalized['email'] : null,
                'gst_registration_type' => $normalized['gst_registration_type'],
                'tax_liability' => $normalized['tax_liability'],
                'gstin' => $normalized['gstin'] !== '' && $normalized['gstin'] !== null ? $normalized['gstin'] : null,
                'gst_rate_id' => $normalized['gst_rate_id'],
                'gst_rate_snapshot' => $normalized['gst_rate_snapshot'] ?? null,
                'data_visibility' => $normalized['data_visibility'],
                'timezone' => $normalized['timezone'] ?: 'Asia/Kolkata',
                'logo_file_id' => $normalized['logo_file_id'],
                'signature_file_id' => $normalized['signature_file_id'],
            ]);
            $company->save();

            $membership = new CompanyMembership;
            $membership->forceFill([
                'company_id' => $company->id,
                'user_id' => $owner->id,
                'deactivated_at' => null,
            ]);
            $membership->save();

            $branch = null;
            $this->withCompanyAuditContext($company, true, function () use ($company, $membership, $initialBranchName, &$branch): void {
                $this->auditWriter->write(
                    AuditAction::Created,
                    Company::class,
                    $company->id,
                    remark: null,
                    before: null,
                    after: $this->companySnapshot($company),
                );

                $this->auditWriter->write(
                    AuditAction::Created,
                    CompanyMembership::class,
                    $membership->id,
                    remark: null,
                    before: null,
                    after: $this->membershipSnapshot($membership),
                );

                if ($initialBranchName !== null && $initialBranchName !== '') {
                    $branch = $this->createBranchWithinContext($company, $initialBranchName);
                }
            });

            $this->createOptionalOpeningBankAccount($owner, $company->refresh(), $branch, $normalized);

            // First-company trial only; subsequent companies return null (Rules §15).
            $this->trials->startFirstCompanyTrial($owner, $company->refresh());

            return $company->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function updateCompany(Company $company, string $name, string $remark, array $profile = [], bool $requireCompleteProfile = false): Company
    {
        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for company updates.');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('Company name is required.');
        }

        $normalized = $profile === [] && ! $requireCompleteProfile
            ? null
            : $this->profiles->normalizeAndValidate($profile, $requireCompleteProfile);

        if ($normalized !== null && isset($normalized['data_visibility'])) {
            $nextVisibility = $normalized['data_visibility'] instanceof DataVisibility
                ? $normalized['data_visibility']
                : DataVisibility::from((string) $normalized['data_visibility']);
            $this->masterVisibility->assertVisibilityChangeAllowed($company, $nextVisibility);
        }

        return $this->auditedTransaction->run(function () use ($company, $name, $remark, $normalized): Company {
            return $this->withCompanyAuditContext($company, true, function () use ($company, $name, $remark, $normalized): Company {
                $before = $this->companySnapshot($company);
                $payload = ['name' => trim($name)];
                if ($normalized !== null) {
                    $payload = array_merge($payload, [
                        'pan' => $normalized['pan'] !== '' ? $normalized['pan'] : null,
                        'registered_address' => $normalized['registered_address'] !== '' ? $normalized['registered_address'] : null,
                        'state' => $normalized['state'] !== '' ? $normalized['state'] : null,
                        'state_id' => $normalized['state_id'],
                        'city' => $normalized['city'] !== '' ? $normalized['city'] : null,
                        'city_id' => $normalized['city_id'],
                        'pin' => $normalized['pin'] !== '' ? $normalized['pin'] : null,
                        'contact_person' => $normalized['contact_person'] !== '' ? $normalized['contact_person'] : null,
                        'mobile' => $normalized['mobile'] !== '' ? $normalized['mobile'] : null,
                        'email' => $normalized['email'] !== '' ? $normalized['email'] : null,
                        'gst_registration_type' => $normalized['gst_registration_type'],
                        'tax_liability' => $normalized['tax_liability'],
                        'gstin' => $normalized['gstin'] !== '' && $normalized['gstin'] !== null ? $normalized['gstin'] : null,
                        'gst_rate_id' => $normalized['gst_rate_id'],
                        'gst_rate_snapshot' => $normalized['gst_rate_snapshot'] ?? null,
                        'data_visibility' => $normalized['data_visibility'],
                        'timezone' => $normalized['timezone'] ?: 'Asia/Kolkata',
                        'logo_file_id' => $normalized['logo_file_id'],
                        'signature_file_id' => $normalized['signature_file_id'],
                    ]);
                }
                $company->forceFill($payload)->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Company::class,
                    $company->id,
                    remark: $remark,
                    before: $before,
                    after: $this->companySnapshot($company->refresh()),
                );

                return $company;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createBranch(Company $company, string $name, string $remark, array $attributes = []): Branch
    {
        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required when creating a branch after company setup.');
        }

        return $this->auditedTransaction->run(function () use ($company, $name, $remark, $attributes): Branch {
            return $this->withCompanyAuditContext($company, true, function () use ($company, $name, $remark, $attributes): Branch {
                return $this->createBranchWithinContext($company, $name, $remark, $attributes);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateBranch(Branch $branch, string $name, string $remark, array $attributes = []): Branch
    {
        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for branch updates.');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('Branch name is required.');
        }

        $company = Company::query()->findOrFail($branch->company_id);

        return $this->auditedTransaction->run(function () use ($company, $branch, $name, $remark, $attributes): Branch {
            return $this->withCompanyAuditContext($company, true, function () use ($company, $branch, $name, $remark, $attributes): Branch {
                $before = $this->branchSnapshot($branch);
                $payload = array_merge(
                    $this->normalizeBranchAttributes($company, $attributes, $branch->id),
                    ['name' => trim($name)],
                );
                $branch->forceFill($payload)->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Branch::class,
                    $branch->id,
                    remark: $remark,
                    before: $before,
                    after: $this->branchSnapshot($branch->refresh()),
                );

                return $branch;
            });
        });
    }

    public function addMembership(Company $company, User $user, string $remark): CompanyMembership
    {
        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required when adding a membership.');
        }

        $this->assertCustomerIdentity($user);

        if ($user->getAuthPassword() === '') {
            throw new CompanyDomainException('Existing identity must retain credentials.');
        }

        $passwordBefore = $user->getAuthPassword();

        if ($this->access->activeMembership($user, $company) !== null) {
            throw new CompanyDomainException('Active membership already exists for this user and company.');
        }

        return $this->auditedTransaction->run(function () use ($company, $user, $remark, $passwordBefore): CompanyMembership {
            return $this->withCompanyAuditContext($company, $company->isOwnedBy($user), function () use ($company, $user, $remark, $passwordBefore): CompanyMembership {
                $membership = new CompanyMembership;
                $membership->forceFill([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'deactivated_at' => null,
                ]);
                $membership->save();

                if ($user->fresh()?->getAuthPassword() !== $passwordBefore) {
                    throw new CompanyDomainException('Existing identity password must not be overwritten.');
                }

                $this->auditWriter->write(
                    AuditAction::Created,
                    CompanyMembership::class,
                    $membership->id,
                    remark: null,
                    before: null,
                    after: array_merge($this->membershipSnapshot($membership), [
                        'join_remark' => $remark,
                    ]),
                );

                return $membership->refresh();
            });
        });
    }

    public function assignBranch(CompanyMembership $membership, Branch $branch, string $remark): BranchAssignment
    {
        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for branch assignment.');
        }

        if ($membership->company_id !== $branch->company_id) {
            throw new CompanyDomainException('Cross-company branch assignment is prohibited.');
        }

        if (! $membership->isActive()) {
            throw new CompanyDomainException('Inactive membership cannot receive branch assignments.');
        }

        if (! $branch->isActive()) {
            throw new CompanyDomainException('Inactive branches cannot be assigned.');
        }

        $company = Company::query()->findOrFail($membership->company_id);

        return $this->auditedTransaction->run(function () use ($company, $membership, $branch, $remark): BranchAssignment {
            return $this->withCompanyAuditContext($company, false, function () use ($membership, $branch, $remark): BranchAssignment {
                $assignment = new BranchAssignment;
                $assignment->forceFill([
                    'company_id' => $membership->company_id,
                    'company_membership_id' => $membership->id,
                    'branch_id' => $branch->id,
                    'deactivated_at' => null,
                ]);
                $assignment->save();

                $this->auditWriter->write(
                    AuditAction::Created,
                    BranchAssignment::class,
                    $assignment->id,
                    remark: $remark,
                    before: null,
                    after: $this->assignmentSnapshot($assignment),
                );

                return $assignment->refresh();
            });
        });
    }

    /**
     * Internal Super Admin deactivates a company (PO-P2-DEACT-COMPANY).
     * Does not deactivate the Owner identity.
     */
    public function deactivateCompany(User $actor, Company $company, string $remark): Company
    {
        $this->assertCompanyLifecycleAdmin($actor);

        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for company deactivation.');
        }

        if ($company->isDeactivated()) {
            throw new CompanyDomainException('Company is already deactivated.');
        }

        return $this->auditedTransaction->run(function () use ($company, $remark): Company {
            return $this->withCompanyAuditContext($company, true, function () use ($company, $remark): Company {
                $before = $this->companySnapshot($company);
                $company->forceFill(['deactivated_at' => now()])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Company::class,
                    $company->id,
                    remark: $remark,
                    before: $before,
                    after: $this->companySnapshot($company->refresh()),
                );

                return $company;
            });
        });
    }

    /**
     * Explicit reactivation — not restore/undelete (DEC-003 / PO-P2-DEACT-COMPANY).
     */
    public function reactivateCompany(User $actor, Company $company, string $remark): Company
    {
        $this->assertCompanyLifecycleAdmin($actor);

        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for company reactivation.');
        }

        if (! $company->isDeactivated()) {
            throw new CompanyDomainException('Company is not deactivated.');
        }

        if ($company->trashed()) {
            throw new CompanyDomainException('Soft-deleted companies cannot be reactivated via deactivation lifecycle.');
        }

        return $this->auditedTransaction->run(function () use ($company, $remark): Company {
            return $this->withCompanyAuditContext($company, true, function () use ($company, $remark): Company {
                $before = $this->companySnapshot($company);
                $company->forceFill(['deactivated_at' => null])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Company::class,
                    $company->id,
                    remark: $remark,
                    before: $before,
                    after: $this->companySnapshot($company->refresh()),
                );

                return $company;
            });
        });
    }

    public function deactivateBranch(Branch $branch, string $remark): Branch
    {
        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for branch deactivation.');
        }

        $company = Company::query()->findOrFail($branch->company_id);

        return $this->auditedTransaction->run(function () use ($company, $branch, $remark): Branch {
            return $this->withCompanyAuditContext($company, true, function () use ($branch, $remark): Branch {
                $before = $this->branchSnapshot($branch);
                $branch->forceFill(['deactivated_at' => now()])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Branch::class,
                    $branch->id,
                    remark: $remark,
                    before: $before,
                    after: $this->branchSnapshot($branch->refresh()),
                );

                return $branch;
            });
        });
    }

    public function deactivateMembership(CompanyMembership $membership, string $remark): CompanyMembership
    {
        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for membership deactivation.');
        }

        $company = Company::query()->findOrFail($membership->company_id);

        if ((int) $company->owner_user_id === (int) $membership->user_id) {
            throw new CompanyDomainException(
                'Owner membership cannot be deactivated without ownership transfer (P2-WS-7).'
            );
        }

        return $this->auditedTransaction->run(function () use ($company, $membership, $remark): CompanyMembership {
            return $this->withCompanyAuditContext($company, false, function () use ($membership, $remark): CompanyMembership {
                $before = $this->membershipSnapshot($membership);
                $membership->forceFill(['deactivated_at' => now()])->save();

                $activeAssignments = BranchAssignment::query()
                    ->where('company_membership_id', $membership->id)
                    ->whereNull('deactivated_at')
                    ->get();

                foreach ($activeAssignments as $assignment) {
                    $assignmentBefore = $this->assignmentSnapshot($assignment);
                    $assignment->forceFill(['deactivated_at' => now()])->save();

                    $this->auditWriter->write(
                        AuditAction::Updated,
                        BranchAssignment::class,
                        $assignment->id,
                        remark: $remark,
                        before: $assignmentBefore,
                        after: $this->assignmentSnapshot($assignment->refresh()),
                    );
                }

                $this->auditWriter->write(
                    AuditAction::Updated,
                    CompanyMembership::class,
                    $membership->id,
                    remark: $remark,
                    before: $before,
                    after: $this->membershipSnapshot($membership->refresh()),
                );

                return $membership;
            });
        });
    }

    public function revokeBranchAssignment(BranchAssignment $assignment, string $remark): BranchAssignment
    {
        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for branch assignment revocation.');
        }

        $company = Company::query()->findOrFail($assignment->company_id);

        return $this->auditedTransaction->run(function () use ($company, $assignment, $remark): BranchAssignment {
            return $this->withCompanyAuditContext($company, false, function () use ($assignment, $remark): BranchAssignment {
                $before = $this->assignmentSnapshot($assignment);
                $assignment->forceFill(['deactivated_at' => now()])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    BranchAssignment::class,
                    $assignment->id,
                    remark: $remark,
                    before: $before,
                    after: $this->assignmentSnapshot($assignment->refresh()),
                );

                return $assignment;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createBranchWithinContext(
        Company $company,
        string $name,
        ?string $remark = null,
        array $attributes = [],
    ): Branch {
        $normalized = $this->normalizeBranchAttributes($company, $attributes);

        $branch = new Branch;
        $branch->forceFill(array_merge([
            'company_id' => $company->id,
            'name' => $name,
            'deactivated_at' => null,
            'gst_mode' => BranchGstMode::InheritCompany,
            'is_head_office' => false,
        ], $normalized));
        $branch->save();

        $this->auditWriter->write(
            AuditAction::Created,
            Branch::class,
            $branch->id,
            remark: $remark,
            before: null,
            after: $this->branchSnapshot($branch),
        );

        return $branch;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeBranchAttributes(
        Company $company,
        array $attributes,
        ?string $exceptBranchId = null,
    ): array {
        if ($attributes === []) {
            return [];
        }

        $normalized = [];

        if (array_key_exists('contact_number', $attributes)) {
            $contact = trim((string) ($attributes['contact_number'] ?? ''));
            $normalized['contact_number'] = $contact !== '' ? $contact : null;
            if ($normalized['contact_number'] !== null && ! preg_match('/^\d{10}$/', $normalized['contact_number'])) {
                throw new InvalidArgumentException('Contact number must be a 10-digit mobile number when provided.');
            }
        }

        if (array_key_exists('is_head_office', $attributes)) {
            $normalized['is_head_office'] = (bool) $attributes['is_head_office'];
        }

        foreach (['address', 'pin'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $value = trim((string) ($attributes[$field] ?? ''));
                $normalized[$field] = $value !== '' ? $value : null;
            }
        }

        if (array_key_exists('state_id', $attributes) || array_key_exists('city_id', $attributes)) {
            $resolved = $this->geography->resolveAddressFields(
                filled($attributes['state_id'] ?? null) ? (string) $attributes['state_id'] : null,
                filled($attributes['city_id'] ?? null) ? (string) $attributes['city_id'] : null,
            );
            $normalized['state_id'] = $resolved['state_id'];
            $normalized['city_id'] = $resolved['city_id'];
            $normalized['state'] = $resolved['state'];
            $normalized['city'] = $resolved['city'];
        }

        if (array_key_exists('gst_mode', $attributes)) {
            $mode = $attributes['gst_mode'] instanceof BranchGstMode
                ? $attributes['gst_mode']
                : BranchGstMode::from((string) $attributes['gst_mode']);
            $normalized['gst_mode'] = $mode;

            if ($mode === BranchGstMode::InheritCompany) {
                $normalized['gst_registration_type'] = null;
                $normalized['tax_liability'] = null;
                $normalized['gstin'] = null;
                $normalized['gst_rate_id'] = null;
                $normalized['gst_rate_snapshot'] = null;
            } else {
                $normalized['gst_registration_type'] = $attributes['gst_registration_type'] ?? null;
                $normalized['tax_liability'] = $attributes['tax_liability'] ?? null;
                $gstin = trim((string) ($attributes['gstin'] ?? ''));
                $normalized['gstin'] = $gstin !== '' ? strtoupper($gstin) : null;
                $normalized['gst_rate_id'] = $attributes['gst_rate_id'] ?? null;
                $normalized['gst_rate_snapshot'] = $attributes['gst_rate_snapshot'] ?? null;

                if ($normalized['gst_registration_type'] === null || $normalized['tax_liability'] === null) {
                    throw new InvalidArgumentException('Independent Branch GST requires registration type and GST scheme.');
                }
            }
        }

        if (($normalized['is_head_office'] ?? false) === true) {
            $this->assertNoConflictingHeadOffice($company, $exceptBranchId);
        }

        return $normalized;
    }

    private function assertNoConflictingHeadOffice(Company $company, ?string $exceptBranchId = null): void
    {
        $query = Branch::query()
            ->where('company_id', $company->id)
            ->where('is_head_office', true)
            ->whereNull('deleted_at');

        if ($exceptBranchId !== null) {
            $query->whereKeyNot($exceptBranchId);
        }

        if ($query->lockForUpdate()->exists()) {
            throw new CompanyDomainException(
                'This company already has a Head Office. Clear that designation before assigning another.',
            );
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withCompanyAuditContext(Company $company, bool $ownerScope, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();

        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: null,
            ownerId: (string) $company->owner_user_id,
            isInternal: false,
            isOwnerScope: $ownerScope,
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

    private function assertCustomerIdentity(User $user): void
    {
        if (! $user->isCustomer()) {
            throw new CompanyDomainException('Only Customer identities may own or join companies.');
        }
    }

    /**
     * PRD: Internal Super Admin/Admin may deactivate/reactivate companies.
     * Ordinary Internal Admin PM catalogues are not invented here — Super Admin only.
     */
    private function assertCompanyLifecycleAdmin(User $actor): void
    {
        if (! $actor->isSuperAdmin() || ! $actor->isActive()) {
            throw new CompanyDomainException(
                'Only an active Super Admin may deactivate or reactivate companies.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function defaultProfileFromOwner(User $owner, array $profile): array
    {
        return array_merge([
            'contact_person' => $owner->name,
            'mobile' => $owner->getAttribute('mobile') ?? $owner->getAttribute('phone') ?? '',
            'email' => $owner->email,
            'timezone' => 'Asia/Kolkata',
        ], $profile);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function createOptionalOpeningBankAccount(
        User $owner,
        Company $company,
        ?Branch $branch,
        array $normalized,
    ): void {
        $bankName = (string) ($normalized['bank_name'] ?? '');
        $accountNumber = (string) ($normalized['account_number'] ?? '');
        $ifsc = (string) ($normalized['ifsc'] ?? '');
        if ($bankName === '' || $accountNumber === '' || $ifsc === '') {
            return;
        }

        $visibility = $normalized['data_visibility'] ?? null;
        if ($visibility === DataVisibility::BranchWiseRestricted->value && $branch === null) {
            throw new CompanyDomainException(
                'Branch-wise Restricted companies require an initial branch before creating a bank account on company create.'
            );
        }

        $this->bankCash->create($owner, $company, $branch, [
            'account_kind' => 'bank',
            'name' => $bankName.' — '.$accountNumber,
            'bank_name' => $bankName,
            'account_number' => $accountNumber,
            'ifsc' => $ifsc,
            'account_holder_name' => $normalized['contact_person'] ?: $owner->name,
            'is_active' => true,
        ], 'PRD §7 opening bank account on company create');
    }

    /**
     * @return array<string, mixed>
     */
    private function companySnapshot(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'pan' => $company->pan,
            'gstin' => $company->gstin,
            'gst_registration_type' => $company->gst_registration_type?->value,
            'data_visibility' => $company->data_visibility?->value,
            'owner_user_id' => $company->owner_user_id,
            'deactivated_at' => $company->deactivated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchSnapshot(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'company_id' => $branch->company_id,
            'name' => $branch->name,
            'contact_number' => $branch->contact_number,
            'is_head_office' => (bool) $branch->is_head_office,
            'address' => $branch->address,
            'state' => $branch->state,
            'state_id' => $branch->state_id,
            'city' => $branch->city,
            'city_id' => $branch->city_id,
            'pin' => $branch->pin,
            'gst_mode' => $branch->gst_mode instanceof BranchGstMode
                ? $branch->gst_mode->value
                : (string) ($branch->gst_mode ?? BranchGstMode::InheritCompany->value),
            'gstin' => $branch->gstin,
            'gst_registration_type' => $branch->gst_registration_type?->value,
            'tax_liability' => $branch->tax_liability?->value,
            'gst_rate_id' => $branch->gst_rate_id,
            'deactivated_at' => $branch->deactivated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipSnapshot(CompanyMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'company_id' => $membership->company_id,
            'user_id' => $membership->user_id,
            'deactivated_at' => $membership->deactivated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentSnapshot(BranchAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'company_id' => $assignment->company_id,
            'company_membership_id' => $assignment->company_membership_id,
            'branch_id' => $assignment->branch_id,
            'deactivated_at' => $assignment->deactivated_at?->toISOString(),
        ];
    }
}
