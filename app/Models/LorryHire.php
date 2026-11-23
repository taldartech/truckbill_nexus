<?php

namespace App\Models;

use App\Domains\Broker\Enums\LorryHireStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property LorryHireStatus $status
 * @property Carbon|null $hire_date
 * @property Carbon|null $cancelled_at
 * @property string $freight_total
 * @property string $tds_percent
 * @property string $tds_amount
 * @property string $paid_amount
 * @property string $outstanding_amount
 * @property-read Collection<int, LorryHireGoodsLine> $goodsLines
 * @property-read Collection<int, TransporterVoucher> $vouchers
 * @property-read Collection<int, BrokerAdjustment> $adjustments
 * @property-read Collection<int, TransporterBookBalanceEntry> $bookBalanceEntries
 * @property-read LorryHireTdsSnapshot|null $tdsSnapshot
 * @property-read LorryReceipt|null $lorryReceipt
 * @property-read Vehicle|null $vehicle
 * @property-read Party|null $transporter
 */
class LorryHire extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'lorry_receipt_id', 'hire_number', 'hire_date',
        'vehicle_id', 'transporter_party_id', 'declaration_type', 'status',
        'freight_total', 'tds_percent', 'tds_amount', 'paid_amount', 'outstanding_amount',
        'remarks', 'cancelled_at', 'cancellation_remark', 'settle_outstanding', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => LorryHireStatus::class,
            'hire_date' => 'date',
            'freight_total' => 'decimal:2',
            'tds_percent' => 'decimal:3',
            'tds_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'settle_outstanding' => 'boolean',
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
    public function transporter(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'transporter_party_id');
    }

    /** @return HasMany<LorryHireGoodsLine, $this> */
    public function goodsLines(): HasMany
    {
        return $this->hasMany(LorryHireGoodsLine::class);
    }

    /** @return HasOne<LorryHireTdsSnapshot, $this> */
    public function tdsSnapshot(): HasOne
    {
        return $this->hasOne(LorryHireTdsSnapshot::class);
    }

    /** @return HasMany<TransporterVoucher, $this> */
    public function vouchers(): HasMany
    {
        return $this->hasMany(TransporterVoucher::class);
    }

    /** @return HasMany<BrokerAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(BrokerAdjustment::class);
    }

    /** @return HasMany<TransporterBookBalanceEntry, $this> */
    public function bookBalanceEntries(): HasMany
    {
        return $this->hasMany(TransporterBookBalanceEntry::class);
    }
}
