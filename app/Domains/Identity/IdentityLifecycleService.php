<?php

namespace App\Domains\Identity;

use App\Domains\Identity\Enums\IdentityType;
use App\Domains\Identity\Enums\InternalRole;
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
 * Does not expose HTTP/admin UI. Invitation and session enforcement are later workstreams.
 */
final class IdentityLifecycleService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, identity_type: IdentityType, internal_role?: InternalRole|null}  $attributes
     */
    public function create(array $attributes): User
    {
        $type = $attributes['identity_type'];
        $role = $attributes['internal_role'] ?? null;

        $this->assertTypeRoleConsistency($type, $role);

        return $this->auditedTransaction->run(function () use ($attributes, $type, $role): User {
            $user = new User;
            $user->forceFill([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'identity_type' => $type,
                'internal_role' => $role,
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

        return $this->auditedTransaction->run(function () use ($user, $remark): User {
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
    }

    public function softDelete(User $user, string $remark): User
    {
        if ($user->isLastActiveSuperAdmin()) {
            throw new LastSuperAdminProtectedException(
                'The last active Super Admin cannot be soft-deleted (TB-AUTH-008).'
            );
        }

        return $this->auditedTransaction->run(function () use ($user, $remark): User {
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
    }

    /**
     * Demotion scaffolding only — does not implement full role administration UI.
     */
    public function changeInternalRole(User $user, InternalRole $role, string $remark): User
    {
        if (! $user->isInternal()) {
            throw new IdentityTypeBoundaryException('Only Internal identities have internal roles.');
        }

        if (
            $user->internal_role === InternalRole::SuperAdmin
            && $role !== InternalRole::SuperAdmin
            && $user->isLastActiveSuperAdmin()
        ) {
            throw new LastSuperAdminProtectedException(
                'The last active Super Admin cannot be demoted (TB-AUTH-008).'
            );
        }

        return $this->auditedTransaction->run(function () use ($user, $role, $remark): User {
            $before = $this->auditableSnapshot($user);

            $user->forceFill([
                'internal_role' => $role,
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
     * Preserve credentials when reusing an existing identity (TB-AUTH-003 preparation).
     *
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

    private function assertTypeRoleConsistency(IdentityType $type, ?InternalRole $role): void
    {
        if ($type === IdentityType::Customer && $role !== null) {
            throw new IdentityTypeBoundaryException('Customer identities cannot have an internal role.');
        }

        if ($type === IdentityType::Internal && $role === null) {
            throw new IdentityTypeBoundaryException('Internal identities require an internal role.');
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
            'internal_role' => $user->internal_role instanceof InternalRole ? $user->internal_role->value : null,
            'deactivated_at' => $user->deactivated_at?->toISOString(),
            'deleted_at' => $user->deleted_at?->toISOString(),
        ];
    }
}
