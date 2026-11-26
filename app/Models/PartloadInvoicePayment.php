<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property CarbonInterface $payment_date
 */
class PartloadInvoicePayment extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'partload_invoice_id',
        'amount_received',
        'tds_amount',
        'deduction_amount',
        'payment_date',
        'payment_mode',
        'bank_cash_account_id',
        'reference',
        'remark',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount_received' => 'decimal:2',
            'tds_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
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

    /** @return BelongsTo<PartloadInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PartloadInvoice::class, 'partload_invoice_id');
    }
}
