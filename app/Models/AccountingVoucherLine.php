<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AccountingVoucherLine extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'accounting_voucher_id',
        'gl_ledger_id',
        'branch_id',
        'line_no',
        'debit_amount',
        'credit_amount',
        'narration',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'debit_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
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

    /** @return BelongsTo<GlLedger, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(GlLedger::class, 'gl_ledger_id');
    }
}
