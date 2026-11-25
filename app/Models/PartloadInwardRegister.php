<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $inward_date
 * @property-read Collection<int, PartloadInwardLine> $lines
 */
class PartloadInwardRegister extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'inward_number', 'inward_date',
        'partload_challan_id', 'from_station_id', 'to_station_id',
        'vehicle_id', 'driver_party_id', 'status', 'remark', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'inward_date' => 'date',
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

    /** @return BelongsTo<PartloadChallan, $this> */
    public function challan(): BelongsTo
    {
        return $this->belongsTo(PartloadChallan::class, 'partload_challan_id');
    }

    /** @return BelongsTo<Station, $this> */
    public function fromStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'from_station_id');
    }

    /** @return BelongsTo<Station, $this> */
    public function toStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'to_station_id');
    }

    /** @return HasMany<PartloadInwardLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PartloadInwardLine::class, 'partload_inward_register_id')->orderBy('position');
    }
}
