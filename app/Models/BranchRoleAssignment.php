<?php

namespace App\Models;

use App\Domains\Rbac\Exceptions\RbacDomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Customer branch-scoped role assignment (requires active branch assignment).
 *
 * @property string $id
 * @property string $company_id
 * @property string $company_membership_id
 * @property string $branch_id
 * @property string $branch_assignment_id
 * @property string $role_id
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 */
class BranchRoleAssignment extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'company_membership_id',
        'branch_id',
        'branch_assignment_id',
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
        static::creating(function (BranchRoleAssignment $assignment): void {
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<CompanyMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(CompanyMembership::class, 'company_membership_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<BranchAssignment, $this>
     */
    public function branchAssignment(): BelongsTo
    {
        return $this->belongsTo(BranchAssignment::class, 'branch_assignment_id');
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
        throw new RbacDomainException('Branch role assignment restoration is prohibited (DEC-003).');
    }

    public function forceDelete(): bool
    {
        throw new RbacDomainException('Hard deletion of branch role assignments is prohibited (DEC-003).');
    }
}
