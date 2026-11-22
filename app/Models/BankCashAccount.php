<?php

namespace App\Models;

use Database\Factories\BankCashAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BankCashAccount extends Model
{
    /** @use HasFactory<BankCashAccountFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'account_kind',
        'name',
        'bank_name',
        'account_number',
        'ifsc',
        'account_holder_name',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'account_number',
    ];

    protected function casts(): array
    {
        return [
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

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function maskedAccountNumber(): ?string
    {
        $number = $this->account_number;
        if ($number === null || $number === '') {
            return null;
        }

        $length = strlen($number);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($number, -4);
    }

    /**
     * @param  Builder<BankCashAccount>  $query
     * @return Builder<BankCashAccount>
     */
    public function scopeVisibleInBranch(Builder $query, string $companyId, string $branchId): Builder
    {
        return $query->where('company_id', $companyId)
            ->where(function (Builder $inner) use ($branchId): void {
                $inner->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });
    }
}
