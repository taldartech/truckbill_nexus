<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SubscriptionCreditReservation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'ledger_id',
        'amount',
        'status',
        'idempotency_key',
        'reason',
        'meta',
        'reserved_at',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'meta' => 'array',
            'reserved_at' => 'datetime',
            'finalized_at' => 'datetime',
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
     * @return BelongsTo<SubscriptionCreditLedger, $this>
     */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(SubscriptionCreditLedger::class, 'ledger_id');
    }
}
