<?php

namespace App\Support\Tenant;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Foundation harness job proving tenant context serialization (not a product feature).
 */
class ExampleTenantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAware;

    public static ?string $lastSeenCompanyId = null;

    public function handle(): void
    {
        self::$lastSeenCompanyId = TenantContextHolder::require()->companyId;
    }
}
