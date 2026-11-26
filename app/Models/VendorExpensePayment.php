<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $vendor_party_id
 * @property string|null $trip_expense_id
 * @property CarbonInterface $payment_date
 * @property string $amount
 * @property string $payment_mode
 * @property string|null $bank_cash_account_id
 */
class VendorExpensePayment extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'vendor_party_id',
        'trip_expense_id',
        'payment_date',
        'amount',
        'payment_mode',
        'bank_cash_account_id',
        'reference',
        'remark',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
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

    /** @return BelongsTo<Party, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'vendor_party_id');
    }

    /** @return BelongsTo<TripExpense, $this> */
    public function tripExpense(): BelongsTo
    {
        return $this->belongsTo(TripExpense::class, 'trip_expense_id');
    }
}
