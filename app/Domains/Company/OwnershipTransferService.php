<?php

namespace App\Domains\Company;

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Identity\IdentityLifecycleService;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\InternalRoleAssignment;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use InvalidArgumentException;

/**
 * Atomic company ownership transfer (P2-WS-7 / TB-OWN-001).
 *
 * Initiator: Internal Super Admin or Internal Admin (existing `internal.admin` role).
 * Target: existing active Customer. New Owner sessions invalidated via web_session_version.
 */
final class OwnershipTransferService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
        private readonly WebSessionService $webSessions = new WebSessionService,
        private readonly IdentityLifecycleService $identities = new IdentityLifecycleService,
    ) {}

    public function transfer(User $actor, Company $company, User $newOwner, string $remark): OwnershipTransfer
    {
        $this->assertTransferActor($actor);

        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for ownership transfer.');
        }

        $this->assertEligibleNewOwner($company, $newOwner);

        $previousOwnerId = (int) $company->owner_user_id;
        $previousOwner = User::query()->findOrFail($previousOwnerId);

        return $this->auditedTransaction->run(function () use ($actor, $company, $newOwner, $previousOwner, $remark): OwnershipTransfer {
            /** @var Company $locked */
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            /** @var User $lockedPreviousOwner */
            $lockedPreviousOwner = User::query()->whereKey($previousOwner->id)->lockForUpdate()->firstOrFail();
            /** @var User $lockedNewOwner */
            $lockedNewOwner = User::query()->whereKey($newOwner->id)->lockForUpdate()->firstOrFail();

            $this->assertTransferActor($actor);
            $this->assertEligibleNewOwner($locked, $lockedNewOwner);

            if ((int) $locked->owner_user_id !== (int) $lockedPreviousOwner->id) {
                throw new CompanyDomainException('Company Owner changed during transfer; retry with current Owner.');
            }

            return $this->withCompanyAuditContext($locked, function () use (
                $actor,
                $locked,
                $lockedNewOwner,
                $lockedPreviousOwner,
                $remark,
            ): OwnershipTransfer {
                $beforeCompany = [
                    'id' => $locked->id,
                    'owner_user_id' => $locked->owner_user_id,
                    'name' => $locked->name,
                    'deactivated_at' => $locked->deactivated_at?->toISOString(),
                ];

                $this->revokeCompanyAccessForUser($locked, $lockedPreviousOwner, 'Previous Owner access revoked by ownership transfer');

                // Owner designation replaces ordinary membership for the new Owner.
                $this->revokeOrdinaryMembershipForUser($locked, $lockedNewOwner, 'Ordinary membership replaced by Owner designation');

                Company::withoutEvents(function () use ($locked, $lockedNewOwner): void {
                    $locked->forceFill([
                        'owner_user_id' => $lockedNewOwner->id,
                    ])->save();
                });

                $locked->refresh();

                if (Company::query()->whereKey($locked->id)->where('owner_user_id', $lockedNewOwner->id)->count() !== 1) {
                    throw new CompanyDomainException('Ownership uniqueness invariant failed.');
                }

                $history = new OwnershipTransfer;
                $history->forceFill([
                    'company_id' => $locked->id,
                    'previous_owner_user_id' => $lockedPreviousOwner->id,
                    'new_owner_user_id' => $lockedNewOwner->id,
                    'initiated_by_user_id' => $actor->id,
                    'remark' => trim($remark),
                    'transferred_at' => now(),
                ]);
                $history->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Company::class,
                    $locked->id,
                    remark: trim($remark),
                    before: $beforeCompany,
                    after: [
                        'id' => $locked->id,
                        'owner_user_id' => $locked->owner_user_id,
                        'name' => $locked->name,
                        'deactivated_at' => $locked->deactivated_at?->toISOString(),
                        'ownership_transfer_id' => $history->id,
                    ],
                );

                $this->auditWriter->write(
                    AuditAction::Created,
                    OwnershipTransfer::class,
                    $history->id,
                    remark: null,
                    before: null,
                    after: [
                        'id' => $history->id,
                        'company_id' => $history->company_id,
                        'previous_owner_user_id' => $history->previous_owner_user_id,
                        'new_owner_user_id' => $history->new_owner_user_id,
                        'initiated_by_user_id' => $history->initiated_by_user_id,
                        'transferred_at' => $history->transferred_at->toISOString(),
                    ],
                );

                // Session/identity follow-ups participate in the same DB transaction so a
                // failure rolls back ownership (retry-safe). Session-store destroy remains
                // best-effort and non-transactional, matching WebSessionService elsewhere.
                $this->webSessions->revokeAllSessionsForSecurityEvent(
                    $lockedNewOwner->fresh(),
                    'Ownership transfer; new Owner web sessions revoked',
                );

                $this->softDeletePreviousOwnerIfNoMemberships($lockedPreviousOwner->fresh());

                return $history->refresh();
            });
        });
    }

    private function assertTransferActor(User $actor): void
    {
        if (! $actor->isInternal() || ! $actor->isActive()) {
            throw new CompanyDomainException('Only an active Internal Super Admin or Admin may transfer ownership.');
        }

        if ($actor->isSuperAdmin()) {
            return;
        }

        $isInternalAdmin = InternalRoleAssignment::query()
            ->where('user_id', $actor->id)
            ->whereNull('deactivated_at')
            ->whereHas('role', function ($query): void {
                $query->where('slug', 'internal.admin')
                    ->whereNull('deactivated_at')
                    ->whereNull('deleted_at');
            })
            ->exists();

        if (! $isInternalAdmin) {
            throw new CompanyDomainException('Only an active Internal Super Admin or Admin may transfer ownership.');
        }
    }

    private function assertEligibleNewOwner(Company $company, User $newOwner): void
    {
        if ($newOwner->trashed() || $newOwner->isDeactivated() || ! $newOwner->isActive()) {
            throw new CompanyDomainException('New Owner must be an active Customer identity.');
        }

        if (! $newOwner->isCustomer()) {
            throw new CompanyDomainException('Internal identities cannot become Company Owner.');
        }

        if ((int) $company->owner_user_id === (int) $newOwner->id) {
            throw new CompanyDomainException('Cannot transfer ownership to the current Owner.');
        }
    }

    private function revokeCompanyAccessForUser(Company $company, User $user, string $remark): void
    {
        $membership = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->whereNull('deactivated_at')
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            return;
        }

        $this->deactivateMembershipTree($membership, $remark);
    }

    private function revokeOrdinaryMembershipForUser(Company $company, User $user, string $remark): void
    {
        $this->revokeCompanyAccessForUser($company, $user, $remark);
    }

    private function deactivateMembershipTree(CompanyMembership $membership, string $remark): void
    {
        $assignments = BranchAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->whereNull('deactivated_at')
            ->lockForUpdate()
            ->get();

        foreach ($assignments as $assignment) {
            $roleAssignments = BranchRoleAssignment::query()
                ->where('branch_assignment_id', $assignment->id)
                ->whereNull('deactivated_at')
                ->lockForUpdate()
                ->get();

            foreach ($roleAssignments as $roleAssignment) {
                $beforeRole = [
                    'id' => $roleAssignment->id,
                    'deactivated_at' => $roleAssignment->deactivated_at?->toISOString(),
                ];
                $roleAssignment->forceFill(['deactivated_at' => now()])->save();
                $this->auditWriter->write(
                    AuditAction::Updated,
                    BranchRoleAssignment::class,
                    $roleAssignment->id,
                    remark: $remark,
                    before: $beforeRole,
                    after: [
                        'id' => $roleAssignment->id,
                        'deactivated_at' => $roleAssignment->fresh()->deactivated_at?->toISOString(),
                    ],
                );
            }

            $beforeAssignment = [
                'id' => $assignment->id,
                'deactivated_at' => $assignment->deactivated_at?->toISOString(),
            ];
            $assignment->forceFill(['deactivated_at' => now()])->save();
            $this->auditWriter->write(
                AuditAction::Updated,
                BranchAssignment::class,
                $assignment->id,
                remark: $remark,
                before: $beforeAssignment,
                after: [
                    'id' => $assignment->id,
                    'deactivated_at' => $assignment->fresh()->deactivated_at?->toISOString(),
                ],
            );
        }

        $beforeMembership = [
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'company_id' => $membership->company_id,
            'deactivated_at' => $membership->deactivated_at?->toISOString(),
        ];
        $membership->forceFill(['deactivated_at' => now()])->save();
        $this->auditWriter->write(
            AuditAction::Updated,
            CompanyMembership::class,
            $membership->id,
            remark: $remark,
            before: $beforeMembership,
            after: [
                'id' => $membership->id,
                'user_id' => $membership->user_id,
                'company_id' => $membership->company_id,
                'deactivated_at' => $membership->fresh()->deactivated_at?->toISOString(),
            ],
        );
    }

    private function softDeletePreviousOwnerIfNoMemberships(User $previousOwner): void
    {
        if ($previousOwner->trashed() || $previousOwner->isDeactivated()) {
            return;
        }

        // Serialize zero-membership decision against concurrent membership/ownership changes.
        CompanyMembership::query()
            ->where('user_id', $previousOwner->id)
            ->whereNull('deactivated_at')
            ->lockForUpdate()
            ->get();

        $remainingMemberships = CompanyMembership::query()
            ->where('user_id', $previousOwner->id)
            ->whereNull('deactivated_at')
            ->exists();

        $stillOwnsCompanies = Company::query()
            ->where('owner_user_id', $previousOwner->id)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->exists();

        if ($remainingMemberships || $stillOwnsCompanies) {
            return;
        }

        // TB-DEL-002: ordinary access revocation / ownership transfer with zero memberships.
        $previous = TenantContextHolder::get();
        TenantContextHolder::inject(new TenantContext(
            companyId: null,
            branchId: null,
            ownerId: null,
            isInternal: true,
            isOwnerScope: false,
        ));

        try {
            $this->identities->softDelete(
                $previousOwner,
                'Soft-deleted after ownership transfer left zero memberships',
            );
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::inject($previous);
            }
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withCompanyAuditContext(Company $company, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();

        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: null,
            ownerId: (string) $company->owner_user_id,
            isInternal: true,
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
