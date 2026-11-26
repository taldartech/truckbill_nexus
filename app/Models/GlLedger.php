<?php

namespace App\Models;

use App\Domains\Accounting\Enums\AccountNature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property AccountNature $nature
 */
class GlLedger extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'account_group_id',
        'name',
        'nature',
        'party_id',
        'bank_cash_account_id',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'nature' => AccountNature::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
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

    /** @return BelongsTo<AccountGroup, $this> */
    public function accountGroup(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<BankCashAccount, $this> */
    public function bankCashAccount(): BelongsTo
    {
        return $this->belongsTo(BankCashAccount::class, 'bank_cash_account_id');
    }
}
