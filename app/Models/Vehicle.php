<?php

namespace App\Models;

use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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
        'primary_driver_party_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'manufacture_year' => 'integer',
            'capacity_mt' => 'decimal:3',
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
