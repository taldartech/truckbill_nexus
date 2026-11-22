<?php

namespace App\Console\Commands;

use App\Domains\Subscription\Lifecycle\SubscriptionTrialService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscription:dispatch-trial-reminders')]
#[Description('Enqueue idempotent trial 7/3/1-day reminder outbox events')]
class SubscriptionDispatchTrialRemindersCommand extends Command
{
    public function handle(SubscriptionTrialService $trials): int
    {
        $emitted = $trials->dispatchDueReminders();
        $this->info('Trial reminder days emitted: '.implode(', ', $emitted !== [] ? $emitted : ['none']));

        return self::SUCCESS;
    }
}
