<?php

namespace App\Models;

use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Enums\TripTimingPerformance;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property TripStatus $status
 * @property TripTimingPerformance|null $timing_performance
 * @property Carbon|null $departure_at
 * @property Carbon|null $actual_arrival_at
 * @property Carbon|null $expected_end_at
 * @property Carbon|null $cancelled_at
 */
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'lorry_receipt_id',
        'status',
        'vehicle_id',
        'driver_party_id',
        'primary_driver_party_id',
        'substitute_driver_party_id',
        'starting_odometer',
        'departure_at',
        'ending_odometer',
        'actual_arrival_at',
        'trip_distance',
        'expected_end_at',
        'timing_performance',
        'cancelled_at',
        'cancellation_remark',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'timing_performance' => TripTimingPerformance::class,
            'starting_odometer' => 'decimal:1',
            'ending_odometer' => 'decimal:1',
            'trip_distance' => 'decimal:1',
            'departure_at' => 'immutable_datetime',
            'actual_arrival_at' => 'immutable_datetime',
            'expected_end_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<LorryReceipt, $this> */
    public function lorryReceipt(): BelongsTo
    {
        return $this->belongsTo(LorryReceipt::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'driver_party_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function primaryDriver(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'primary_driver_party_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function substituteDriver(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'substitute_driver_party_id');
    }

    /** @return HasMany<TripPickup, $this> */
    public function pickups(): HasMany
    {
        return $this->hasMany(TripPickup::class)->orderBy('position');
    }

    /** @return HasMany<TripDrop, $this> */
    public function drops(): HasMany
    {
        return $this->hasMany(TripDrop::class)->orderBy('position');
    }

    /** @return HasMany<TripDropPickupAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(TripDropPickupAllocation::class);
    }
}
