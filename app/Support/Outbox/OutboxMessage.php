<?php

namespace App\Support\Outbox;

use App\Support\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property string $company_id
 * @property string|null $branch_id
 * @property string|null $owner_id
 * @property bool $is_internal
 * @property bool $is_owner_scope
 * @property string|null $correlation_id
 * @property string $status
 * @property int $attempts
 * @property int $max_attempts
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $locked_at
 * @property string|null $locked_by
 * @property string|null $last_error
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $failed_at
 */
class OutboxMessage extends Model
{
    use HasUuids;

    protected $table = 'outbox_messages';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'event_id',
        'event_type',
        'payload',
        'company_id',
        'branch_id',
        'owner_id',
        'is_internal',
        'is_owner_scope',
        'correlation_id',
        'status',
        'attempts',
        'max_attempts',
        'available_at',
        'locked_at',
        'locked_by',
        'last_error',
        'published_at',
        'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_internal' => 'bool',
            'is_owner_scope' => 'bool',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function statusEnum(): OutboxStatus
    {
        return OutboxStatus::from($this->status);
    }

    public function tenantContext(): TenantContext
    {
        return new TenantContext(
            companyId: $this->company_id,
            branchId: $this->branch_id,
            ownerId: $this->owner_id,
            isInternal: $this->is_internal,
            isOwnerScope: $this->is_owner_scope,
        );
    }
}
