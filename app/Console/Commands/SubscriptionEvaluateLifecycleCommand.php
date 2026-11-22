<?php

namespace App\Console\Commands;

use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Storage\StorageReconciliationService;
use App\Models\Company;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscription:evaluate-lifecycle {--storage : Also reconcile storage usage for active companies}')]
#[Description('Finalize due cancellations and optionally reconcile storage thresholds')]
class SubscriptionEvaluateLifecycleCommand extends Command
{
    public function handle(
        SubscriptionLifecycleService $lifecycle,
        StorageReconciliationService $storage,
    ): int {
        $finalized = $lifecycle->finalizeDueCancellations();
        $this->info("Cancellations finalized: {$finalized}");

        if ($this->option('storage')) {
            $alerts = 0;
            Company::query()->whereNull('deactivated_at')->whereNull('deleted_at')->orderBy('id')
                ->chunkById(100, function ($companies) use ($storage, &$alerts): void {
                    foreach ($companies as $company) {
                        $result = $storage->reconcile($company);
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
