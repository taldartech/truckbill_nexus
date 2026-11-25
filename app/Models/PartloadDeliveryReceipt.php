<?php

namespace App\Models;

use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property PartloadDeliveryPaymentMode $payment_mode
 * @property Carbon|null $dr_date
 */
class PartloadDeliveryReceipt extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'dr_number', 'dr_date',
        'lorry_receipt_id', 'partload_inward_line_id', 'consignee_party_id',
        'payment_mode', 'payment_due_from_party_id', 'bank_cash_account_id',
        'freight', 'handling_charge', 'delivery_charge', 'stationery_charge',
        'demurrage', 'cartage_charge', 'other_charge', 'gst_amount', 'less_amount',
        'final_amount', 'status', 'remark', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'dr_date' => 'date',
            'payment_mode' => PartloadDeliveryPaymentMode::class,
            'freight' => 'decimal:2',
            'handling_charge' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'stationery_charge' => 'decimal:2',
            'demurrage' => 'decimal:2',
            'cartage_charge' => 'decimal:2',
            'other_charge' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'less_amount' => 'decimal:2',
            'final_amount' => 'decimal:2',
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

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<LorryReceipt, $this> */
    public function lorryReceipt(): BelongsTo
    {
        return $this->belongsTo(LorryReceipt::class, 'lorry_receipt_id');
    }

    /** @return BelongsTo<PartloadInwardLine, $this> */
    public function inwardLine(): BelongsTo
    {
        return $this->belongsTo(PartloadInwardLine::class, 'partload_inward_line_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function consignee(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'consignee_party_id');
    }
}
