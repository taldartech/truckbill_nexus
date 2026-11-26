<?php

namespace App\Models;

use App\Domains\Invitation\Enums\InvitationStatus;
use App\Domains\Invitation\Exceptions\InvitationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Customer company invitation (P2-WS-5). Never hard-deleted; lifecycle via status.
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $branch_id
 * @property string $email
 * @property string|null $invitee_name
 * @property string $token_hash
 * @property InvitationStatus $status
 * @property int $invited_by_user_id
 * @property int|null $accepted_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
class Invitation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'email',
        'invitee_name',
        'token_hash',
        'status',
        'invited_by_user_id',
        'accepted_user_id',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invitation $invitation): void {
            if (! filled($invitation->getAttribute('id'))) {
                $invitation->setAttribute('id', (string) Str::uuid());
            }
        });

        static::deleting(function (): void {
            throw new InvitationException('Hard deletion of invitations is prohibited.');
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
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    /**
     * @return HasMany<InvitationBranchAccessIntent, $this>
     */
    public function branchAccessIntents(): HasMany
    {
        return $this->hasMany(InvitationBranchAccessIntent::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::Pending;
    }
}
