<?php

namespace App\Models\Crm;

use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\Enums\LeadStatus;
use App\Models\Company;
use App\Models\User;
use Database\Factories\Crm\LeadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Internal CRM sales lead (Phase 2A).
 *
 * @property string $id
 * @property string $contact_name
 * @property string|null $email
 * @property string|null $mobile
 * @property string|null $company_name
 * @property LeadSource $source
 * @property InterestedProduct $interested_product
 * @property LeadStatus $status
 * @property int|null $assigned_to_user_id
 * @property int $created_by_user_id
 * @property string|null $remarks
 * @property string|null $email_normalized
 * @property bool $duplicate_override
 * @property string|null $duplicate_override_remark
 * @property string|null $converted_company_id
 * @property int|null $converted_user_id
 * @property Carbon|null $converted_at
 * @property Carbon|null $disqualified_at
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'crm_leads';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_name',
        'email',
        'mobile',
        'company_name',
        'source',
        'interested_product',
        'status',
        'assigned_to_user_id',
        'created_by_user_id',
        'remarks',
        'email_normalized',
        'duplicate_override',
        'duplicate_override_remark',
        'converted_company_id',
        'converted_user_id',
        'converted_at',
        'disqualified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'interested_product' => InterestedProduct::class,
            'status' => LeadStatus::class,
            'duplicate_override' => 'boolean',
            'converted_at' => 'datetime',
            'disqualified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function convertedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'converted_company_id');
    }

    /**
     * @return HasMany<LeadFollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(LeadFollowUp::class, 'lead_id');
    }

    /**
     * @return HasMany<LeadActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class, 'lead_id');
    }
}
