<?php

namespace App\Models;

use Database\Factories\TripPickupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TripPickup extends Model
{
    /** @use HasFactory<TripPickupFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'trip_id',
        'position',
        'station_id',
        'consignor_party_id',
        'goods_description',
        'lr_goods_row_id',
        'weight',
        'pickup_date',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'pickup_date' => 'date',
            'position' => 'integer',
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

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'consignor_party_id');
    }

    /** @return HasMany<TripDropPickupAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(TripDropPickupAllocation::class, 'trip_pickup_id');
    }
}
