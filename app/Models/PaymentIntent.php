<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property string $purpose
 * @property string $currency
 * @property string $amount
 * @property string $status
 * @property string $verification_status
 * @property string $idempotency_key
 * @property string|null $company_subscription_id
 * @property string|null $change_proposal_id
 * @property string $gateway
 * @property string|null $gateway_order_id
 * @property string|null $gateway_payment_id
 * @property array<string, mixed>|null $meta
 */
class PaymentIntent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'purpose',
        'currency',
        'amount',
        'status',
        'verification_status',
        'idempotency_key',
        'company_subscription_id',
        'change_proposal_id',
        'gateway',
        'gateway_order_id',
        'gateway_payment_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'meta' => 'array',
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

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<PaymentGatewayEvent, $this>
     */
    public function gatewayEvents(): HasMany
    {
        return $this->hasMany(PaymentGatewayEvent::class, 'payment_intent_id');
    }
}
