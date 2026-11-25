<?php

namespace App\Models;

use App\Domains\Accounting\Enums\AccountingPostingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AccountingPostingLink extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'source_type',
        'source_id',
        'posting_purpose',
        'accounting_voucher_id',
        'status',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => AccountingPostingStatus::class,
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

    /** @return BelongsTo<AccountingVoucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(AccountingVoucher::class, 'accounting_voucher_id');
    }
}
