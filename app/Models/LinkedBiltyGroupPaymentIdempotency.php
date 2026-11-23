<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LinkedBiltyGroupPaymentIdempotency extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'linked_bilty_group_id', 'idempotency_key', 'transporter_voucher_ids',
    ];

    protected function casts(): array
    {
        return [
            'transporter_voucher_ids' => 'array',
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

    /** @return BelongsTo<LinkedBiltyGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(LinkedBiltyGroup::class, 'linked_bilty_group_id');
    }
}
