<?php

namespace App\Models;

use App\Domains\Broker\Enums\BookBalanceEntryType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $transporter_party_id
 * @property string|null $lorry_hire_id
 * @property string|null $transporter_voucher_id
 * @property BookBalanceEntryType $entry_type
 * @property string $amount
 * @property CarbonInterface|null $entry_date
 * @property string|null $payment_mode
 * @property string|null $bank_cash_account_id
 * @property string|null $reference_number
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $remark
 * @property int|null $created_by_user_id
 */
class TransporterBookBalanceEntry extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'transporter_party_id', 'lorry_hire_id',
        'transporter_voucher_id', 'amount', 'entry_type', 'entry_date', 'payment_mode',
        'bank_cash_account_id', 'reference_number', 'source_type', 'source_id',
        'idempotency_key', 'reversal_of_entry_id', 'remark', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'entry_type' => BookBalanceEntryType::class,
            'entry_date' => 'date',
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

    /** @return BelongsTo<LorryHire, $this> */
    public function lorryHire(): BelongsTo
    {
        return $this->belongsTo(LorryHire::class);
    }

    /** @return BelongsTo<TransporterVoucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(TransporterVoucher::class, 'transporter_voucher_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function transporter(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'transporter_party_id');
    }

    /** @return BelongsTo<BankCashAccount, $this> */
    public function bankCashAccount(): BelongsTo
    {
        return $this->belongsTo(BankCashAccount::class, 'bank_cash_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
