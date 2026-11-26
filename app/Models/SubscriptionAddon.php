<?php

namespace App\Models;

use Database\Factories\SubscriptionAddonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SubscriptionAddon extends Model
{
    /** @use HasFactory<SubscriptionAddonFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'catalogue_version_id',
        'code',
        'name',
        'annual_price',
        'billing_period',
        'unit_label',
        'is_quantifiable',
        'storage_bytes_per_unit',
        'credit_units_per_unit',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'annual_price' => 'decimal:2',
            'is_quantifiable' => 'boolean',
            'storage_bytes_per_unit' => 'integer',
            'credit_units_per_unit' => 'integer',
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
