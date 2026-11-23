<?php

namespace App\Models;

use App\Domains\Broker\Enums\BrokerAdjustmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property BrokerAdjustmentType $type
 * @property string $amount
 * @property Carbon|null $occurred_at
 */
class BrokerAdjustment extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'lorry_hire_id', 'type', 'amount', 'weight',
        'previous_weight', 'new_weight', 'quantity', 'rate', 'reason', 'occurred_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => BrokerAdjustmentType::class,
            'amount' => 'decimal:2',
            'weight' => 'decimal:3',
            'previous_weight' => 'decimal:3',
            'new_weight' => 'decimal:3',
            'quantity' => 'decimal:3',
            'rate' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
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
