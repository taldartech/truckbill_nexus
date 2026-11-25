<?php

namespace App\Models;

use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Partload\Enums\PartloadChallanStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property PartloadChallanStatus $status
 * @property VehicleOwnership $ownership_mode
 * @property Carbon|null $challan_date
 * @property-read Collection<int, PartloadChallanLine> $lines
 * @property-read PartloadInwardRegister|null $inwardRegister
 */
class PartloadChallan extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'challan_number', 'challan_date',
        'from_station_id', 'to_station_id', 'vehicle_id', 'driver_party_id',
        'ownership_mode', 'status',
        'truck_freight', 'labour_charge', 'kat', 'home_delivery_charge',
        'cash_received', 'cash_payment',
        'total_packages', 'total_weight_kg', 'to_pay_amount', 'paid_amount',
        'crossing_freight', 'cartage_dalali_pf', 'gst_amount', 'total_amount',
        'to_account_party_id', 'remark',
        'dispatched_at', 'cancelled_at', 'cancellation_remark', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PartloadChallanStatus::class,
            'ownership_mode' => VehicleOwnership::class,
            'challan_date' => 'date',
            'truck_freight' => 'decimal:2',
            'labour_charge' => 'decimal:2',
            'kat' => 'decimal:2',
            'home_delivery_charge' => 'decimal:2',
            'cash_received' => 'decimal:2',
            'cash_payment' => 'decimal:2',
            'total_packages' => 'decimal:3',
            'total_weight_kg' => 'decimal:3',
            'to_pay_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'crossing_freight' => 'decimal:2',
            'cartage_dalali_pf' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'dispatched_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'driver_party_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function toAccountParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'to_account_party_id');
    }

    /** @return HasMany<PartloadChallanLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PartloadChallanLine::class, 'partload_challan_id')->orderBy('position');
    }

    /** @return HasOne<PartloadInwardRegister, $this> */
    public function inwardRegister(): HasOne
    {
        return $this->hasOne(PartloadInwardRegister::class, 'partload_challan_id');
    }
}
