<?php

namespace App\Models;

use Database\Factories\OwnerTyreLayoutPositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OwnerTyreLayoutPosition extends Model
{
    /** @use HasFactory<OwnerTyreLayoutPositionFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tyre_layout_id',
        'position_key',
        'axle_sequence',
        'side',
        'ring',
        'label',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'axle_sequence' => 'integer',
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
     * @return BelongsTo<OwnerTyreLayout, $this>
     */
    public function layout(): BelongsTo
    {
        return $this->belongsTo(OwnerTyreLayout::class, 'tyre_layout_id');
    }
}
