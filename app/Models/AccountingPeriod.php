<?php

namespace App\Models;

use App\Domains\Accounting\Enums\AccountingPeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property AccountingPeriodStatus $status
 */
class AccountingPeriod extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'label',
        'starts_on',
        'ends_on',
        'status',
        'closed_at',
        'closed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => AccountingPeriodStatus::class,
            'closed_at' => 'datetime',
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

    public function containsDate(Carbon|string $date): bool
    {
        $day = $date instanceof Carbon ? $date->toDateString() : (string) $date;

        return $day >= $this->starts_on->toDateString()
            && $day <= $this->ends_on->toDateString();
    }
}
