<?php

namespace App\Models;

use Database\Factories\EnquiryGoodsRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class EnquiryGoodsRow extends Model
{
    /** @use HasFactory<EnquiryGoodsRowFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'enquiry_id', 'position', 'goods_type_id', 'package_type_id',
        'goods_description', 'package_count', 'actual_weight', 'charged_weight',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'package_count' => 'decimal:3',
            'actual_weight' => 'decimal:3',
            'charged_weight' => 'decimal:3',
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

    /** @return BelongsTo<Enquiry, $this> */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<OwnerCatalogueItem, $this> */
    public function goodsType(): BelongsTo
    {
        return $this->belongsTo(OwnerCatalogueItem::class, 'goods_type_id');
    }

    /** @return BelongsTo<OwnerCatalogueItem, $this> */
    public function packageType(): BelongsTo
    {
        return $this->belongsTo(OwnerCatalogueItem::class, 'package_type_id');
    }
}
