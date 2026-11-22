<?php

namespace App\Domains\Identity;

use App\Domains\Auth\WebSessionService;
use App\Domains\Identity\Enums\IdentityType;
use App\Domains\Identity\Exceptions\IdentityTypeBoundaryException;
use App\Domains\Identity\Exceptions\LastSuperAdminProtectedException;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use InvalidArgumentException;

/**
 * Controlled identity create / deactivate / soft-delete helpers for P2-WS-1.
 *
 * Super Admin is a protected system privilege. Ordinary Internal/Customer role
 * definitions and assignments belong to P2-WS-3 (Permission Master).
 * Session revocation on deactivate/soft-delete uses P2-WS-4 WebSessionService.
 */
final class IdentityLifecycleService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
        private readonly WebSessionService $webSessions = new WebSessionService,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, identity_type: IdentityType, is_super_admin?: bool}  $attributes
     */
    public function create(array $attributes): User
    {
        $type = $attributes['identity_type'];
        $isSuperAdmin = (bool) ($attributes['is_super_admin'] ?? false);

        $this->assertSuperAdminConsistency($type, $isSuperAdmin);

        return $this->auditedTransaction->run(function () use ($attributes, $type, $isSuperAdmin): User {
            $user = new User;
            $user->forceFill([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'identity_type' => $type,
                'is_super_admin' => $isSuperAdmin,
                'deactivated_at' => null,
            ]);
            $user->save();

            $this->auditWriter->write(
                AuditAction::Created,
                User::class,
                $user->id,
                remark: null,
                before: null,
                after: $this->auditableSnapshot($user),
            );

            return $user->refresh();
        });
    }

    public function deactivate(User $user, string $remark): User
    {
        if ($user->isDeactivated()) {
            throw new InvalidArgumentException('Identity is already deactivated.');
        }

        if ($user->isLastActiveSuperAdmin()) {
            throw new LastSuperAdminProtectedException(
                'The last active Super Admin cannot be deactivated (TB-AUTH-008).'
            );
        }

        $user = $this->auditedTransaction->run(function () use ($user, $remark): User {
            $before = $this->auditableSnapshot($user);

            $user->forceFill([
                'deactivated_at' => now(),
            ])->save();

            $this->auditWriter->write(
                AuditAction::Updated,
                User::class,
                $user->id,
                remark: $remark,
                before: $before,
                after: $this->auditableSnapshot($user->refresh()),
            );

            return $user;
        });

        $this->webSessions->revokeAllSessionsForIdentityLifecycle(
            $user,
            'Identity deactivated; all web sessions revoked',
        );

        return $user;
    }

    /**
     * Explicit reactivation of a deactivated identity (not soft-delete restore).
     * Does not recreate memberships, roles or Owner designation changes.
     */
    public function reactivate(User $user, string $remark): User
    {
        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for identity reactivation.');
        }

        if ($user->trashed()) {
            throw new InvalidArgumentException('Soft-deleted identities cannot be reactivated via deactivation lifecycle.');
        }

        if (! $user->isDeactivated()) {
            throw new InvalidArgumentException('Identity is not deactivated.');
        }

        return $this->auditedTransaction->run(function () use ($user, $remark): User {
            $before = $this->auditableSnapshot($user);

            $user->forceFill([
                'deactivated_at' => null,
            ])->save();

            $this->auditWriter->write(
                AuditAction::Updated,
                User::class,
                $user->id,
                remark: $remark,
                before: $before,
                after: $this->auditableSnapshot($user->refresh()),
            );

            return $user->refresh();
        });
    }

    public function softDelete(User $user, string $remark): User
    {
        if ($user->isLastActiveSuperAdmin()) {
            throw new LastSuperAdminProtectedException(
                'The last active Super Admin cannot be soft-deleted (TB-AUTH-008).'
            );
        }

        $trashed = $this->auditedTransaction->run(function () use ($user, $remark): User {
            $before = $this->auditableSnapshot($user);
            $userId = $user->id;

            $user->delete();

            $trashed = User::withTrashed()->findOrFail($userId);

            $this->auditWriter->write(
                AuditAction::Deleted,
                User::class,
                $userId,
                remark: $remark,
                before: $before,
                after: $this->auditableSnapshot($trashed),
            );

            return $trashed;
        });

        $this->webSessions->revokeAllSessionsForIdentityLifecycle(
            $trashed,
            'Identity soft-deleted; all web sessions revoked',
        );

        return $trashed;
    }

    /**
     * Revoke Super Admin system privilege (demotion scaffolding).
     *
     * Does not assign a Permission Master Internal role — that is P2-WS-3.
     */
    public function revokeSuperAdminPrivilege(User $user, string $remark): User
    {
        if (! $user->isInternal()) {
            throw new IdentityTypeBoundaryException('Only Internal identities can hold Super Admin privilege.');
        }

        if (! $user->isSuperAdmin()) {
            throw new InvalidArgumentException('Identity is not a Super Admin.');
        }

        if ($user->isLastActiveSuperAdmin()) {
            throw new LastSuperAdminProtectedException(
                'The last active Super Admin cannot be demoted (TB-AUTH-008).'
            );
        }

        return $this->auditedTransaction->run(function () use ($user, $remark): User {
            $before = $this->auditableSnapshot($user);

            $user->forceFill([
                'is_super_admin' => false,
            ])->save();

            $this->auditWriter->write(
                AuditAction::Updated,
                User::class,
                $user->id,
                remark: $remark,
                before: $before,
                after: $this->auditableSnapshot($user->refresh()),
            );

            return $user;
        });
    }

    /**
     * Grant Super Admin system privilege (scaffolding). Independent of Permission Master.
     */
    public function grantSuperAdminPrivilege(User $user, string $remark): User
    {
        if (! $user->isInternal()) {
            throw new IdentityTypeBoundaryException('Only Internal identities can hold Super Admin privilege.');
        }

        if ($user->isSuperAdmin()) {
            return $user;
        }

        return $this->auditedTransaction->run(function () use ($user, $remark): User {
            $before = $this->auditableSnapshot($user);

            $user->forceFill([
                'is_super_admin' => true,
            ])->save();

            $this->auditWriter->write(
                AuditAction::Updated,
                User::class,
                $user->id,
                remark: $remark,
                before: $before,
                after: $this->auditableSnapshot($user->refresh()),
            );

            return $user;
        });
    }

    /**
     * @param  array{name?: string, password?: string}  $attributes
     */
    public function updateWithoutOverwritingCredentials(User $existing, array $attributes = []): User
    {
        return $this->auditedTransaction->run(function () use ($existing, $attributes): User {
            $before = $this->auditableSnapshot($existing);
            $passwordBefore = $existing->getAuthPassword();

            if (array_key_exists('password', $attributes)) {
                throw new IdentityTypeBoundaryException('Existing identity password must not be overwritten.');
            }

            $nameChanged = isset($attributes['name']) && $attributes['name'] !== $existing->name;

            if ($nameChanged) {
                $existing->forceFill(['name' => $attributes['name']])->save();
            }

            if ($existing->getAuthPassword() !== $passwordBefore) {
                throw new IdentityTypeBoundaryException('Existing identity password must not be overwritten.');
            }

            if ($nameChanged) {
                $this->auditWriter->write(
                    AuditAction::Updated,
                    User::class,
                    $existing->id,
                    remark: 'Identity attributes updated without credential overwrite',
                    before: $before,
                    after: $this->auditableSnapshot($existing->refresh()),
                );
            }

            return $existing->refresh();
        });
    }

    private function assertSuperAdminConsistency(IdentityType $type, bool $isSuperAdmin): void
    {
        if ($type === IdentityType::Customer && $isSuperAdmin) {
            throw new IdentityTypeBoundaryException(
                'Customer identities cannot hold Super Admin privilege.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditableSnapshot(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'identity_type' => $user->identity_type instanceof IdentityType ? $user->identity_type->value : null,
            'is_super_admin' => $user->is_super_admin,
            'deactivated_at' => $user->deactivated_at?->toISOString(),
            'deleted_at' => $user->deleted_at?->toISOString(),
        ];
    }
}
