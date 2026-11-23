<?php

namespace App\Models;

use Database\Factories\TripInvoicePaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon $payment_date
 */
class TripInvoicePayment extends Model
{
    /** @use HasFactory<TripInvoicePaymentFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'trip_invoice_id',
        'payment_date',
        'payment_mode_id',
        'reference',
        'amount_received',
        'tds_amount',
        'shortage_damage',
        'discount',
        'discount_reason',
        'settlement_amount',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount_received' => 'decimal:2',
            'tds_amount' => 'decimal:2',
            'shortage_damage' => 'decimal:2',
            'discount' => 'decimal:2',
            'settlement_amount' => 'decimal:2',
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

    /** @return BelongsTo<PaymentMode, $this> */
    public function paymentMode(): BelongsTo
    {
        return $this->belongsTo(PaymentMode::class);
    }
}
