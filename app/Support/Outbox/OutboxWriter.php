<?php

namespace App\Support\Outbox;

use App\Support\Audit\SensitiveFieldFilter;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Inserts outbox rows inside the caller's open DB transaction (ADR-009).
 * Does not publish — publish happens after commit via OutboxDispatcher.
 */
class OutboxWriter
{
    public function __construct(
        private readonly SensitiveFieldFilter $sensitiveFieldFilter = new SensitiveFieldFilter,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        string $eventType,
        array $payload,
        ?string $eventId = null,
        ?string $correlationId = null,
        ?int $maxAttempts = null,
    ): OutboxMessage {
        $tenant = TenantContextHolder::require();

        if ($tenant->companyId === null || $tenant->companyId === '') {
            throw new InvalidArgumentException('Company tenant identity is required for outbox events.');
        }

        $eventType = trim($eventType);
        if ($eventType === '') {
            throw new InvalidArgumentException('event_type is required.');
        }

        return OutboxMessage::query()->create([
            'event_id' => $eventId ?? (string) Str::uuid(),
            'event_type' => $eventType,
            'payload' => $this->sensitiveFieldFilter->filter($payload) ?? [],
            'company_id' => $tenant->companyId,
            'branch_id' => $tenant->branchId,
            'owner_id' => $tenant->ownerId,
            'is_internal' => $tenant->isInternal,
            'is_owner_scope' => $tenant->isOwnerScope,
            'correlation_id' => $correlationId,
            'status' => OutboxStatus::Pending->value,
            'attempts' => 0,
            'max_attempts' => $maxAttempts ?? (int) config('outbox.max_attempts', 5),
            'available_at' => now(),
        ]);
    }
}
