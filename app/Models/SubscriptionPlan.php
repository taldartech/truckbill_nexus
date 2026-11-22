<?php

namespace App\Models;

use Database\Factories\SubscriptionPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SubscriptionPlan extends Model
{
    /** @use HasFactory<SubscriptionPlanFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'catalogue_version_id',
        'module',
        'code',
        'name',
        'annual_price',
        'capacity_units',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'annual_price' => 'decimal:2',
            'capacity_units' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
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
     * @return BelongsTo<SubscriptionCatalogueVersion, $this>
     */
    public function catalogueVersion(): BelongsTo
    {
        return $this->belongsTo(SubscriptionCatalogueVersion::class, 'catalogue_version_id');
    }
}
