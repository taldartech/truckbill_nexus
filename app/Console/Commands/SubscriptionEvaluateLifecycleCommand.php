<?php

namespace App\Console\Commands;

use App\Domains\Crm\LeadLifecycleService;
use App\Domains\Subscription\Capacity\CompanyCapacityService;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Storage\StorageReconciliationService;
use App\Models\Company;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscription:evaluate-lifecycle {--storage : Also reconcile storage usage for active companies}')]
#[Description('Finalize due cancellations/downgrades, sync CRM lead expiry, and optionally reconcile storage')]
class SubscriptionEvaluateLifecycleCommand extends Command
{
    public function handle(
        SubscriptionLifecycleService $lifecycle,
        CompanyCapacityService $capacity,
        StorageReconciliationService $storage,
        LeadLifecycleService $leadLifecycle,
    ): int {
        $finalized = $lifecycle->finalizeDueCancellations();
        $this->info("Cancellations finalized: {$finalized}");

        $downgrades = $lifecycle->finalizeDueScheduledDowngrades(
            fn ($company): array => $capacity->evaluate($company),
        );
        $this->info("Scheduled downgrades resolved: {$downgrades}");

        $expiredOwners = $leadLifecycle->processExpiredEntitlements();
        $this->info("CRM lead expiry owners processed: {$expiredOwners}");

        if ($this->option('storage')) {
            $allocation = (int) config('subscription.storage.default_allocation_bytes', 0);
            $alerts = 0;
            Company::query()->whereNull('deactivated_at')->whereNull('deleted_at')->orderBy('id')
                ->chunkById(100, function ($companies) use ($storage, $allocation, &$alerts): void {
                    foreach ($companies as $company) {
                        $result = $storage->reconcile(
                            $company,
                            $allocation > 0 ? $allocation : null,
                        );
                        if ($result['alert'] || $result['block'] || $result['mismatches'] !== []) {
                            $alerts++;
                        }
                    }
                });
            $this->info("Companies with storage alerts/mismatches: {$alerts}");
        }

        return self::SUCCESS;
    }
}
