<?php

namespace App\Models;

use App\Domains\Company\Exceptions\CompanyDomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Immutable ownership transfer history (P2-WS-7).
 *
 * @property string $id
 * @property string $company_id
 * @property int $previous_owner_user_id
 * @property int $new_owner_user_id
 * @property int $initiated_by_user_id
 * @property string $remark
 * @property Carbon $transferred_at
 */
class OwnershipTransfer extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'previous_owner_user_id',
        'new_owner_user_id',
        'initiated_by_user_id',
        'remark',
        'transferred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OwnershipTransfer $transfer): void {
            if (! filled($transfer->getAttribute('id'))) {
                $transfer->setAttribute('id', (string) Str::uuid());
            }
        });

        static::updating(function (): void {
            throw new CompanyDomainException('Ownership transfer history is immutable.');
        });

        static::deleting(function (): void {
            throw new CompanyDomainException('Ownership transfer history cannot be deleted (DEC-003).');
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
     * @return BelongsTo<User, $this>
     */
    public function previousOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_owner_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function newOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_owner_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
