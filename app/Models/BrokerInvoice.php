<?php

namespace App\Models;

use App\Domains\Broker\Enums\BrokerInvoiceStatus;
use App\Domains\Broker\Invoice\Enums\BrokerInvoicePaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property BrokerInvoiceStatus $status
 * @property BrokerInvoicePaymentStatus $payment_status
 * @property Carbon|null $invoice_date
 */
class BrokerInvoice extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'lorry_receipt_id', 'lorry_hire_id',
        'billing_customer_party_id', 'invoice_number', 'status', 'payment_status',
        'invoice_date', 'taxable_total', 'gst_amount', 'invoice_total',
        'amount_received', 'customer_tds_amount', 'balance_due', 'gst_rate_snapshot',
        'cancelled_at', 'cancellation_remark', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => BrokerInvoiceStatus::class,
            'payment_status' => BrokerInvoicePaymentStatus::class,
            'invoice_date' => 'date',
            'taxable_total' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'invoice_total' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'customer_tds_amount' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'gst_rate_snapshot' => 'array',
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

    /** @return BelongsTo<Party, $this> */
    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'billing_customer_party_id');
    }

    /** @return HasMany<BrokerInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BrokerInvoiceLine::class);
    }

    /** @return HasMany<BrokerInvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(BrokerInvoicePayment::class);
    }
}
