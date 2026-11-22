<?php

namespace App\Models;

use App\Domains\Enquiry\Enums\BroadcastRequestStatus;
use Database\Factories\EnquiryQuotationBroadcastRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EnquiryQuotationBroadcastRequest extends Model
{
    /** @use HasFactory<EnquiryQuotationBroadcastRequestFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'enquiry_id', 'batch_id', 'channels', 'status',
        'correlation_id', 'idempotency_key', 'actor_user_id', 'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'status' => BroadcastRequestStatus::class,
            'requested_at' => 'immutable_datetime',
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

    /** @return HasMany<EnquiryQuotationBroadcastTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(EnquiryQuotationBroadcastTarget::class, 'broadcast_request_id');
    }
}
