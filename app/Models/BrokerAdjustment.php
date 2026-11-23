<?php

namespace App\Models;

use App\Domains\Broker\Enums\BrokerAdjustmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property BrokerAdjustmentType $type
 * @property string $amount
 */
class BrokerAdjustment extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'lorry_hire_id', 'type', 'amount', 'weight', 'reason', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => BrokerAdjustmentType::class,
            'amount' => 'decimal:2',
            'weight' => 'decimal:3',
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
}
