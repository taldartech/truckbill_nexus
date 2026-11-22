<?php

namespace App\Domains\Subscription\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Explicit clock for deterministic lifecycle boundary tests.
 */
final class SubscriptionClock
{
    public function __construct(
        private ?CarbonImmutable $now = null,
    ) {}

    public function now(): CarbonImmutable
    {
        return ($this->now ?? CarbonImmutable::now(config('subscription.timezone', 'UTC')))
            ->timezone(config('subscription.timezone', 'UTC'));
    }

    public function freeze(CarbonInterface $at): void
    {
        $this->now = CarbonImmutable::instance($at)->timezone(config('subscription.timezone', 'UTC'));
    }

    public function advanceDays(int $days): void
    {
        $this->now = $this->now()->addDays($days);
    }
}
