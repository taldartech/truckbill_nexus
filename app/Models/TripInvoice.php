<?php

namespace App\Models;

use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceParticularsMode;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoicePaymentStatus;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceStatus;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceType;
use Database\Factories\TripInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property TripInvoiceStatus $status
 * @property TripInvoicePaymentStatus $payment_status
 * @property TripInvoiceType $invoice_type
 * @property TripInvoiceParticularsMode $particulars_mode
 * @property Carbon $invoice_date
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $charges_locked_at
 * @property array<string, mixed>|null $gst_rate_snapshot
 * @property array<string, mixed> $billing_customer_snapshot
 */
class TripInvoice extends Model
{
    /** @use HasFactory<TripInvoiceFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'invoice_number',
        'document_number_allocation_id',
        'billing_customer_party_id',
        'invoice_type',
        'particulars_mode',
        'status',
        'payment_status',
        'invoice_date',
        'workflow_state',
        'base_freight_total',
        'additional_charges_total',
        'taxable_total',
        'gst_amount',
        'invoice_total',
        'amount_received_total',
        'tds_received_total',
        'deductions_total',
        'settlement_total',
        'balance_due',
        'invoice_tds_rate_percent',
        'tds_basis_freight',
        'gst_rate_snapshot',
        'billing_customer_snapshot',
        'cancelled_at',
        'cancellation_remark',
        'charges_locked_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TripInvoiceStatus::class,
            'payment_status' => TripInvoicePaymentStatus::class,
            'invoice_type' => TripInvoiceType::class,
            'particulars_mode' => TripInvoiceParticularsMode::class,
            'invoice_date' => 'date',
            'base_freight_total' => 'decimal:2',
            'additional_charges_total' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'invoice_total' => 'decimal:2',
            'amount_received_total' => 'decimal:2',
            'tds_received_total' => 'decimal:2',
            'deductions_total' => 'decimal:2',
            'settlement_total' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'invoice_tds_rate_percent' => 'decimal:2',
            'tds_basis_freight' => 'decimal:2',
            'gst_rate_snapshot' => 'array',
            'billing_customer_snapshot' => 'array',
            'cancelled_at' => 'immutable_datetime',
            'charges_locked_at' => 'immutable_datetime',
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

    /** @return HasMany<TripInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(TripInvoiceLine::class);
    }

    /** @return HasMany<TripInvoiceCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(TripInvoiceCharge::class);
    }

    /** @return HasMany<TripInvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(TripInvoicePayment::class);
    }
}
