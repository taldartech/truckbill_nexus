<?php

namespace App\Models;

use App\Domains\Identity\Enums\IdentityType;
use App\Domains\Identity\Enums\InternalRole;
use App\Domains\Identity\Exceptions\IdentityHardDeleteProhibitedException;
use App\Domains\Identity\Exceptions\IdentityRestoreProhibitedException;
use App\Domains\Identity\Exceptions\IdentityTypeBoundaryException;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Global login identity (ADR-005). Mutually exclusive Internal / Customer type.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property IdentityType|null $identity_type
 * @property InternalRole|null $internal_role
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'identity_type' => IdentityType::class,
            'internal_role' => InternalRole::class,
            'deactivated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $user->assertIdentityTypeBoundary();
        });
    }

    public function isInternal(): bool
    {
        return $this->identity_type === IdentityType::Internal;
    }

    public function isCustomer(): bool
    {
        return $this->identity_type === IdentityType::Customer;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isDeactivated() && ! $this->trashed();
    }

    public function isSuperAdmin(): bool
    {
        return $this->isInternal() && $this->internal_role === InternalRole::SuperAdmin;
    }

    /**
     * Company Owner is a protected company designation (P2-WS-2), not an identity type or InternalRole.
     */
    public function isCompanyOwnerDesignation(): bool
    {
        return false;
    }

    public function isLastActiveSuperAdmin(): bool
    {
        if (! $this->isSuperAdmin() || ! $this->isActive()) {
            return false;
        }

        return ! static::query()
            ->internal()
            ->superAdmins()
            ->active()
            ->whereKeyNot($this->getKey())
            ->exists();
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('identity_type', IdentityType::Internal->value);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeCustomer(Builder $query): Builder
    {
        return $query->where('identity_type', IdentityType::Customer->value);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeDeactivated(Builder $query): Builder
    {
        return $query->whereNotNull('deactivated_at');
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeSuperAdmins(Builder $query): Builder
    {
        return $query
            ->where('identity_type', IdentityType::Internal->value)
            ->where('internal_role', InternalRole::SuperAdmin->value);
    }

    public function restore(): bool
    {
        throw new IdentityRestoreProhibitedException(
            'Identity restoration is prohibited (DEC-003 / ADR-004).'
        );
    }

    public function forceDelete(): bool
    {
        throw new IdentityHardDeleteProhibitedException(
            'Hard deletion of identities is prohibited (DEC-003 / ADR-004).'
        );
    }

    private function assertIdentityTypeBoundary(): void
    {
        if ($this->identity_type === null) {
            throw new IdentityTypeBoundaryException('Identity type is required.');
        }

        if ($this->identity_type === IdentityType::Customer && $this->internal_role !== null) {
            throw new IdentityTypeBoundaryException('Customer identities cannot have an internal role.');
        }

        if ($this->identity_type === IdentityType::Internal && $this->internal_role === null) {
            throw new IdentityTypeBoundaryException('Internal identities require an internal role.');
        }
    }
}
