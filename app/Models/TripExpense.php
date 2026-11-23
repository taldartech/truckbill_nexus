<?php

namespace App\Models;

use Database\Factories\TripExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon $expense_date
 */
class TripExpense extends Model
{
    /** @use HasFactory<TripExpenseFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'trip_id',
        'expense_category_id',
        'expense_date',
        'amount',
        'payment_mode_id',
        'paid_from_bank_cash_account_id',
        'remarks',
        'is_diesel',
        'diesel_litres',
        'diesel_rate_per_litre',
        'diesel_odometer',
        'planned_diesel_quantity',
        'planned_diesel_rate',
        'planned_diesel_amount',
        'planned_efficiency',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'is_diesel' => 'boolean',
            'diesel_litres' => 'decimal:3',
            'diesel_rate_per_litre' => 'decimal:4',
            'diesel_odometer' => 'decimal:1',
            'planned_diesel_quantity' => 'decimal:3',
            'planned_diesel_rate' => 'decimal:4',
            'planned_diesel_amount' => 'decimal:2',
            'planned_efficiency' => 'decimal:3',
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

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<OwnerCatalogueItem, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(OwnerCatalogueItem::class, 'expense_category_id');
    }

    /** @return BelongsTo<PaymentMode, $this> */
    public function paymentMode(): BelongsTo
    {
        return $this->belongsTo(PaymentMode::class, 'payment_mode_id');
    }

    /** @return BelongsTo<BankCashAccount, $this> */
    public function paidFromAccount(): BelongsTo
    {
        return $this->belongsTo(BankCashAccount::class, 'paid_from_bank_cash_account_id');
    }
}
