<?php

namespace App\Models;

use Database\Factories\TripInvoiceLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TripInvoiceLine extends Model
{
    /** @use HasFactory<TripInvoiceLineFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'trip_invoice_id',
        'lorry_receipt_id',
        'trip_id',
        'sort_order',
        'base_freight',
        'lr_number',
    ];

    protected function casts(): array
    {
        return [
            'base_freight' => 'decimal:2',
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

    /** @return BelongsTo<TripInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TripInvoice::class, 'trip_invoice_id');
    }

    /** @return BelongsTo<LorryReceipt, $this> */
    public function lorryReceipt(): BelongsTo
    {
        return $this->belongsTo(LorryReceipt::class);
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
