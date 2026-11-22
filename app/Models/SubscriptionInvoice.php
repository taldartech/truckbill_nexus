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
 * @property string $status
 * @property string|null $proposed_number
 * @property string|null $approved_number
 * @property string $financial_year
 * @property string $amount
 * @property string $currency
 * @property array<int, array<string, mixed>>|null $line_items
 * @property CarbonInterface|null $approved_at
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
        'status',
        'proposed_number',
        'approved_number',
        'financial_year',
        'amount',
        'currency',
        'line_items',
        'approved_at',
        'approved_by',
        'emailed_at',
        'email_status',
        'email_failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'line_items' => 'array',
            'approved_at' => 'datetime',
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
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
