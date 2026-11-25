<?php

namespace App\Models;

use App\Domains\Accounting\Enums\AccountingVoucherStatus;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property AccountingVoucherType $voucher_type
 * @property AccountingVoucherStatus $status
 * @property Carbon $voucher_date
 * @property string $total_debit
 * @property string $total_credit
 */
class AccountingVoucher extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'accounting_period_id',
        'voucher_type',
        'voucher_number',
        'voucher_date',
        'status',
        'narration',
        'total_debit',
        'total_credit',
        'reversal_of_voucher_id',
        'posted_at',
        'posted_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'voucher_type' => AccountingVoucherType::class,
            'status' => AccountingVoucherStatus::class,
            'voucher_date' => 'date',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'posted_at' => 'datetime',
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

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<AccountingPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    /** @return HasMany<AccountingVoucherLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AccountingVoucherLine::class, 'accounting_voucher_id')->orderBy('line_no');
    }

    /** @return BelongsTo<self, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_voucher_id');
    }
}
