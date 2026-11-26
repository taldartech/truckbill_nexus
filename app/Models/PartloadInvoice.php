<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $billing_customer_party_id
 * @property string $invoice_number
 * @property string $status
 * @property string $payment_status
 * @property CarbonInterface $invoice_date
 * @property string $taxable_total
 * @property string $gst_amount
 * @property string $invoice_total
 * @property string $amount_received
 * @property string $customer_tds_amount
 * @property string $balance_due
 * @property array<string, mixed>|null $gst_rate_snapshot
 */
class PartloadInvoice extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'billing_customer_party_id',
        'invoice_number',
        'status',
        'payment_status',
        'invoice_date',
        'taxable_total',
        'gst_amount',
        'invoice_total',
        'amount_received',
        'customer_tds_amount',
        'balance_due',
        'gst_rate_snapshot',
        'cancelled_at',
        'cancellation_remark',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'taxable_total' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'invoice_total' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'customer_tds_amount' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'gst_rate_snapshot' => 'array',
            'cancelled_at' => 'datetime',
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

    /** @return BelongsTo<Party, $this> */
    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'billing_customer_party_id');
    }

    /** @return HasMany<PartloadInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PartloadInvoiceLine::class, 'partload_invoice_id')->orderBy('position');
    }

    /** @return HasMany<PartloadInvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PartloadInvoicePayment::class, 'partload_invoice_id')->orderByDesc('payment_date');
    }
}
