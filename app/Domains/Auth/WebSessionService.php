<?php

namespace App\Domains\Auth;

use App\Domains\Auth\Exceptions\WebSessionException;
use App\Models\CustomerWebSession;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Web session policy enforcement (ADR-006 / P2-WS-4).
 *
 * Customer: exactly one active web session (DB registry + Laravel session destroy).
 * Internal: multiple concurrent web sessions; security events may revoke all/others.
 *
 * Persistence rationale: PostgreSQL `customer_web_sessions` is the Customer active-session
 * source of truth (works with redis/database/array session drivers). Laravel session store
 * remains authoritative for session payloads; the registry only stores the session id.
 * Atomic replacement uses a cache lock plus row lock. Fail closed when validity cannot
 * be established for Customer identities.
 */
final class WebSessionService
{
    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
    ) {}

    /**
     * After successful authentication and session regeneration.
     * Failed logins must never call this.
     */
    public function registerAuthenticatedSession(User $user, string $sessionId): void
    {
        if ($sessionId === '') {
            throw new WebSessionException('Cannot register an empty session id.');
        }

        if ($user->isCustomer()) {
            $this->replaceCustomerActiveSession($user, $sessionId);

            return;
        }

        // Internal Team: no single-session registry; concurrent sessions remain valid.
    }

    public function isCustomerSessionValid(User $user, string $sessionId): bool
    {
        if (! $user->isCustomer() || ! $user->isActive()) {
            return false;
        }

        if ($sessionId === '') {
            return false;
        }

        try {
            $active = CustomerWebSession::query()->where('user_id', $user->id)->first();
        } catch (Throwable $e) {
            Log::warning('customer_session_validity_check_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // Fail closed: missing registry means the session is not authorized.
        if ($active === null) {
            return false;
        }

        return hash_equals($active->session_id, $sessionId);
    }

    /**
     * Atomically claim the Customer active-session slot when no registry row exists.
     * Never overwrites a different active session id.
     */
    public function claimMissingCustomerSession(User $user, string $sessionId): bool
    {
        if (! $user->isCustomer() || ! $user->isActive() || $sessionId === '') {
            return false;
        }

        $lock = Cache::lock($this->lockKey($user->id), self::LOCK_SECONDS);

        try {
            if (! $lock->block(self::LOCK_WAIT_SECONDS)) {
                return false;
            }

            return (bool) DB::transaction(function () use ($user, $sessionId): bool {
                $existing = CustomerWebSession::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return hash_equals($existing->session_id, $sessionId);
                }

                CustomerWebSession::query()->create([
                    'user_id' => $user->id,
                    'session_id' => $sessionId,
                ]);

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('customer_session_claim_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Logout of the current session. Does not clear a newer Customer registry entry.
     * Does not destroy the current Laravel session — Fortify/session invalidate owns that.
     */
    public function handleLogout(User $user, string $sessionId): void
    {
        if ($user->isCustomer() && $sessionId !== '') {
            CustomerWebSession::query()
                ->where('user_id', $user->id)
                ->where('session_id', $sessionId)
                ->delete();
        }
    }

    /**
     * Password change / security event while authenticated: revoke other sessions,
     * keep the current session and refresh Customer registry.
     */
    public function revokeOtherSessionsForSecurityEvent(User $user, string $currentSessionId, string $remark): void
    {
        $this->revokeSessions($user, $currentSessionId, $remark, keepCurrent: true);
    }

    /**
     * Password reset / deactivation / full security revocation: destroy every web session.
     */
    public function revokeAllSessionsForSecurityEvent(User $user, string $remark): void
    {
        $this->revokeSessions($user, null, $remark, keepCurrent: false);
    }

    /**
     * Integration point for P2-WS-6 identity deactivation (and similar lifecycle events).
     */
    public function revokeAllSessionsForIdentityLifecycle(User $user, string $remark): void
    {
        $this->revokeAllSessionsForSecurityEvent($user, $remark);
    }

    private function replaceCustomerActiveSession(User $user, string $sessionId): void
    {
        $lock = Cache::lock($this->lockKey($user->id), self::LOCK_SECONDS);

        try {
            if (! $lock->block(self::LOCK_WAIT_SECONDS)) {
                throw new WebSessionException('Unable to acquire Customer session lock.');
            }

            DB::transaction(function () use ($user, $sessionId): void {
                $existing = CustomerWebSession::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null && ! hash_equals($existing->session_id, $sessionId)) {
                    $this->destroyLaravelSession($existing->session_id);
                }

                CustomerWebSession::query()->updateOrCreate(
                    ['user_id' => $user->id],
                    ['session_id' => $sessionId],
                );
            });
        } catch (WebSessionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new WebSessionException(
                'Customer session registration failed closed: '.$e->getMessage(),
                previous: $e,
            );
        } finally {
            $lock->release();
        }
    }

    private function revokeSessions(User $user, ?string $keepSessionId, string $remark, bool $keepCurrent): void
    {
        $this->auditedTransaction->run(function () use ($user, $keepSessionId, $remark, $keepCurrent): void {
            $this->withSecurityAuditContext($user, function () use ($user, $keepSessionId, $remark, $keepCurrent): void {
                $query = DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id);

                if ($keepCurrent && $keepSessionId !== null && $keepSessionId !== '') {
                    $query->where('id', '!=', $keepSessionId);
                }

                $ids = $query->pluck('id');

                foreach ($ids as $id) {
                    $this->destroyLaravelSession((string) $id);
                }

                if ($user->isCustomer()) {
                    if ($keepCurrent && $keepSessionId !== null && $keepSessionId !== '') {
                        CustomerWebSession::query()->updateOrCreate(
                            ['user_id' => $user->id],
                            ['session_id' => $keepSessionId],
                        );
                    } else {
                        CustomerWebSession::query()->where('user_id', $user->id)->delete();
                    }
                }

                $this->auditWriter->write(
                    AuditAction::Updated,
                    User::class,
                    $user->id,
                    remark: $remark,
                    before: [
                        'session_revocation' => $keepCurrent ? 'others' : 'all',
                        'identity_type' => $user->identity_type?->value,
                    ],
                    after: [
                        'session_revocation' => 'completed',
                        'kept_current' => $keepCurrent,
                    ],
                );
            });
        });
    }

    private function destroyLaravelSession(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        try {
            app('session')->getHandler()->destroy($sessionId);
        } catch (Throwable $e) {
            Log::warning('session_destroy_failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Ensure database-driver rows are removed even if the handler is not database-backed
        // in the current process (e.g. tests switching drivers).
        try {
            DB::table(config('session.table', 'sessions'))->where('id', $sessionId)->delete();
        } catch (Throwable) {
            // Session table may be unavailable for non-database drivers; handler destroy is primary.
        }
    }

    private function lockKey(int|string $userId): string
    {
        return 'auth:customer-web-session:'.$userId;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withSecurityAuditContext(User $user, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();

        // Security-plane audit context (non-empty). Not a Customer tenant claim.
        TenantContextHolder::inject(new TenantContext(
            companyId: null,
            branchId: null,
            ownerId: null,
            isInternal: true,
            isOwnerScope: false,
        ));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::inject($previous);
            }
        }
    }
}
