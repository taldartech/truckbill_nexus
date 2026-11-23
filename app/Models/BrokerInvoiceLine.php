<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BrokerInvoiceLine extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'broker_invoice_id', 'lorry_receipt_id', 'lorry_hire_id',
        'memo_number', 'pod_number', 'description', 'freight_amount', 'charges_amount',
        'shortage_damage_amount', 'line_total', 'amount', 'position',
    ];

    protected function casts(): array
    {
        return [
            'freight_amount' => 'decimal:2',
            'charges_amount' => 'decimal:2',
            'shortage_damage_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'amount' => 'decimal:2',
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

    /** @return BelongsTo<BrokerInvoice, $this> */
    public function brokerInvoice(): BelongsTo
    {
        return $this->belongsTo(BrokerInvoice::class);
    }

    /** @return BelongsTo<LorryReceipt, $this> */
    public function lorryReceipt(): BelongsTo
    {
        return $this->belongsTo(LorryReceipt::class);
    }

    /** @return BelongsTo<LorryHire, $this> */
    public function lorryHire(): BelongsTo
    {
        return $this->belongsTo(LorryHire::class);
    }
}
