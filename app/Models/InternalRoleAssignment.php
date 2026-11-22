<?php

namespace App\Models;

use App\Domains\Rbac\Exceptions\RbacDomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Global Internal identity ↔ ordinary Internal role assignment (not Super Admin).
 *
 * @property string $id
 * @property int $user_id
 * @property string $role_id
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 */
class InternalRoleAssignment extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'role_id',
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
        static::creating(function (InternalRoleAssignment $assignment): void {
            if (! filled($assignment->getAttribute('id'))) {
                $assignment->setAttribute('id', (string) Str::uuid());
            }
        });
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null && ! $this->trashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function restore(): bool
    {
        throw new RbacDomainException('Internal role assignment restoration is prohibited (DEC-003).');
    }

    public function forceDelete(): bool
    {
        throw new RbacDomainException('Hard deletion of internal role assignments is prohibited (DEC-003).');
    }
}
