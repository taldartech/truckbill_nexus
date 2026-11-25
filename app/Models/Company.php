<?php

namespace App\Models;

use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
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
 * @property string|null $pan
 * @property string|null $registered_address
 * @property string|null $state
 * @property string|null $city
 * @property string|null $pin
 * @property string|null $contact_person
 * @property string|null $mobile
 * @property string|null $email
 * @property GstRegistrationType|null $gst_registration_type
 * @property TaxLiability|null $tax_liability
 * @property string|null $gstin
 * @property string|null $gst_rate_id
 * @property array<string, mixed>|null $gst_rate_snapshot
 * @property DataVisibility|null $data_visibility
 * @property string|null $timezone
 * @property string|null $logo_file_id
 * @property string|null $signature_file_id
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
        'pan',
        'registered_address',
        'state',
        'state_id',
        'city',
        'city_id',
        'pin',
        'contact_person',
        'mobile',
        'email',
        'gst_registration_type',
        'tax_liability',
        'gstin',
        'gst_rate_id',
        'gst_rate_snapshot',
        'data_visibility',
        'timezone',
        'logo_file_id',
        'signature_file_id',
        'owner_user_id',
        'deactivated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gst_registration_type' => GstRegistrationType::class,
            'tax_liability' => TaxLiability::class,
            'data_visibility' => DataVisibility::class,
            'gst_rate_snapshot' => 'array',
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
                    'Owner designation cannot be changed here; use OwnershipTransferService.'
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

    /**
     * @return HasMany<OwnershipTransfer, $this>
     */
    public function ownershipTransfers(): HasMany
    {
        return $this->hasMany(OwnershipTransfer::class);
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
