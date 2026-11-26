<?php

namespace App\Domains\Company;

use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Invitation\Enums\InvitationStatus;
use App\Domains\Invitation\Exceptions\InvitationException;
use App\Domains\Invitation\InvitationService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Capacity\CompanyCapacityService;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanySubscription;
use App\Models\Invitation;
use App\Models\InvitationBranchAccessIntent;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditedTransaction;
use InvalidArgumentException;

/**
 * Owner Panel user create: invitations for new identities, direct assign for existing members.
 */
final class OwnerUserProvisioningService
{
    /**
     * @var list<string>
     */
    public const APPROVED_CUSTOMER_ROLE_SLUGS = [
        'customer.admin',
        'customer.operator',
        'customer.viewer',
        'customer.operator_no_freight',
        'customer.viewer_no_freight',
    ];

    public function __construct(
        private readonly CompanyAccessService $access = new CompanyAccessService,
        private readonly CompanyWorkspaceService $workspace = new CompanyWorkspaceService,
        private readonly InvitationService $invitations = new InvitationService,
        private readonly RoleAssignmentService $roles = new RoleAssignmentService,
        private readonly CompanyCapacityService $capacity = new CompanyCapacityService,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
    ) {}

    /**
     * @param  list<array{
     *     company_id: string,
     *     assignments: list<array{branch_id: string, role_id: string}>
     * }>  $companyBlocks
     * @return array{
     *     outcome: string,
     *     invitation_ids: list<string>,
     *     membership_ids: list<string>,
     *     messages: list<string>
     * }
     */
    public function provision(
        User $actor,
        string $email,
        ?string $inviteeName,
        array $companyBlocks,
        string $remark,
    ): array {
        $this->assertOwnerActor($actor);

        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required.');
        }

        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for user access changes.');
        }

        $existing = User::withTrashed()->where('email', $email)->first();
        if ($existing !== null && $existing->isInternal()) {
            throw new CompanyDomainException('Internal Team identities cannot be provisioned as Customer users.');
        }

        $invitationIds = [];
        $membershipIds = [];
        $messages = [];

        foreach ($companyBlocks as $block) {
            $company = $this->resolveOwnedCompany($actor, (string) $block['company_id']);
            /** @var list<array{branch_id: string, role_id: string}> $assignments */
            $assignments = $this->normalizeAssignments($company, $block['assignments'] ?? []);

            $this->assertSeatCapacityForAssignments($company, $existing, $assignments);

            if ($existing !== null && $existing->isActive() && $existing->isCustomer()) {
                $membership = $this->access->activeMembership($existing, $company);
                if ($membership === null) {
                    $membership = $this->workspace->addMembership($company, $existing, $remark);
                }

                $this->applyAssignmentsToMembership($actor, $company, $membership, $assignments, $remark);
                $membershipIds[] = $membership->id;
                $messages[] = 'Access updated for '.$company->name.'.';

                continue;
            }

            $this->assertNoConflictingPendingInvitation($company, $email);

            $firstBranch = Branch::query()->findOrFail($assignments[0]['branch_id']);
            $invitationResult = $this->invitations->create(
                $actor,
                $company,
                $email,
                $firstBranch,
                $inviteeName,
            );

            /** @var Invitation $invitation */
            $invitation = $invitationResult['invitation'];

            foreach ($assignments as $assignment) {
                InvitationBranchAccessIntent::query()->create([
                    'invitation_id' => $invitation->id,
                    'branch_id' => $assignment['branch_id'],
                    'role_id' => $assignment['role_id'],
                ]);
            }

            $invitationIds[] = $invitation->id;
            $messages[] = 'Invitation sent for '.$company->name.'.';
        }

        $outcome = $invitationIds !== [] && $membershipIds === []
            ? 'invited'
            : ($invitationIds === [] ? 'assigned' : 'mixed');

        return [
            'outcome' => $outcome,
            'invitation_ids' => $invitationIds,
            'membership_ids' => $membershipIds,
            'messages' => $messages,
        ];
    }

    /**
     * @return list<array{id: string, name: string, slug: string}>
     */
    public function assignableCustomerRoles(): array
    {
        return Role::query()
            ->where('domain', PermissionDomain::Customer)
            ->whereIn('slug', self::APPROVED_CUSTOMER_ROLE_SLUGS)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     included_seats: int,
     *     seat_limit: int,
     *     used_billable_seats: int,
     *     available_user_capacity: int
     * }
     */
    public function seatCapacitySnapshot(Company $company): array
    {
        [$purchasedBranches, $purchasedSeats] = $this->purchasedCapacity($company);
        $capacity = $this->capacity->evaluate($company, $purchasedBranches, $purchasedSeats);

        return [
            'included_seats' => $capacity['included_seats'],
            'seat_limit' => $capacity['seat_limit'],
            'used_billable_seats' => $capacity['used_billable_seats'],
            'available_user_capacity' => $capacity['available_user_capacity'],
        ];
    }

    private function assertOwnerActor(User $actor): void
    {
        if (! $actor->isCustomer() || ! $actor->isActive()) {
            throw new CompanyDomainException('Only active Customer Owners may provision users.');
        }
    }

    private function resolveOwnedCompany(User $actor, string $companyId): Company
    {
        $company = Company::query()
            ->whereKey($companyId)
            ->where('owner_user_id', $actor->id)
            ->whereNull('deleted_at')
            ->first();

        if ($company === null) {
            throw new CompanyDomainException('You do not own the selected company.');
        }

        if (! $company->isActive()) {
            throw new CompanyDomainException('Cannot provision users for an inactive company.');
        }

        return $company;
    }

    /**
     * @param  list<array{branch_id?: mixed, role_id?: mixed}>  $raw
     * @return list<array{branch_id: string, role_id: string}>
     */
    private function normalizeAssignments(Company $company, array $raw): array
    {
        if ($raw === []) {
            throw new InvalidArgumentException('At least one branch and role assignment is required per company.');
        }

        $seenBranches = [];
        $normalized = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $branchId = (string) ($row['branch_id'] ?? '');
            $roleId = (string) ($row['role_id'] ?? '');

            if ($branchId === '' || $roleId === '') {
                throw new InvalidArgumentException('Each assignment requires a branch and role.');
            }

            if (isset($seenBranches[$branchId])) {
                throw new InvalidArgumentException('Duplicate branch assignments are not allowed.');
            }

            $branch = Branch::query()
                ->whereKey($branchId)
                ->where('company_id', $company->id)
                ->whereNull('deactivated_at')
                ->whereNull('deleted_at')
                ->first();

            if ($branch === null) {
                throw new CompanyDomainException('Branch must be active and belong to the selected company.');
            }

            $role = Role::query()
                ->whereKey($roleId)
                ->where('domain', PermissionDomain::Customer)
                ->whereIn('slug', self::APPROVED_CUSTOMER_ROLE_SLUGS)
                ->whereNull('deactivated_at')
                ->whereNull('deleted_at')
                ->first();

            if ($role === null) {
                throw new CompanyDomainException('Role must be an approved active Customer role.');
            }

            $seenBranches[$branchId] = true;
            $normalized[] = [
                'branch_id' => $branchId,
                'role_id' => $roleId,
            ];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('At least one branch and role assignment is required per company.');
        }

        return $normalized;
    }

    /**
     * @param  list<array{branch_id: string, role_id: string}>  $assignments
     */
    private function assertSeatCapacityForAssignments(Company $company, ?User $existing, array $assignments): void
    {
        $seatsNeeded = 0;
        $membership = null;

        if ($existing !== null && $existing->isActive() && $existing->isCustomer()) {
            $membership = $this->access->activeMembership($existing, $company);
        }

        $alreadyBillable = $membership !== null && $this->membershipConsumesBillableSeat($company, $membership);

        if ($membership === null) {
            $seatsNeeded = count($assignments);
        } elseif (! $alreadyBillable) {
            $seatsNeeded = 1;
        }

        $snapshot = $this->seatCapacitySnapshot($company);

        if ($snapshot['available_user_capacity'] < $seatsNeeded) {
            throw new CompanyDomainException(
                'User seat capacity limit reached ('.$snapshot['used_billable_seats'].'/'.$snapshot['seat_limit'].'). Purchase additional seats before adding users.'
            );
        }
    }

    private function membershipConsumesBillableSeat(Company $company, CompanyMembership $membership): bool
    {
        $ownerBillable = (bool) config('subscription.capacity.owner_is_billable_seat', false);

        if (! $ownerBillable && (int) $membership->user_id === (int) $company->owner_user_id) {
            return false;
        }

        return BranchAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->exists();
    }

    private function assertNoConflictingPendingInvitation(Company $company, string $email): void
    {
        $pending = Invitation::query()
            ->where('company_id', $company->id)
            ->where('email', $email)
            ->where('status', InvitationStatus::Pending)
            ->exists();

        if ($pending) {
            throw new InvitationException('A pending invitation already exists for this email in '.$company->name.'.');
        }
    }

    /**
     * @param  list<array{branch_id: string, role_id: string}>  $assignments
     */
    private function applyAssignmentsToMembership(
        User $actor,
        Company $company,
        CompanyMembership $membership,
        array $assignments,
        string $remark,
    ): void {
        if ((int) $membership->user_id === (int) $company->owner_user_id) {
            throw new CompanyDomainException('Company Owner access is managed automatically.');
        }

        $this->auditedTransaction->run(function () use ($actor, $membership, $assignments, $remark): void {
            foreach ($assignments as $assignment) {
                $branch = Branch::query()->findOrFail($assignment['branch_id']);
                $role = Role::query()->findOrFail($assignment['role_id']);

                $branchAssignment = BranchAssignment::query()
                    ->where('company_membership_id', $membership->id)
                    ->where('branch_id', $branch->id)
                    ->whereNull('deactivated_at')
                    ->whereNull('deleted_at')
                    ->first();

                if ($branchAssignment === null) {
                    $branchAssignment = $this->workspace->assignBranch($membership, $branch, $remark);
                }

                $hasActiveRole = BranchRoleAssignment::query()
                    ->where('company_membership_id', $membership->id)
                    ->where('branch_id', $branch->id)
                    ->whereNull('deactivated_at')
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $hasActiveRole) {
                    try {
                        $this->roles->assignCustomerBranchRole(
                            $actor,
                            $membership,
                            $branch,
                            $branchAssignment,
                            $role,
                            $remark,
                        );
                    } catch (RbacDomainException|RbacAuthorizationException $e) {
                        throw new CompanyDomainException($e->getMessage());
                    }
                }
            }
        });
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function purchasedCapacity(Company $company): array
    {
        $subscription = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderByDesc('activated_at')
            ->first();

        if ($subscription === null) {
            return [0, 0];
        }

        $raw = $subscription->getAttribute('pricing_breakdown');
        $breakdown = is_array($raw) ? $raw : [];
        $branches = (int) ($breakdown['purchased_branches'] ?? $breakdown['extra_branches'] ?? 0);
        $seats = (int) ($breakdown['purchased_seats'] ?? $breakdown['extra_seats'] ?? 0);

        return [max(0, $branches), max(0, $seats)];
    }
}
