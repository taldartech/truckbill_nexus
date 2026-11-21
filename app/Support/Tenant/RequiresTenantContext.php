<?php

namespace App\Support\Tenant;

use Closure;
use Illuminate\Contracts\Queue\Job;
use RuntimeException;

/**
 * Queue middleware: jobs fail closed without tenant context (TB-SEC-003).
 */
class RequiresTenantContext
{
    /**
     * @param  Job|object  $job
     */
    public function handle(object $job, Closure $next): mixed
    {
        if (! TenantContextHolder::has()) {
            throw new RuntimeException('Queued job executed without tenant context.');
        }

        return $next($job);
    }
}
