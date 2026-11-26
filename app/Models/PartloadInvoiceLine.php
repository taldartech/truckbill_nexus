<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PartloadInvoiceLine extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'partload_invoice_id',
        'lorry_receipt_id',
        'partload_delivery_receipt_id',
        'description',
        'freight_amount',
        'charges_amount',
        'line_total',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'freight_amount' => 'decimal:2',
            'charges_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
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

    /** @return BelongsTo<PartloadInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PartloadInvoice::class, 'partload_invoice_id');
    }

    /** @return BelongsTo<LorryReceipt, $this> */
    public function lorryReceipt(): BelongsTo
    {
        return $this->belongsTo(LorryReceipt::class, 'lorry_receipt_id');
    }

    /** @return BelongsTo<PartloadDeliveryReceipt, $this> */
    public function deliveryReceipt(): BelongsTo
    {
        return $this->belongsTo(PartloadDeliveryReceipt::class, 'partload_delivery_receipt_id');
    }
}
