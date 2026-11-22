<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property int $owner_user_id
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property string $timezone
 * @property CarbonInterface|null $activated_notified_at
 * @property list<int>|null $reminder_sent_for_days
 */
class CompanySubscriptionTrial extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'owner_user_id',
        'starts_at',
        'ends_at',
        'timezone',
        'activated_notified_at',
        'reminder_sent_for_days',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'activated_notified_at' => 'datetime',
            'reminder_sent_for_days' => 'array',
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
}
