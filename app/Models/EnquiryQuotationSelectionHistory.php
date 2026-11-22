<?php

namespace App\Models;

use Database\Factories\EnquiryQuotationSelectionHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EnquiryQuotationSelectionHistory extends Model
{
    /** @use HasFactory<EnquiryQuotationSelectionHistoryFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'enquiry_id', 'quotation_id', 'action', 'actor_user_id', 'remark', 'selected_at',
    ];

    protected function casts(): array
    {
        return ['selected_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! filled($model->getAttribute('id'))) {
                $model->setAttribute('id', (string) Str::uuid());
            }
        });
    }

    /** @return BelongsTo<EnquiryQuotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(EnquiryQuotation::class, 'quotation_id');
    }
}
