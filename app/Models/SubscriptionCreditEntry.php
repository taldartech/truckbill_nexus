<?php

namespace App\Models;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Append-only credit ledger entry.
 *
 * @property CarbonInterface|null $created_at
 */
class SubscriptionCreditEntry extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'ledger_id',
        'amount',
        'entry_type',
        'reason',
        'idempotency_key',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! filled($model->getAttribute('id'))) {
                $model->setAttribute('id', (string) Str::uuid());
            }
        });

        static::updating(function (): void {
            throw new SubscriptionDomainException('Credit ledger entries are append-only.');
        });

        static::deleting(function (): void {
            throw new SubscriptionDomainException('Credit ledger entries cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<SubscriptionCreditLedger, $this>
     */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(SubscriptionCreditLedger::class, 'ledger_id');
    }
}
