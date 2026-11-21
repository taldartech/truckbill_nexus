<?php

namespace App\Support\Tenant;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Foundation harness job that throws after context is available.
 */
class FailingTenantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAware;

    public function handle(): void
    {
        TenantContextHolder::require();

        throw new RuntimeException('Harness job failed intentionally.');
    }
}
