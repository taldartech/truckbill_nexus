<?php

namespace App\Support\Idempotency;

use App\Support\Idempotency\Exceptions\IdempotencyConflictException;
use App\Support\Idempotency\Exceptions\IdempotencyInProgressException;
use App\Support\Tenant\TenantContextHolder;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tenant-scoped idempotency primitive (ADR-009).
 *
 * Scope: callers must choose a stable scope string (e.g. "outbox.consumer:event.type").
 * Uniqueness: (scope, company_id, idempotency_key).
 * Replay of the same key + same request_hash returns the stored result without re-running.
 * Same key + different request_hash raises IdempotencyConflictException.
 */
class IdempotencyGuard
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn|array<string, mixed>|null
     */
    public function run(
        string $scope,
        string $idempotencyKey,
        Closure $callback,
        ?string $requestHash = null,
        ?int $ttlHours = null,
    ): mixed {
        $tenant = TenantContextHolder::require();
        $companyId = (string) $tenant->companyId;

        if ($companyId === '') {
            throw new IdempotencyConflictException('Company tenant identity is required for idempotency.');
        }

        $scope = trim($scope);
        $idempotencyKey = trim($idempotencyKey);

        if ($scope === '' || $idempotencyKey === '') {
            throw new IdempotencyConflictException('scope and idempotency_key are required.');
        }

        $ttlHours ??= (int) config('outbox.idempotency_ttl_hours', 72);
        $expiresAt = now()->addHours($ttlHours);

        $existing = IdempotencyRecord::query()
            ->where('scope', $scope)
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->handleExisting($existing, $requestHash, $callback);
        }

        try {
            $record = IdempotencyRecord::query()->create([
                'scope' => $scope,
                'idempotency_key' => $idempotencyKey,
                'company_id' => $companyId,
                'branch_id' => $tenant->branchId,
                'owner_id' => $tenant->ownerId,
                'request_hash' => $requestHash,
                'status' => IdempotencyStatus::Processing->value,
                'locked_at' => now(),
                'expires_at' => $expiresAt,
            ]);
        } catch (QueryException $e) {
            $existing = IdempotencyRecord::query()
                ->where('scope', $scope)
                ->where('company_id', $companyId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $e;
            }

            return $this->handleExisting($existing, $requestHash, $callback);
        }

        try {
            $result = $callback();
            $record->status = IdempotencyStatus::Completed->value;
            $record->result_payload = $this->normalizeResult($result);
            $record->completed_at = now();
            $record->locked_at = null;
            $record->save();

            return $result;
        } catch (Throwable $e) {
            $record->status = IdempotencyStatus::Failed->value;
            $record->locked_at = null;
            $record->save();

            throw $e;
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn|array<string, mixed>|null
     */
    private function handleExisting(IdempotencyRecord $existing, ?string $requestHash, Closure $callback): mixed
    {
        if ($existing->expires_at !== null && $existing->expires_at->isPast()) {
            $existing->delete();

            return $this->run(
                scope: $existing->scope,
                idempotencyKey: $existing->idempotency_key,
                callback: $callback,
                requestHash: $requestHash,
            );
        }

        if ($requestHash !== null && $existing->request_hash !== null && ! hash_equals($existing->request_hash, $requestHash)) {
            throw new IdempotencyConflictException('Idempotency key reused with a conflicting request fingerprint.');
        }

        if ($existing->status === IdempotencyStatus::Completed->value) {
            return $existing->result_payload;
        }

        if ($existing->status === IdempotencyStatus::Processing->value) {
            throw new IdempotencyInProgressException('Idempotent operation is already in progress.');
        }

        // Previous failure: allow retry by reclaiming the record.
        return DB::transaction(function () use ($existing, $requestHash, $callback) {
            /** @var IdempotencyRecord $locked */
            $locked = IdempotencyRecord::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === IdempotencyStatus::Completed->value) {
                return $locked->result_payload;
            }

            if ($locked->status === IdempotencyStatus::Processing->value) {
                throw new IdempotencyInProgressException('Idempotent operation is already in progress.');
            }

            $locked->status = IdempotencyStatus::Processing->value;
            $locked->request_hash = $requestHash ?? $locked->request_hash;
            $locked->locked_at = now();
            $locked->save();

            try {
                $result = $callback();
                $locked->status = IdempotencyStatus::Completed->value;
                $locked->result_payload = $this->normalizeResult($result);
                $locked->completed_at = now();
                $locked->locked_at = null;
                $locked->save();

                return $result;
            } catch (Throwable $e) {
                $locked->status = IdempotencyStatus::Failed->value;
                $locked->locked_at = null;
                $locked->save();

                throw $e;
            }
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeResult(mixed $result): ?array
    {
        if ($result === null) {
            return null;
        }

        if (is_array($result)) {
            return $result;
        }

        if (is_scalar($result)) {
            return ['value' => $result];
        }

        return ['value' => (string) Str::of(get_debug_type($result))];
    }
}
