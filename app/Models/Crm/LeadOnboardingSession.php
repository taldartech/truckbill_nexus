<?php

namespace App\Models\Crm;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $invitation_id
 * @property CarbonInterface|null $email_verified_at
 * @property CarbonInterface|null $policy_accepted_at
 * @property array<int, string>|null $accepted_policies
 * @property string|null $password_hash
 * @property CarbonInterface|null $completed_at
 */
class LeadOnboardingSession extends Model
{
    use HasUuids;

    protected $table = 'lead_onboarding_sessions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'invitation_id',
        'email_verified_at',
        'policy_accepted_at',
        'accepted_policies',
        'password_hash',
        'completed_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'policy_accepted_at' => 'datetime',
            'accepted_policies' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LeadOnboardingInvitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(LeadOnboardingInvitation::class, 'invitation_id');
    }
}
