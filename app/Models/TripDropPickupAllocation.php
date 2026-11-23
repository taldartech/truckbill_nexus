<?php

namespace App\Models;

use Database\Factories\TripDropPickupAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TripDropPickupAllocation extends Model
{
    /** @use HasFactory<TripDropPickupAllocationFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'trip_id',
        'trip_drop_id',
        'trip_pickup_id',
        'allocation_weight',
        'rate',
        'freight',
        'distance',
        'package_count',
        'ewb_number',
        'goods_value',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'allocation_weight' => 'decimal:3',
            'rate' => 'decimal:4',
            'freight' => 'decimal:2',
            'distance' => 'decimal:1',
            'package_count' => 'decimal:3',
            'goods_value' => 'decimal:2',
            'lock_version' => 'integer',
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

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<TripDrop, $this> */
    public function drop(): BelongsTo
    {
        return $this->belongsTo(TripDrop::class, 'trip_drop_id');
    }

    /** @return BelongsTo<TripPickup, $this> */
    public function pickup(): BelongsTo
    {
        return $this->belongsTo(TripPickup::class, 'trip_pickup_id');
    }
}
