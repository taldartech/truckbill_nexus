<?php

namespace App\Models;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $payment_intent_id
 * @property string|null $company_subscription_id
 * @property string $status
 * @property string $document_type
 * @property string|null $original_invoice_id
 * @property string|null $proposed_number
 * @property string|null $approved_number
 * @property string|null $invoice_number
 * @property string $financial_year
 * @property CarbonInterface|null $service_period_start
 * @property CarbonInterface|null $service_period_end
 * @property string|null $purchase_type
 * @property string|null $sac_code
 * @property string $subtotal_amount
 * @property string $discount_amount
 * @property string $taxable_amount
 * @property string $gst_rate_percent
 * @property string $cgst_amount
 * @property string $sgst_amount
 * @property string $igst_amount
 * @property string $total_tax_amount
 * @property-read Company|null $company
 * @property-read PaymentIntent|null $paymentIntent
 * @property string|null $place_of_supply_state
 * @property string|null $place_of_supply_code
 * @property bool $is_inter_state
 * @property string $amount
 * @property string $currency
 * @property array<int, array<string, mixed>>|null $line_items
 * @property array<string, mixed>|null $supplier_snapshot
 * @property array<string, mixed>|null $customer_snapshot
 * @property array<string, mixed>|null $pricing_snapshot
 * @property string|null $payment_reference
 * @property string|null $credit_note_reason
 * @property CarbonInterface|null $approved_at
 * @property CarbonInterface|null $issued_at
 * @property int|null $approved_by
 * @property CarbonInterface|null $emailed_at
 * @property string|null $email_status
 * @property string|null $email_failure_reason
 */
class SubscriptionInvoice extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'payment_intent_id',
        'company_subscription_id',
        'status',
        'document_type',
        'original_invoice_id',
        'proposed_number',
        'approved_number',
        'invoice_number',
        'financial_year',
        'service_period_start',
        'service_period_end',
        'purchase_type',
        'sac_code',
        'subtotal_amount',
        'discount_amount',
        'taxable_amount',
        'gst_rate_percent',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'total_tax_amount',
        'place_of_supply_state',
        'place_of_supply_code',
        'is_inter_state',
        'amount',
        'currency',
        'line_items',
        'supplier_snapshot',
        'customer_snapshot',
        'pricing_snapshot',
        'payment_reference',
        'credit_note_reason',
        'approved_at',
        'issued_at',
        'approved_by',
        'emailed_at',
        'email_status',
        'email_failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gst_rate_percent' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total_tax_amount' => 'decimal:2',
            'is_inter_state' => 'boolean',
            'service_period_start' => 'date',
            'service_period_end' => 'date',
            'line_items' => 'array',
            'supplier_snapshot' => 'array',
            'customer_snapshot' => 'array',
            'pricing_snapshot' => 'array',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! filled($model->getAttribute('id'))) {
                $model->setAttribute('id', (string) Str::uuid());
            }
        });

        static::updating(function (self $model): void {
            if ($model->getOriginal('approved_number') !== null
                && $model->isDirty('approved_number')
            ) {
                throw new SubscriptionDomainException(
                    'Approved invoice numbers cannot be edited.'
                );
            }

            if ($model->getOriginal('issued_at') !== null) {
                foreach ([
                    'invoice_number',
                    'amount',
                    'subtotal_amount',
                    'discount_amount',
                    'taxable_amount',
                    'gst_rate_percent',
                    'cgst_amount',
                    'sgst_amount',
                    'igst_amount',
                    'total_tax_amount',
                    'supplier_snapshot',
                    'customer_snapshot',
                    'pricing_snapshot',
                    'line_items',
                    'service_period_start',
                    'service_period_end',
                ] as $immutableField) {
                    if ($model->isDirty($immutableField)) {
                        throw new SubscriptionDomainException(
                            'Issued subscription invoice financial and billing snapshots are immutable.'
                        );
                    }
                }
            }
        });
    }

    /**
     * @return BelongsTo<SubscriptionInvoice, $this>
     */
    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    /**
     * @return BelongsTo<CompanySubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'company_subscription_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<PaymentIntent, $this>
     */
    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }
}
