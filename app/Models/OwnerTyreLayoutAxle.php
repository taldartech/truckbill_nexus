<?php

namespace App\Models;

use App\Domains\Master\TyreLayout\Enums\TyreAxleConfiguration;
use Database\Factories\OwnerTyreLayoutAxleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OwnerTyreLayoutAxle extends Model
{
    /** @use HasFactory<OwnerTyreLayoutAxleFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tyre_layout_id',
        'sequence',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'configuration' => TyreAxleConfiguration::class,
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
