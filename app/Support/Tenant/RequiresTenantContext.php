<?php

namespace App\Support\Tenant;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Queue middleware: restore serialized tenant context, fail closed if missing, always clear (TB-SEC-003).
 *
 * Runs around the job via Laravel's queue middleware pipeline (before handle()).
 */
class RequiresTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        try {
            if (method_exists($job, 'restoreTenantContext')) {
                $job->restoreTenantContext();
            }

            if (! TenantContextHolder::has()) {
                throw new RuntimeException('Queued job executed without tenant context.');
            }

            return $next($job);
        } catch (Throwable $e) {
            throw $e;
        } finally {
            TenantContextHolder::clear();
        }
    }
}
