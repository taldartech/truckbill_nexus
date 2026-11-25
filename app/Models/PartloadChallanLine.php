<?php

namespace App\Models;

use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property PartloadFreightPaymentTerm|null $freight_payment_term
 */
class PartloadChallanLine extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'partload_challan_id', 'lorry_receipt_id', 'to_station_id',
        'position', 'packages', 'weight_kg', 'freight_amount', 'freight_payment_term',
    ];

    protected function casts(): array
    {
        return [
            'packages' => 'decimal:3',
            'weight_kg' => 'decimal:3',
            'freight_amount' => 'decimal:2',
            'freight_payment_term' => PartloadFreightPaymentTerm::class,
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

    /** @return BelongsTo<PartloadChallan, $this> */
    public function challan(): BelongsTo
    {
        return $this->belongsTo(PartloadChallan::class, 'partload_challan_id');
    }

    /** @return BelongsTo<LorryReceipt, $this> */
    public function lorryReceipt(): BelongsTo
    {
        return $this->belongsTo(LorryReceipt::class, 'lorry_receipt_id');
    }

    /** @return BelongsTo<Station, $this> */
    public function toStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'to_station_id');
    }
}
