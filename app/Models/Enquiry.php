<?php

namespace App\Models;

use App\Domains\Enquiry\Enums\EnquirySource;
use App\Domains\Enquiry\Enums\EnquiryStatus;
use Database\Factories\EnquiryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property EnquirySource $source
 * @property EnquiryStatus $status
 * @property Carbon|null $enquiry_date
 * @property Carbon|null $expected_loading_date
 * @property Carbon|null $converted_at
 */
class Enquiry extends Model
{
    /** @use HasFactory<EnquiryFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'source',
        'enquiry_number',
        'enquiry_date',
        'customer_party_id',
        'party_name',
        'consignor_party_id',
        'consignee_party_id',
        'from_state_id',
        'from_city_id',
        'to_state_id',
        'to_city_id',
        'external_customer_name',
        'external_customer_phone',
        'external_customer_email',
        'external_business_name',
        'external_contact_person_name',
        'pickup_location',
        'delivery_location',
        'vehicle_type_id',
        'vehicle_quantity',
        'expected_loading_date',
        'instructions',
        'pending_master_resolution',
        'assigned_user_id',
        'status',
        'created_by_user_id',
        'external_reference',
        'converted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => EnquirySource::class,
            'status' => EnquiryStatus::class,
            'enquiry_date' => 'date',
            'expected_loading_date' => 'date',
            'vehicle_quantity' => 'integer',
            'converted_at' => 'immutable_datetime',
            'pending_master_resolution' => 'array',
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

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function customerParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_party_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function consignorParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'consignor_party_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function consigneeParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'consignee_party_id');
    }

    /** @return BelongsTo<State, $this> */
    public function fromState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'from_state_id');
    }

    /** @return BelongsTo<City, $this> */
    public function fromCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'from_city_id');
    }

    /** @return BelongsTo<State, $this> */
    public function toState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'to_state_id');
    }

    /** @return BelongsTo<City, $this> */
    public function toCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'to_city_id');
    }

    /**
     * @return BelongsTo<OwnerCatalogueItem, $this>
     */
    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(OwnerCatalogueItem::class, 'vehicle_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<EnquiryGoodsRow, $this>
     */
    public function goodsRows(): HasMany
    {
        return $this->hasMany(EnquiryGoodsRow::class)->orderBy('position');
    }

    /**
     * @return HasMany<EnquiryAssignmentHistory, $this>
     */
    public function assignmentHistories(): HasMany
    {
        return $this->hasMany(EnquiryAssignmentHistory::class);
    }

    /**
     * @return HasMany<EnquiryFollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(EnquiryFollowUp::class);
    }

    /**
     * @return HasMany<EnquiryStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(EnquiryStatusHistory::class);
    }

    /**
     * @return HasMany<EnquiryQuotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(EnquiryQuotation::class);
    }

    /**
     * @return HasMany<EnquiryQuotationBroadcastRequest, $this>
     */
    public function broadcastRequests(): HasMany
    {
        return $this->hasMany(EnquiryQuotationBroadcastRequest::class);
    }

    /**
     * @return HasOne<EnquiryConversion, $this>
     */
    public function conversion(): HasOne
    {
        return $this->hasOne(EnquiryConversion::class);
    }
}
