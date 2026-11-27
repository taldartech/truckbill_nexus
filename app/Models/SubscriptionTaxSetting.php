<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Single platform-wide GST rate applied to every subscription purchase, upgrade, add-on and renewal.
 *
 * @property string $id
 * @property string|null $gst_rate_id
 * @property string $gst_rate_percent
 * @property int|null $updated_by_user_id
 */
class SubscriptionTaxSetting extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'gst_rate_id',
        'gst_rate_percent',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'gst_rate_percent' => 'decimal:2',
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

    public static function current(): ?self
    {
        return self::query()->orderByDesc('updated_at')->first();
    }

    /**
     * @return BelongsTo<GstRate, $this>
     */
    public function gstRate(): BelongsTo
    {
        return $this->belongsTo(GstRate::class, 'gst_rate_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
