<?php

namespace App\Models;

use App\Domains\Company\Exceptions\CompanyDomainException;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
 * @property int $owner_user_id
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'owner_user_id',
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
        static::creating(function (Company $company): void {
            if (! filled($company->getAttribute('id'))) {
                $company->setAttribute('id', (string) Str::uuid());
            }
        });

        static::updating(function (Company $company): void {
            if ($company->isDirty('owner_user_id')) {
                throw new CompanyDomainException(
                    'Owner designation cannot be changed here; ownership transfer is P2-WS-7.'
                );
            }
        });
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null && ! $this->trashed();
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->owner_user_id === (int) $user->id;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<CompanyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    public function restore(): bool
    {
        throw new CompanyDomainException('Company restoration is prohibited (DEC-003).');
    }

    public function forceDelete(): bool
    {
        throw new CompanyDomainException('Hard deletion of companies is prohibited (DEC-003).');
    }
}
