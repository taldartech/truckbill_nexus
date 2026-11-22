<?php

namespace App\Models;

use Database\Factories\SubscriptionCreditLedgerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $balance
 */
class SubscriptionCreditLedger extends Model
{
    /** @use HasFactory<SubscriptionCreditLedgerFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'ledger_type',
        'service_key',
        'balance',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:4',
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
     * @return HasMany<SubscriptionCreditEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(SubscriptionCreditEntry::class, 'ledger_id');
    }
}
