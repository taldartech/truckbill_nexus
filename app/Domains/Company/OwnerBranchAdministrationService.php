<?php

namespace App\Domains\Company;

use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Capacity\CompanyCapacityService;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanySubscription;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditedTransaction;
use InvalidArgumentException;

/**
 * Owner Panel branch administration (PRD §3 / Owner Panel IA).
 *
 * Create may assign existing company members + Customer roles.
 * Edit/deactivate do not manage users. Capacity uses Phase 3 CompanyCapacityService.
 */
final class OwnerBranchAdministrationService
{
    public function __construct(
        private readonly CompanyAccessService $access = new CompanyAccessService,
        private readonly CompanyWorkspaceService $workspace = new CompanyWorkspaceService,
        private readonly RoleAssignmentService $roles = new RoleAssignmentService,
        private readonly CompanyCapacityService $capacity = new CompanyCapacityService,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
    ) {}

    /**
     * @param  list<array{user_id: int|string, role_id: string}>  $memberAssignments
     * @param  array<string, mixed>  $attributes
     */
    public function createBranch(
        User $actor,
        Company $company,
        string $name,
        string $remark,
        array $memberAssignments = [],
        array $attributes = [],
    ): Branch {
        $this->assertOwnerMayMutate($actor, $company);

        if (trim($name) === '') {
            throw new InvalidArgumentException('Branch name is required.');
        }

        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required when creating a branch.');
        }

        $this->assertBranchCapacityAvailable($company);

        return $this->auditedTransaction->run(function () use ($actor, $company, $name, $remark, $memberAssignments, $attributes): Branch {
            $branch = $this->workspace->createBranch($company, trim($name), $remark, $attributes);

            foreach ($memberAssignments as $assignment) {
                $this->assignExistingMemberToNewBranch(
                    $actor,
                    $company,
                    $branch,
                    (int) $assignment['user_id'],
                    (string) $assignment['role_id'],
                    $remark,
                );
            }

            return $branch->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateBranch(
        User $actor,
        Branch $branch,
        string $name,
        string $remark,
        array $attributes = [],
    ): Branch {
        $company = Company::query()->findOrFail($branch->company_id);
        $this->assertOwnerMayMutate($actor, $company);

        return $this->workspace->updateBranch($branch, $name, $remark, $attributes);
    }

    public function deactivateBranch(User $actor, Branch $branch, string $remark): Branch
    {
        $company = Company::query()->findOrFail($branch->company_id);
        $this->assertOwnerMayMutate($actor, $company);

        if ($branch->deactivated_at !== null) {
            throw new CompanyDomainException('Branch is already deactivated.');
        }

        $activeCount = Branch::query()
            ->where('company_id', $company->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->count();

        if ($activeCount <= 1) {
            throw new CompanyDomainException('Cannot deactivate the last active branch.');
        }

        return $this->workspace->deactivateBranch($branch, $remark);
    }

    /**
     * @return array{
     *     included_branches: int,
     *     purchased_branches: int,
     *     branch_limit: int,
     *     used_branches: int,
     *     available_branch_slots: int
     * }
     */
    public function capacitySnapshot(Company $company): array
    {
        [$purchasedBranches, $purchasedSeats] = $this->purchasedCapacity($company);
        $capacity = $this->capacity->evaluate($company, $purchasedBranches, $purchasedSeats);

        return [
            'included_branches' => $capacity['included_branches'],
            'purchased_branches' => $capacity['purchased_branches'],
            'branch_limit' => $capacity['branch_limit'],
            'used_branches' => $capacity['used_branches'],
            'available_branch_slots' => $capacity['available_branch_slots'],
        ];
    }

    private function assertBranchCapacityAvailable(Company $company): void
    {
        $snapshot = $this->capacitySnapshot($company);
        if ($snapshot['available_branch_slots'] < 1) {
            throw new CompanyDomainException(
                'Branch capacity limit reached ('.$snapshot['used_branches'].'/'.$snapshot['branch_limit'].'). Purchase additional capacity before creating another branch.'
            );
        }
    }

    private function assignExistingMemberToNewBranch(
        User $actor,
        Company $company,
        Branch $branch,
        int $userId,
        string $roleId,
        string $remark,
    ): void {
        if ((int) $company->owner_user_id === $userId) {
            // Owner already has automatic branch access; skip assignment rows.
            return;
        }

        $membership = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('user_id', $userId)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->first();

        if ($membership === null) {
            throw new CompanyDomainException(
                'Branch creation may only select existing company members (user_id '.$userId.' has no active membership).'
            );
        }

        $member = User::query()->findOrFail($userId);
        if (! $member->isCustomer() || ! $member->isActive()) {
            throw new CompanyDomainException('Only active Customer members may be assigned to a branch.');
        }

        $role = Role::query()
            ->whereKey($roleId)
            ->where('domain', PermissionDomain::Customer->value)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->first();

        if ($role === null) {
            throw new CompanyDomainException('Customer role is invalid or inactive.');
        }

        $existingAssignment = BranchAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deactivated_at')
            ->first();

        $assignment = $existingAssignment ?? $this->workspace->assignBranch(
            $membership,
            $branch,
            $remark.' (branch create assignment)',
        );

        try {
            $this->roles->assignCustomerBranchRole(
                $actor,
                $membership,
                $branch,
                $assignment,
                $role,
                $remark.' (branch create role)',
            );
        } catch (RbacDomainException|RbacAuthorizationException $e) {
            throw new CompanyDomainException($e->getMessage(), previous: $e);
        }
    }

    private function assertOwnerMayMutate(User $actor, Company $company): void
    {
        if (! $actor->isCustomer() || ! $actor->isActive()) {
            throw new CompanyDomainException('Active Customer Owner required.');
        }

        if (! $this->access->isCompanyOwner($actor, $company)) {
            throw new CompanyDomainException('Only the designated Company Owner may manage branches.');
        }

        if (! $company->isActive()) {
            throw new CompanyDomainException('Branch administration writes are unavailable while the company is deactivated.');
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function purchasedCapacity(Company $company): array
    {
        $active = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->with('items')
            ->orderByDesc('activated_at')
            ->first();

        if ($active === null) {
            return [0, 0];
        }

        $raw = $active->getAttribute('pricing_breakdown');
        $breakdown = is_array($raw) ? $raw : [];

        $branches = (int) ($breakdown['purchased_branches'] ?? $breakdown['extra_branches'] ?? 0);
        $seats = (int) ($breakdown['purchased_seats'] ?? $breakdown['extra_seats'] ?? 0);

        return [max(0, $branches), max(0, $seats)];
    }
}
