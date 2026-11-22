<?php

namespace App\Models;

use Database\Factories\CompanySubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CompanySubscription extends Model
{
    /** @use HasFactory<CompanySubscriptionFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'catalogue_version_id',
        'status',
        'common_renewal_date',
        'tenure',
        'renewal_eligible',
        'core_module_count',
        'list_annual_total',
        'discounted_annual_total',
        'pricing_breakdown',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'common_renewal_date' => 'date',
            'renewal_eligible' => 'boolean',
            'core_module_count' => 'integer',
            'list_annual_total' => 'decimal:2',
            'discounted_annual_total' => 'decimal:2',
            'pricing_breakdown' => 'array',
            'activated_at' => 'datetime',
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
     * @return HasMany<CompanySubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CompanySubscriptionItem::class, 'company_subscription_id');
    }

    /**
     * @return HasMany<CompanySubscriptionSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(CompanySubscriptionSnapshot::class, 'company_subscription_id');
    }
}
