<?php

namespace App\Models;

use Database\Factories\LrEwayBillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LrEwayBill extends Model
{
    /** @use HasFactory<LrEwayBillFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'lorry_receipt_id', 'ewb_number', 'ewb_date', 'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'ewb_date' => 'date',
            'valid_until' => 'date',
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
