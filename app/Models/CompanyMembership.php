<?php

namespace App\Models;

use App\Domains\Company\Exceptions\CompanyDomainException;
use Database\Factories\CompanyMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property int $user_id
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 */
class CompanyMembership extends Model
{
    /** @use HasFactory<CompanyMembershipFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'user_id',
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
        static::creating(function (CompanyMembership $membership): void {
            if (! filled($membership->getAttribute('id'))) {
                $membership->setAttribute('id', (string) Str::uuid());
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BranchAssignment, $this>
     */
    public function branchAssignments(): HasMany
    {
        return $this->hasMany(BranchAssignment::class);
    }

    public function restore(): bool
    {
        throw new CompanyDomainException('Membership restoration is prohibited (DEC-003).');
    }

    public function forceDelete(): bool
    {
        throw new CompanyDomainException('Hard deletion of memberships is prohibited (DEC-003).');
    }
}
