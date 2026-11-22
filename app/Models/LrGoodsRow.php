<?php

namespace App\Models;

use App\Domains\Consignment\Enums\FreightRateType;
use Database\Factories\LrGoodsRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LrGoodsRow extends Model
{
    /** @use HasFactory<LrGoodsRowFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'lorry_receipt_id', 'position', 'goods_type_id', 'package_type_id',
        'goods_description', 'package_count', 'actual_weight', 'charged_weight',
        'freight_rate_type', 'freight_rate', 'freight_amount', 'goods_value',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'package_count' => 'decimal:3',
            'actual_weight' => 'decimal:3',
            'charged_weight' => 'decimal:3',
            'freight_rate_type' => FreightRateType::class,
            'freight_rate' => 'decimal:4',
            'freight_amount' => 'decimal:2',
            'goods_value' => 'decimal:2',
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

    /** @return BelongsTo<LorryReceipt, $this> */
    public function lorryReceipt(): BelongsTo
    {
        return $this->belongsTo(LorryReceipt::class);
    }
}
