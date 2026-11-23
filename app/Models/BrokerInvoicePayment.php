<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BrokerInvoicePayment extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'broker_invoice_id', 'amount_received', 'payment_date', 'payment_mode', 'remark', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_received' => 'decimal:2',
            'payment_date' => 'date',
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
}
