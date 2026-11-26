<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Platform (TruckBill SaaS service provider) billing & GST profile for subscription tax invoices.
 *
 * @property string $id
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string $registered_address
 * @property string|null $state_id
 * @property string $state_name
 * @property string|null $state_code
 * @property string $city
 * @property string $pin_code
 * @property string|null $gstin
 * @property string|null $pan
 * @property string $contact_number
 * @property string $email
 * @property string|null $logo_path
 * @property string|null $bank_name
 * @property string|null $bank_account_name
 * @property string|null $bank_account_number
 * @property string|null $bank_ifsc
 * @property string|null $bank_branch
 * @property string $gst_rate_percent
 * @property string $sac_code
 * @property string|null $hsn_code
 * @property string $invoice_prefix
 * @property int $invoice_next_sequence
 * @property string $credit_note_prefix
 * @property int $credit_note_next_sequence
 * @property bool $is_active
 */
class PlatformBillingProfile extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'legal_name',
        'trade_name',
        'registered_address',
        'state_id',
        'state_name',
        'state_code',
        'city',
        'pin_code',
        'gstin',
        'pan',
        'contact_number',
        'email',
        'logo_path',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'bank_ifsc',
        'bank_branch',
        'gst_rate_percent',
        'sac_code',
        'hsn_code',
        'invoice_prefix',
        'invoice_next_sequence',
        'credit_note_prefix',
        'credit_note_next_sequence',
        'is_active',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'gst_rate_percent' => 'decimal:2',
            'invoice_next_sequence' => 'integer',
            'credit_note_next_sequence' => 'integer',
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
     * @return array<string, mixed>
     */
    public function toImmutableSnapshot(): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'registered_address' => $this->registered_address,
            'state_id' => $this->state_id,
            'state_name' => $this->state_name,
            'state_code' => $this->state_code,
            'city' => $this->city,
            'pin_code' => $this->pin_code,
            'gstin' => $this->gstin,
            'pan' => $this->pan,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'logo_path' => $this->logo_path,
            'bank_name' => $this->bank_name,
            'bank_account_name' => $this->bank_account_name,
            'bank_account_number' => $this->bank_account_number,
            'bank_ifsc' => $this->bank_ifsc,
            'bank_branch' => $this->bank_branch,
            'gst_rate_percent' => (string) $this->gst_rate_percent,
            'sac_code' => $this->sac_code,
            'hsn_code' => $this->hsn_code,
        ];
    }
}
