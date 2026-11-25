<?php

namespace App\Models\Crm;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $lead_id
 * @property string $email
 * @property string $email_normalized
 * @property string $token_hash
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $used_at
 * @property CarbonInterface|null $revoked_at
 * @property string $delivery_status
 * @property string|null $delivery_error
 * @property int|null $created_by_user_id
 * @property int|null $completed_user_id
 */
class LeadOnboardingInvitation extends Model
{
    use HasUuids;

    protected $table = 'lead_onboarding_invitations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'lead_id',
        'email',
        'email_normalized',
        'token_hash',
        'expires_at',
        'sent_at',
        'used_at',
        'revoked_at',
        'delivery_status',
        'delivery_error',
        'created_by_user_id',
        'completed_user_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function statusLabel(): string
    {
        if ($this->used_at !== null) {
            return 'completed';
        }
        if ($this->revoked_at !== null) {
            return 'revoked';
        }
        if ($this->expires_at->isPast()) {
            return 'expired';
        }
        if ($this->sent_at !== null) {
            return 'sent';
        }

        return 'created';
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * @return HasOne<LeadOnboardingSession, $this>
     */
    public function session(): HasOne
    {
        return $this->hasOne(LeadOnboardingSession::class, 'invitation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_user_id');
    }
}
