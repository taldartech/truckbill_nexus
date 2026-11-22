<?php

namespace App\Models;

use App\Domains\Enquiry\Enums\EnquiryStatus;
use Database\Factories\EnquiryStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EnquiryStatusHistory extends Model
{
    /** @use HasFactory<EnquiryStatusHistoryFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'enquiry_id', 'from_status', 'to_status', 'actor_user_id', 'remark', 'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => EnquiryStatus::class,
            'to_status' => EnquiryStatus::class,
            'changed_at' => 'immutable_datetime',
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
}
