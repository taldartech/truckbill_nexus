<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PartloadMainChallanLine extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'partload_main_challan_id',
        'partload_challan_id',
        'position',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! filled($model->getAttribute('id'))) {
                $model->setAttribute('id', (string) Str::uuid());
            }
        });
    }

    /** @return BelongsTo<PartloadMainChallan, $this> */
    public function mainChallan(): BelongsTo
    {
        return $this->belongsTo(PartloadMainChallan::class, 'partload_main_challan_id');
    }

    /** @return BelongsTo<PartloadChallan, $this> */
    public function challan(): BelongsTo
    {
        return $this->belongsTo(PartloadChallan::class, 'partload_challan_id');
    }
}
