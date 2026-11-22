<?php

namespace App\Models;

use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use Database\Factories\LorryReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LorryReceipt extends Model
{
    /** @use HasFactory<LorryReceiptFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'workflow_context', 'lr_number', 'lr_date',
        'from_text', 'to_text', 'from_station_id', 'to_station_id',
        'consignor_party_id', 'consignee_party_id', 'status', 'goods_value',
        'freight_total', 'additional_charges_total', 'created_by_user_id',
        'delivered_at', 'cancelled_at', 'cancellation_remark',
    ];

    protected function casts(): array
    {
        return [
            'workflow_context' => LrWorkflowContext::class,
            'status' => LrStatus::class,
            'lr_date' => 'date',
            'goods_value' => 'decimal:2',
            'freight_total' => 'decimal:2',
            'additional_charges_total' => 'decimal:2',
            'delivered_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Party, $this> */
    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'consignor_party_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function consignee(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'consignee_party_id');
    }

    /** @return HasMany<LrGoodsRow, $this> */
    public function goodsRows(): HasMany
    {
        return $this->hasMany(LrGoodsRow::class)->orderBy('position');
    }

    /** @return HasMany<LrEwayBill, $this> */
    public function ewayBills(): HasMany
    {
        return $this->hasMany(LrEwayBill::class);
    }

    /** @return HasMany<LrAdditionalCharge, $this> */
    public function additionalCharges(): HasMany
    {
        return $this->hasMany(LrAdditionalCharge::class);
    }
}
