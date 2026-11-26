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
 * @property string $driver_party_id
 * @property string|null $trip_id
 * @property CarbonInterface $entry_date
 * @property string $entry_type
 * @property string $amount
 * @property string $payment_mode
 * @property string|null $bank_cash_account_id
 */
class DriverAdvanceEntry extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'driver_party_id',
        'trip_id',
        'entry_date',
        'entry_type',
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
            'entry_date' => 'date',
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

    /** @return BelongsTo<Party, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'driver_party_id');
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
}
