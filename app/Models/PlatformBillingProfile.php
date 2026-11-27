<?php

namespace App\Models;

use App\Support\Files\StoredFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Internal Company Billing Profile for TruckBill SaaS subscription invoicing.
 *
 * @property string $id
 * @property string $legal_name
 * @property string|null $pan
 * @property string|null $logo_path
 * @property string|null $logo_stored_file_id
 * @property string $contact_number
 * @property string $email
 * @property string $registered_address
 * @property string|null $city_id
 * @property string $city
 * @property string|null $state_id
 * @property string $state_name
 * @property string|null $state_code
 * @property string $pin_code
 * @property string|null $gstin
 * @property string|null $gst_rate_id
 * @property string $gst_rate_percent
 * @property string|null $bank_name
 * @property string|null $bank_account_name
 * @property string|null $bank_account_number
 * @property string|null $bank_ifsc
 * @property string|null $website
 * @property string|null $notes_terms
 * @property bool $is_active
 * @property int|null $updated_by_user_id
 */
class PlatformBillingProfile extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'legal_name',
        'pan',
        'logo_path',
        'logo_stored_file_id',
        'contact_number',
        'email',
        'registered_address',
        'city_id',
        'city',
        'state_id',
        'state_name',
        'state_code',
        'pin_code',
        'gstin',
        'gst_rate_id',
        'gst_rate_percent',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'bank_ifsc',
        'website',
        'notes_terms',
        'is_active',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'gst_rate_percent' => 'decimal:2',
            'is_active' => 'boolean',
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

    public static function currentActive(): ?self
    {
        return self::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function cityRecord(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    /**
     * @return BelongsTo<GstRate, $this>
     */
    public function gstRate(): BelongsTo
    {
        return $this->belongsTo(GstRate::class, 'gst_rate_id');
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function logoFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'logo_stored_file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function supplierRegistrationKey(): string
    {
        if (filled($this->gstin)) {
            return 'gstin:'.strtoupper(trim((string) $this->gstin));
        }

        return 'profile:'.$this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toImmutableSnapshot(): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'pan' => $this->pan,
            'logo_stored_file_id' => $this->logo_stored_file_id,
            'logo_path' => $this->logo_path,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'registered_address' => $this->registered_address,
            'city_id' => $this->city_id,
            'city' => $this->city,
            'state_id' => $this->state_id,
            'state_name' => $this->state_name,
            'state_code' => $this->state_code,
            'pin_code' => $this->pin_code,
            'gstin' => $this->gstin,
            'gst_rate_id' => $this->gst_rate_id,
            'gst_rate_percent' => (string) $this->gst_rate_percent,
            'bank_name' => $this->bank_name,
            'bank_account_name' => $this->bank_account_name,
            'bank_account_number' => $this->bank_account_number,
            'bank_ifsc' => $this->bank_ifsc,
            'website' => $this->website,
            'notes_terms' => $this->notes_terms,
            'sac_code' => (string) config('subscription.billing.default_sac_code', '998314'),
        ];
    }
}
