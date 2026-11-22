<?php

namespace App\Models;

use Database\Factories\EnquiryConversionLorryReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EnquiryConversionLorryReceipt extends Model
{
    /** @use HasFactory<EnquiryConversionLorryReceiptFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'enquiry_conversion_id', 'lorry_receipt_id', 'position',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! filled($model->getAttribute('id'))) {
                $model->setAttribute('id', (string) Str::uuid());
            }
        });
    }

    /** @return BelongsTo<EnquiryConversion, $this> */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(EnquiryConversion::class, 'enquiry_conversion_id');
    }

    /** @return BelongsTo<LorryReceipt, $this> */
    public function lorryReceipt(): BelongsTo
    {
        return $this->belongsTo(LorryReceipt::class);
    }
}
