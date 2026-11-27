<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CompanyEnquiryWebhookCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property string $token_prefix
 * @property string $token_hash
 * @property string|null $label
 * @property string|null $receiving_branch_id
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $replaces_credential_id
 */
class CompanyEnquiryWebhookCredential extends Model
{
    /** @use HasFactory<CompanyEnquiryWebhookCredentialFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'token_prefix', 'token_hash', 'label', 'receiving_branch_id',
        'expires_at', 'last_used_at', 'revoked_at', 'created_by_user_id', 'revoked_by_user_id', 'replaces_credential_id',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function receivingBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'receiving_branch_id');
    }
}
