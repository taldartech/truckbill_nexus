<?php

namespace App\Domains\Invitation;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Identity\Enums\IdentityType;
use App\Domains\Identity\IdentityLifecycleService;
use App\Domains\Invitation\Enums\InvitationStatus;
use App\Domains\Invitation\Exceptions\InvitationException;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Outbox\OutboxWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use InvalidArgumentException;

/**
 * Customer invitation lifecycle (P2-WS-5 / PO-P2-INVITE).
 *
 * Super Admin creates/revokes. Acceptance is public via single-use token.
 * Existing Customer identities are reused without password overwrite.
 */
final class InvitationService
{
    public const OUTBOX_EVENT_TYPE = 'invitation.email';

    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
        private readonly OutboxWriter $outboxWriter = new OutboxWriter,
        private readonly CompanyAccessService $access = new CompanyAccessService,
        private readonly IdentityLifecycleService $identities = new IdentityLifecycleService,
    ) {}

    /**
     * @return array{invitation: Invitation, plain_token: string}
     */
    public function create(User $actor, Company $company, string $email, ?Branch $branch = null): array
    {
        $this->assertSuperAdmin($actor);

        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid invitation email is required.');
        }

        if (! $company->isActive()) {
            throw new InvitationException('Cannot invite users to an inactive company.');
        }

        if ($branch !== null) {
            if ($branch->company_id !== $company->id || ! $branch->isActive()) {
                throw new InvitationException('Branch must belong to the invitation company and be active.');
            }
        }

        $existing = User::withTrashed()->where('email', $email)->first();
        if ($existing !== null && $existing->isInternal()) {
            throw new InvitationException('Internal identities cannot be invited as Customer users.');
        }

        $bytes = max(32, (int) config('invitations.token_bytes', 32));
        $plainToken = bin2hex(random_bytes($bytes));
        $tokenHash = $this->hashToken($plainToken);
        $expiresHours = max(1, (int) config('invitations.expires_hours', 168));

        $invitation = $this->auditedTransaction->run(function () use ($actor, $company, $branch, $email, $tokenHash, $expiresHours, $plainToken): Invitation {
            return $this->withCompanyContext($company, function () use ($actor, $company, $branch, $email, $tokenHash, $expiresHours, $plainToken): Invitation {
                $invitation = new Invitation;
                $invitation->forceFill([
                    'company_id' => $company->id,
                    'branch_id' => $branch?->id,
                    'email' => $email,
                    'token_hash' => $tokenHash,
                    'status' => InvitationStatus::Pending,
                    'invited_by_user_id' => $actor->id,
                    'expires_at' => now()->addHours($expiresHours),
                ]);
                $invitation->save();

                $this->auditWriter->write(
                    AuditAction::Created,
                    Invitation::class,
                    $invitation->id,
                    remark: null,
                    before: null,
                    after: $this->auditableSnapshot($invitation),
                );

                $this->outboxWriter->enqueue(
                    eventType: self::OUTBOX_EVENT_TYPE,
                    payload: [
                        'invitation_id' => $invitation->id,
                        'email' => $email,
                        'company_id' => $company->id,
                        'accept_url' => url('/invitations/accept/'.$plainToken),
                    ],
                    // event_id is UUID-typed; invitation id is the stable idempotency key.
                    eventId: $invitation->id,
                );

                return $invitation->refresh();
            });
        });

        return [
            'invitation' => $invitation,
            'plain_token' => $plainToken,
        ];
    }

    public function revoke(User $actor, Invitation $invitation, string $remark): Invitation
    {
        $this->assertSuperAdmin($actor);

        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required when revoking an invitation.');
        }

        $company = Company::query()->findOrFail($invitation->company_id);

        return $this->auditedTransaction->run(function () use ($invitation, $remark, $company): Invitation {
            return $this->withCompanyContext($company, function () use ($invitation, $remark): Invitation {
                /** @var Invitation $locked */
                $locked = Invitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== InvitationStatus::Pending) {
                    throw new InvitationException('Only pending invitations can be revoked.');
                }

                $before = $this->auditableSnapshot($locked);

                $locked->forceFill([
                    'status' => InvitationStatus::Revoked,
                    'revoked_at' => now(),
                ])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Invitation::class,
                    $locked->id,
                    remark: $remark,
                    before: $before,
                    after: $this->auditableSnapshot($locked->refresh()),
                );

                return $locked->refresh();
            });
        });
    }

    /**
     * @param  array{name?: string, password?: string, password_confirmation?: string}  $credentials
     */
    public function accept(string $plainToken, array $credentials = []): User
    {
        $tokenHash = $this->hashToken($plainToken);

        // Persist expiry in its own transaction so rejection cannot roll back the status.
        $this->persistExpiryIfNeeded($tokenHash);

        return $this->auditedTransaction->run(function () use ($tokenHash, $credentials): User {
            /** @var Invitation|null $invitation */
            $invitation = Invitation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                throw new InvitationException('Invitation is invalid or has already been used.');
            }

            if ($invitation->status === InvitationStatus::Accepted) {
                throw new InvitationException('Invitation has already been accepted.');
            }

            if ($invitation->status === InvitationStatus::Revoked) {
                throw new InvitationException('Invitation has been revoked.');
            }

            if ($invitation->status === InvitationStatus::Expired || $invitation->isExpired()) {
                throw new InvitationException('Invitation has expired.');
            }

            $company = Company::query()->findOrFail($invitation->company_id);

            return $this->withCompanyContext($company, function () use ($invitation, $credentials, $company): User {
                $before = $this->auditableSnapshot($invitation);
                $user = $this->resolveOrCreateCustomer($invitation, $credentials);

                if ($this->access->activeMembership($user, $company) === null) {
                    $this->attachMembershipWithinContext($company, $user, $invitation);
                }

                if ($invitation->branch_id !== null) {
                    $branch = Branch::query()->findOrFail($invitation->branch_id);
                    $membership = CompanyMembership::query()
                        ->where('company_id', $company->id)
                        ->where('user_id', $user->id)
                        ->whereNull('deactivated_at')
                        ->firstOrFail();

                    $alreadyAssigned = $membership->branchAssignments()
                        ->where('branch_id', $branch->id)
                        ->whereNull('deactivated_at')
                        ->exists();

                    if (! $alreadyAssigned) {
                        $this->attachBranchWithinContext($membership, $branch);
                    }
                }

                $invitation->forceFill([
                    'status' => InvitationStatus::Accepted,
                    'accepted_at' => now(),
                    'accepted_user_id' => $user->id,
                ])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Invitation::class,
                    $invitation->id,
                    remark: 'Invitation accepted',
                    before: $before,
                    after: $this->auditableSnapshot($invitation->refresh()),
                );

                return $user->refresh();
            });
        });
    }

    public function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Atomically mark a pending invitation expired when its expires_at has passed.
     * Safe to call repeatedly — no duplicate status transitions or audits.
     */
    public function persistExpiryIfNeeded(string $tokenHash): void
    {
        $this->auditedTransaction->run(function () use ($tokenHash): void {
            /** @var Invitation|null $invitation */
            $invitation = Invitation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                return;
            }

            if ($invitation->status !== InvitationStatus::Pending || ! $invitation->isExpired()) {
                return;
            }

            $company = Company::query()->findOrFail($invitation->company_id);

            $this->withCompanyContext($company, function () use ($invitation): void {
                $before = $this->auditableSnapshot($invitation);

                $invitation->forceFill([
                    'status' => InvitationStatus::Expired,
                ])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Invitation::class,
                    $invitation->id,
                    remark: 'Invitation expired',
                    before: $before,
                    after: $this->auditableSnapshot($invitation->refresh()),
                );
            });
        });
    }

    public function findPendingByPlainToken(string $plainToken): ?Invitation
    {
        $invitation = Invitation::query()
            ->where('token_hash', $this->hashToken($plainToken))
            ->first();

        if ($invitation === null || ! $invitation->isPending()) {
            return null;
        }

        if ($invitation->isExpired()) {
            return null;
        }

        return $invitation;
    }

    /**
     * @param  array{name?: string, password?: string, password_confirmation?: string}  $credentials
     */
    private function resolveOrCreateCustomer(Invitation $invitation, array $credentials): User
    {
        $existing = User::withTrashed()->where('email', $invitation->email)->first();

        if ($existing !== null) {
            if ($existing->trashed() || $existing->isDeactivated()) {
                throw new InvitationException('Existing identity is not eligible to accept invitations.');
            }

            if (! $existing->isCustomer()) {
                throw new InvitationException('Internal identities cannot be invited as Customer users.');
            }

            return $existing;
        }

        $name = trim((string) ($credentials['name'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        if ($name === '' || $password === '') {
            throw new InvitationException('Name and password are required to create a new Customer identity.');
        }

        if ($password !== (string) ($credentials['password_confirmation'] ?? '')) {
            throw new InvitationException('Password confirmation does not match.');
        }

        return $this->identities->create([
            'name' => $name,
            'email' => $invitation->email,
            'password' => $password,
            'identity_type' => IdentityType::Customer,
        ]);
    }

    private function attachMembershipWithinContext(Company $company, User $user, Invitation $invitation): void
    {
        $passwordBefore = $user->getAuthPassword();

        $membership = new CompanyMembership;
        $membership->forceFill([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'deactivated_at' => null,
        ]);
        $membership->save();

        if ($user->fresh()?->getAuthPassword() !== $passwordBefore) {
            throw new InvitationException('Existing identity password must not be overwritten.');
        }

        $this->auditWriter->write(
            AuditAction::Created,
            CompanyMembership::class,
            $membership->id,
            remark: null,
            before: null,
            after: [
                'id' => $membership->id,
                'company_id' => $membership->company_id,
                'user_id' => $membership->user_id,
                'join_remark' => 'Invitation acceptance',
                'invitation_id' => $invitation->id,
            ],
        );
    }

    private function attachBranchWithinContext(CompanyMembership $membership, Branch $branch): void
    {
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
            remark: 'Invitation acceptance branch assignment',
            before: null,
            after: [
                'id' => $assignment->id,
                'company_id' => $assignment->company_id,
                'company_membership_id' => $assignment->company_membership_id,
                'branch_id' => $assignment->branch_id,
            ],
        );
    }

    private function assertSuperAdmin(User $actor): void
    {
        if (! $actor->isSuperAdmin() || ! $actor->isActive()) {
            throw new InvitationException('Only an active Super Admin may manage invitations.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditableSnapshot(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'company_id' => $invitation->company_id,
            'branch_id' => $invitation->branch_id,
            'email' => $invitation->email,
            'status' => $invitation->status->value,
            'invited_by_user_id' => $invitation->invited_by_user_id,
            'accepted_user_id' => $invitation->accepted_user_id,
            'expires_at' => $invitation->expires_at->toISOString(),
            'accepted_at' => $invitation->accepted_at?->toISOString(),
            'revoked_at' => $invitation->revoked_at?->toISOString(),
        ];
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withCompanyContext(Company $company, callable $callback): mixed
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
