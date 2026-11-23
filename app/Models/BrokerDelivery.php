<?php

namespace App\Models;

use App\Domains\Broker\Enums\BrokerDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property BrokerDeliveryStatus $status
 * @property Carbon|null $delivery_date
 */
class BrokerDelivery extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'lorry_receipt_id', 'lorry_hire_id',
        'memo_number', 'pod_number', 'delivery_date', 'status', 'remarks', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'status' => BrokerDeliveryStatus::class,
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

    /** @return BelongsTo<LorryHire, $this> */
    public function lorryHire(): BelongsTo
    {
        return $this->belongsTo(LorryHire::class);
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

    /** @return BelongsTo<LorryReceipt, $this> */
    public function lorryReceipt(): BelongsTo
    {
        return $this->belongsTo(LorryReceipt::class);
    }
}
