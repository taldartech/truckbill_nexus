<?php

namespace App\Models;

use Database\Factories\EnquiryConversionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Cardinality-neutral conversion header (PO-P4-CNV-CARDINALITY Pending).
 */
class EnquiryConversion extends Model
{
    /** @use HasFactory<EnquiryConversionFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'enquiry_id', 'idempotency_key', 'converted_by_user_id', 'converted_at',
    ];

    protected function casts(): array
    {
        return ['converted_at' => 'immutable_datetime'];
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

    /** @return HasMany<EnquiryConversionLorryReceipt, $this> */
    public function lorryReceiptLinks(): HasMany
    {
        return $this->hasMany(EnquiryConversionLorryReceipt::class);
    }
}
