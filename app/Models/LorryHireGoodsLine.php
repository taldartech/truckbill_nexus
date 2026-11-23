<?php

namespace App\Models;

use App\Domains\Consignment\Enums\FreightRateType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property FreightRateType $rate_type
 * @property string $rate
 * @property bool $is_fixed_amount
 * @property string $freight_amount
 * @property string $loading_weight
 * @property-read LrGoodsRow|null $lrGoodsRow
 */
class LorryHireGoodsLine extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'lorry_hire_id', 'lr_goods_row_id',
        'rate_type', 'rate', 'is_fixed_amount', 'freight_amount', 'loading_weight',
    ];

    protected function casts(): array
    {
        return [
            'rate_type' => FreightRateType::class,
            'rate' => 'decimal:4',
            'is_fixed_amount' => 'boolean',
            'freight_amount' => 'decimal:2',
            'loading_weight' => 'decimal:3',
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

    /** @return BelongsTo<LorryHire, $this> */
    public function lorryHire(): BelongsTo
    {
        return $this->belongsTo(LorryHire::class);
    }

    /** @return BelongsTo<LrGoodsRow, $this> */
    public function lrGoodsRow(): BelongsTo
    {
        return $this->belongsTo(LrGoodsRow::class);
    }
}
