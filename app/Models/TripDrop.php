<?php

namespace App\Models;

use Database\Factories\TripDropFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TripDrop extends Model
{
    /** @use HasFactory<TripDropFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'trip_id',
        'position',
        'station_id',
        'consignee_party_id',
    ];

    protected function casts(): array
    {
        return [
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
    public function consignee(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'consignee_party_id');
    }

    /** @return HasMany<TripDropPickupAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(TripDropPickupAllocation::class, 'trip_drop_id');
    }
}
