<?php

namespace App\Models;

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property PermissionDomain $domain
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_default
 * @property bool $is_system
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'domain',
        'name',
        'slug',
        'description',
        'is_default',
        'is_system',
        'deactivated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domain' => PermissionDomain::class,
            'is_default' => 'boolean',
            'is_system' => 'boolean',
            'deactivated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Role $role): void {
            if (! filled($role->getAttribute('id'))) {
                $role->setAttribute('id', (string) Str::uuid());
            }
        });
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null && ! $this->trashed();
    }

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * @return HasMany<InternalRoleAssignment, $this>
     */
    public function internalAssignments(): HasMany
    {
        return $this->hasMany(InternalRoleAssignment::class);
    }

    /**
     * @return HasMany<BranchRoleAssignment, $this>
     */
    public function branchAssignments(): HasMany
    {
        return $this->hasMany(BranchRoleAssignment::class);
    }

    public function restore(): bool
    {
        throw new RbacDomainException('Role restoration is prohibited (DEC-003).');
    }

    public function forceDelete(): bool
    {
        throw new RbacDomainException('Hard deletion of roles is prohibited (DEC-003).');
    }

    public function delete(): ?bool
    {
        throw new RbacDomainException('Roles cannot be deleted; deactivate instead (PRD §6).');
    }
}
