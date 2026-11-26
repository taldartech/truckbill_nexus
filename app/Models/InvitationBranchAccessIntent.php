<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Pending branch + role targets applied when a Customer invitation is accepted.
 *
 * @property string $id
 * @property string $invitation_id
 * @property string $branch_id
 * @property string $role_id
 */
class InvitationBranchAccessIntent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'invitation_id',
        'branch_id',
        'role_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (InvitationBranchAccessIntent $intent): void {
            if (! filled($intent->getAttribute('id'))) {
                $intent->setAttribute('id', (string) Str::uuid());
            }
        });
    }

    /**
     * @return BelongsTo<Invitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
