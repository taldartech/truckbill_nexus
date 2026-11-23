<?php

namespace App\Models;

use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceChargeType;
use Database\Factories\TripInvoiceChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TripInvoiceCharge extends Model
{
    /** @use HasFactory<TripInvoiceChargeFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'trip_invoice_id',
        'charge_type',
        'description',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'charge_type' => TripInvoiceChargeType::class,
            'amount' => 'decimal:2',
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
}
