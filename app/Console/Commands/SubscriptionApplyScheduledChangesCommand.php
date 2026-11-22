<?php

namespace App\Console\Commands;

use App\Domains\Subscription\Capacity\CompanyCapacityService;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscription:apply-scheduled-changes')]
#[Description('Finalize due subscription cancellations and scheduled downgrade proposals')]
class SubscriptionApplyScheduledChangesCommand extends Command
{
    public function handle(
        SubscriptionLifecycleService $lifecycle,
        CompanyCapacityService $capacity,
    ): int {
        $cancelled = $lifecycle->finalizeDueCancellations();
        $this->info("Cancellations finalized: {$cancelled}");

        $downgrades = $lifecycle->finalizeDueScheduledDowngrades(
            fn ($company): array => $capacity->evaluate($company),
        );
        $this->info("Scheduled downgrades resolved: {$downgrades}");

        return self::SUCCESS;
    }
}
