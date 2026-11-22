<?php

namespace App\Models;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Immutable contract snapshot (Rules §15). Updates and deletes are rejected.
 *
 * @property CarbonInterface|null $created_at
 */
class CompanySubscriptionSnapshot extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'company_subscription_id',
        'reason',
        'payload',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
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
            throw new SubscriptionDomainException('Subscription contract snapshots are immutable.');
        });

        static::deleting(function (): void {
            throw new SubscriptionDomainException('Subscription contract snapshots cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<CompanySubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'company_subscription_id');
    }
}
