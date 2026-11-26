<?php

namespace App\Models;

use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property VehicleOwnership|null $ownership
 */
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'registration_number',
        'vehicle_type',
        'make',
        'model',
        'manufacture_year',
        'chassis_number',
        'engine_number',
        'capacity_mt',
        'is_active',
        'ownership',
        'truck_type_id',
        'tyre_layout_id',
        'owner_party_id',
        'primary_driver_party_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'manufacture_year' => 'integer',
            'capacity_mt' => 'decimal:3',
            'ownership' => VehicleOwnership::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! filled($model->getAttribute('id'))) {
                $model->setAttribute('id', (string) Str::uuid());
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function primaryDriver(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'primary_driver_party_id');
    }

    /**
     * Market vehicle owner (Transporter party role).
     *
     * @return BelongsTo<Party, $this>
     */
    public function ownerParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'owner_party_id');
    }

    /**
     * @return BelongsTo<OwnerCatalogueItem, $this>
     */
    public function truckType(): BelongsTo
    {
        return $this->belongsTo(OwnerCatalogueItem::class, 'truck_type_id');
    }

    /**
     * @return BelongsTo<OwnerTyreLayout, $this>
     */
    public function tyreLayout(): BelongsTo
    {
        return $this->belongsTo(OwnerTyreLayout::class, 'tyre_layout_id');
    }

    /**
     * @param  Builder<Vehicle>  $query
     * @return Builder<Vehicle>
     */
    public function scopeVisibleInBranch(Builder $query, string $companyId, string $branchId): Builder
    {
        return $query->where('company_id', $companyId)
            ->where(function (Builder $inner) use ($branchId): void {
                $inner->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });
    }
}
