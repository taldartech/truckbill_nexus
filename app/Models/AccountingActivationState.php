<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property CarbonInterface $activated_at
 * @property CarbonInterface $cutover_date
 * @property CarbonInterface|null $setup_completed_at
 * @property string|null $opening_voucher_id
 * @property CarbonInterface|null $last_expired_at
 * @property CarbonInterface|null $last_catchup_from
 * @property CarbonInterface|null $last_catchup_to
 */
class AccountingActivationState extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'activated_at',
        'cutover_date',
        'setup_completed_at',
        'opening_voucher_id',
        'last_expired_at',
        'last_catchup_from',
        'last_catchup_to',
        'activated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'cutover_date' => 'date',
            'setup_completed_at' => 'datetime',
            'last_expired_at' => 'datetime',
            'last_catchup_from' => 'date',
            'last_catchup_to' => 'date',
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

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<AccountingVoucher, $this>
     */
    public function openingVoucher(): BelongsTo
    {
        return $this->belongsTo(AccountingVoucher::class, 'opening_voucher_id');
    }
}
