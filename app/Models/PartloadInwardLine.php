<?php

namespace App\Models;

use App\Domains\Partload\Enums\PartloadInwardDisposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property PartloadInwardDisposition $disposition
 */
class PartloadInwardLine extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'partload_inward_register_id', 'lorry_receipt_id',
        'partload_challan_line_id', 'position', 'packages', 'weight_kg',
        'disposition', 'remark',
    ];

    protected function casts(): array
    {
        return [
            'packages' => 'decimal:3',
            'weight_kg' => 'decimal:3',
            'disposition' => PartloadInwardDisposition::class,
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

    /** @return BelongsTo<PartloadInwardRegister, $this> */
    public function inwardRegister(): BelongsTo
    {
        return $this->belongsTo(PartloadInwardRegister::class, 'partload_inward_register_id');
    }

    /** @return BelongsTo<LorryReceipt, $this> */
    public function lorryReceipt(): BelongsTo
    {
        return $this->belongsTo(LorryReceipt::class, 'lorry_receipt_id');
    }

    /** @return BelongsTo<PartloadChallanLine, $this> */
    public function challanLine(): BelongsTo
    {
        return $this->belongsTo(PartloadChallanLine::class, 'partload_challan_line_id');
    }

    /** @return HasOne<PartloadDeliveryReceipt, $this> */
    public function deliveryReceipt(): HasOne
    {
        return $this->hasOne(PartloadDeliveryReceipt::class, 'partload_inward_line_id');
    }
}
