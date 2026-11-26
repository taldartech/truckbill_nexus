<?php

namespace App\Models;

use Database\Factories\CompanySubscriptionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CompanySubscriptionItem extends Model
{
    /** @use HasFactory<CompanySubscriptionItemFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'company_subscription_id',
        'item_type',
        'plan_id',
        'addon_id',
        'module',
        'code',
        'name',
        'annual_price_snapshot',
        'capacity_units_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'annual_price_snapshot' => 'decimal:2',
            'capacity_units_snapshot' => 'integer',
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
     * @return BelongsTo<CompanySubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'company_subscription_id');
    }

    /**
     * @return BelongsTo<SubscriptionAddon, $this>
     */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(SubscriptionAddon::class, 'addon_id');
    }
}
