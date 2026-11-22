<?php

namespace App\Models;

use App\Domains\Enquiry\Enums\QuotationSelectionStatus;
use Database\Factories\EnquiryQuotationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class EnquiryQuotation extends Model
{
    /** @use HasFactory<EnquiryQuotationFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'enquiry_id', 'transporter_party_id', 'quoted_freight', 'rate_basis',
        'vehicle_type_id', 'vehicle_quantity', 'advance_amount', 'payment_terms',
        'expected_delivery_duration', 'valid_until', 'extra_charges_amount',
        'extra_charges_description', 'remarks', 'selection_status',
    ];

    protected function casts(): array
    {
        return [
            'quoted_freight' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'extra_charges_amount' => 'decimal:2',
            'vehicle_quantity' => 'integer',
            'valid_until' => 'immutable_datetime',
            'selection_status' => QuotationSelectionStatus::class,
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

    /** @return BelongsTo<Enquiry, $this> */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function transporter(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'transporter_party_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
