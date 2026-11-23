<?php

namespace App\Models;

use App\Domains\Broker\Enums\BrokerInvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BrokerInvoice extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'lorry_receipt_id', 'lorry_hire_id',
        'billing_customer_party_id', 'invoice_number', 'status', 'invoice_date',
        'invoice_total', 'amount_received', 'balance_due',
        'cancelled_at', 'cancellation_remark', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => BrokerInvoiceStatus::class,
            'invoice_date' => 'date',
            'invoice_total' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'balance_due' => 'decimal:2',
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

    /** @return HasMany<BrokerInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BrokerInvoiceLine::class);
    }
}
