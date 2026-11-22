<?php

namespace App\Models;

use App\Domains\Rbac\Exceptions\RbacDomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $role_id
 * @property string $permission_id
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 */
class RolePermission extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'permission_id',
        'deactivated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deactivated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RolePermission $pivot): void {
            if (! filled($pivot->getAttribute('id'))) {
                $pivot->setAttribute('id', (string) Str::uuid());
            }
        });
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null && ! $this->trashed();
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function restore(): bool
    {
        throw new RbacDomainException('Role-permission restoration is prohibited (DEC-003).');
    }

    public function forceDelete(): bool
    {
        throw new RbacDomainException('Hard deletion of role-permissions is prohibited (DEC-003).');
    }
}
