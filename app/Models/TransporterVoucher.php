<?php

namespace App\Models;

use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property VoucherStatus $status
 * @property VoucherPaymentType $payment_type
 * @property VoucherPaymentMode|null $payment_mode
 * @property string $amount
 * @property Carbon|null $voucher_date
 * @property-read BankCashAccount|null $bankCashAccount
 * @property-read Party|null $transporter
 * @property-read LorryHire|null $lorryHire
 */
class TransporterVoucher extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'lorry_hire_id', 'linked_bilty_group_id', 'voucher_number',
        'voucher_date', 'transporter_party_id', 'vehicle_id', 'amount', 'payment_type', 'payment_mode',
        'status', 'bank_cash_account_id', 'cash_reference', 'pump_reference', 'pump_vendor_party_id',
        'remark', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'amount' => 'decimal:2',
            'payment_type' => VoucherPaymentType::class,
            'payment_mode' => VoucherPaymentMode::class,
            'status' => VoucherStatus::class,
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

    /** @return BelongsTo<Party, $this> */
    public function transporter(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'transporter_party_id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<LinkedBiltyGroup, $this> */
    public function linkedBiltyGroup(): BelongsTo
    {
        return $this->belongsTo(LinkedBiltyGroup::class, 'linked_bilty_group_id');
    }

    /** @return BelongsTo<BankCashAccount, $this> */
    public function bankCashAccount(): BelongsTo
    {
        return $this->belongsTo(BankCashAccount::class, 'bank_cash_account_id');
    }
}
