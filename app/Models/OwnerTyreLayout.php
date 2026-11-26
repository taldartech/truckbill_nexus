<?php

namespace App\Models;

use Database\Factories\OwnerTyreLayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class OwnerTyreLayout extends Model
{
    /** @use HasFactory<OwnerTyreLayoutFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'owner_user_id',
        'name',
        'spare_tyre_count',
        'drive_tyre_count',
        'needs_axle_completion',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'spare_tyre_count' => 'integer',
            'drive_tyre_count' => 'integer',
            'needs_axle_completion' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<OwnerTyreLayoutAxle, $this>
     */
    public function axles(): HasMany
    {
        return $this->hasMany(OwnerTyreLayoutAxle::class, 'tyre_layout_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<OwnerTyreLayoutPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(OwnerTyreLayoutPosition::class, 'tyre_layout_id')->orderBy('axle_sequence')->orderBy('position_key');
    }
}
