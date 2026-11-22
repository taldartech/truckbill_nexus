<?php

namespace App\Support\Audit;

use App\Support\Audit\Exceptions\AuditRemarkRequiredException;
use App\Support\Tenant\TenantContextHolder;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

/**
 * Controlled append-only audit writer (ADR-003).
 *
 * Tenant and actor come from trusted application context only — never from request payloads.
 */
class AuditWriter
{
    private static ?string $systemActorOverride = null;

    public function __construct(
        private readonly SensitiveFieldFilter $sensitiveFieldFilter = new SensitiveFieldFilter,
    ) {}

    /**
     * Temporarily represent a non-user system actor (e.g. website webhook intake).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function usingSystemActor(string $actorId, Closure $callback): mixed
    {
        $previous = self::$systemActorOverride;
        self::$systemActorOverride = $actorId;

        try {
            return $callback();
        } finally {
            self::$systemActorOverride = $previous;
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function write(
        AuditAction $action,
        string $subjectType,
        string|int $subjectId,
        ?string $remark = null,
        ?array $before = null,
        ?array $after = null,
        ?string $cancellationReason = null,
        mixed $cancellationAt = null,
        ?string $correlationId = null,
    ): AuditEntry {
        $tenant = TenantContextHolder::require();

        $normalizedRemark = $remark !== null ? trim($remark) : null;

        if ($action->requiresRemark() && ($normalizedRemark === null || $normalizedRemark === '')) {
            throw new AuditRemarkRequiredException(
                sprintf('A remark is required for audit action [%s].', $action->value)
            );
        }

        if ($action->requiresCancellationDetails()) {
            $normalizedCancellationReason = $cancellationReason !== null ? trim($cancellationReason) : null;

            if ($normalizedCancellationReason === null || $normalizedCancellationReason === '') {
                throw new AuditRemarkRequiredException('A cancellation reason is required for cancelled actions.');
            }

            if ($cancellationAt === null) {
                throw new InvalidArgumentException('cancellation_at is required for cancelled actions.');
            }
        }

        $subjectType = trim($subjectType);
        $subjectId = trim((string) $subjectId);

        if ($subjectType === '' || $subjectId === '') {
            throw new InvalidArgumentException('subject_type and subject_id are required.');
        }

        [$actorType, $actorId] = $this->resolveActor();

        return AuditEntry::insertAppendOnly([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'company_id' => $tenant->companyId,
            'branch_id' => $tenant->branchId,
            'owner_id' => $tenant->ownerId,
            'is_internal' => $tenant->isInternal,
            'is_owner_scope' => $tenant->isOwnerScope,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action->value,
            'before' => $this->sensitiveFieldFilter->filter($before),
            'after' => $this->sensitiveFieldFilter->filter($after),
            'remark' => $action === AuditAction::Created ? $normalizedRemark : $normalizedRemark,
            'cancellation_reason' => $action->requiresCancellationDetails()
                ? trim((string) $cancellationReason)
                : null,
            'cancellation_at' => $action->requiresCancellationDetails() ? $cancellationAt : null,
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function resolveActor(): array
    {
        $user = Auth::user();

        if ($user instanceof Authenticatable) {
            $id = $user->getAuthIdentifier();

            return ['user', $id !== null ? (string) $id : null];
        }

        return ['system', self::$systemActorOverride ?? 'phase1-system'];
    }

    /**
     * Fail closed helper when caller attempts to pass tenant fields (not accepted).
     *
     * @param  array<string, mixed>  $payload
     */
    public function assertNoClientTenantFields(array $payload): void
    {
        foreach (['company_id', 'branch_id', 'owner_id', 'actor_id', 'actor_type'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw new RuntimeException(
                    'Client-supplied actor/tenant fields are not accepted by AuditWriter.'
                );
            }
        }
    }
}
