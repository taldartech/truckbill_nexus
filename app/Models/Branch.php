<?php

namespace App\Models;

use App\Domains\Company\Enums\BranchGstMode;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Company\Exceptions\CompanyDomainException;
use Database\Factories\BranchFactory;
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
 * @property string $name
 * @property string|null $contact_number
 * @property bool $is_head_office
 * @property string|null $address
 * @property string|null $state
 * @property string|null $state_id
 * @property string|null $city
 * @property string|null $city_id
 * @property string|null $pin
 * @property BranchGstMode $gst_mode
 * @property GstRegistrationType|null $gst_registration_type
 * @property TaxLiability|null $tax_liability
 * @property string|null $gstin
 * @property string|null $gst_rate_id
 * @property array<string, mixed>|null $gst_rate_snapshot
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'contact_number',
        'is_head_office',
        'address',
        'state',
        'state_id',
        'city',
        'city_id',
        'pin',
        'gst_mode',
        'gst_registration_type',
        'tax_liability',
        'gstin',
        'gst_rate_id',
        'gst_rate_snapshot',
        'deactivated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_head_office' => 'boolean',
            'gst_mode' => BranchGstMode::class,
            'gst_registration_type' => GstRegistrationType::class,
            'tax_liability' => TaxLiability::class,
            'gst_rate_snapshot' => 'array',
            'deactivated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Branch $branch): void {
            if (! filled($branch->getAttribute('id'))) {
                $branch->setAttribute('id', (string) Str::uuid());
            }

            if ($branch->getAttribute('gst_mode') === null) {
                $branch->setAttribute('gst_mode', BranchGstMode::InheritCompany);
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
     * @return BelongsTo<GstRate, $this>
     */
    public function gstRate(): BelongsTo
    {
        return $this->belongsTo(GstRate::class, 'gst_rate_id');
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function stateRecord(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function cityRecord(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    /**
     * @return HasMany<BranchAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(BranchAssignment::class);
    }

    public function restore(): bool
    {
        throw new CompanyDomainException('Branch restoration is prohibited (DEC-003).');
    }

    public function forceDelete(): bool
    {
        throw new CompanyDomainException('Hard deletion of branches is prohibited (DEC-003).');
    }
}
