<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon $snapshotted_at
 */
class LorryHireTdsSnapshot extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'lorry_hire_id', 'freight_basis',
        'calculated_rate', 'applicable_rate', 'tds_amount',
        'matched_rule_id', 'rule_code', 'rule_priority',
        'pan_status', 'declaration_status', 'goods_vehicle_count',
        'override_state', 'explanation', 'snapshotted_at',
    ];

    protected function casts(): array
    {
        return [
            'freight_basis' => 'decimal:2',
            'calculated_rate' => 'decimal:3',
            'applicable_rate' => 'decimal:3',
            'tds_amount' => 'decimal:2',
            'rule_priority' => 'integer',
            'goods_vehicle_count' => 'integer',
            'override_state' => 'boolean',
            'snapshotted_at' => 'immutable_datetime',
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
