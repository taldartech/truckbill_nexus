<?php

namespace App\Models;

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property PermissionDomain $domain
 * @property string $module
 * @property string|null $submodule
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 */
class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'domain',
        'module',
        'submodule',
        'name',
        'code',
        'description',
        'deactivated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domain' => PermissionDomain::class,
            'deactivated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Permission $permission): void {
            if (! filled($permission->getAttribute('id'))) {
                $permission->setAttribute('id', (string) Str::uuid());
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

    public function restore(): bool
    {
        throw new RbacDomainException('Permission restoration is prohibited (DEC-003).');
    }

    public function forceDelete(): bool
    {
        throw new RbacDomainException('Hard deletion of permissions is prohibited (DEC-003).');
    }
}
