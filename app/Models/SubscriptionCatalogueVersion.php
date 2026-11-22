<?php

namespace App\Models;

use Database\Factories\SubscriptionCatalogueVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SubscriptionCatalogueVersion extends Model
{
    /** @use HasFactory<SubscriptionCatalogueVersionFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'code',
        'name',
        'effective_from',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
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
     * @return HasMany<SubscriptionPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(SubscriptionPlan::class, 'catalogue_version_id');
    }

    /**
     * @return HasMany<SubscriptionAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(SubscriptionAddon::class, 'catalogue_version_id');
    }
}
