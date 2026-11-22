<?php

namespace App\Domains\Subscription\Lifecycle;

use App\Domains\Subscription\Enums\SubscriptionLifecycleStatus;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionTrial;
use Carbon\CarbonImmutable;

/**
 * Derives effective subscription lifecycle status from persisted facts.
 * Does not enforce route/action permissions (AUD-CON-006 Pending).
 */
final class SubscriptionStatusEvaluator
{
    public function __construct(
        private readonly SubscriptionClock $clock = new SubscriptionClock,
    ) {}

    /**
     * @return array{
     *     status: SubscriptionLifecycleStatus,
     *     company_admin_active: bool,
     *     trial_ends_at: ?string,
     *     paid_through_date: ?string,
     *     grace_ends_on: ?string,
     *     cancellation_effective_on: ?string,
     *     notes: list<string>
     * }
     */
    public function evaluate(Company $company): array
    {
        $now = $this->clock->now();
        $notes = [];
        $adminActive = $company->isActive();

        if (! $adminActive) {
            $notes[] = 'Company administrative status is deactivated; subscription term is not paused.';
        }

        $trial = CompanySubscriptionTrial::query()->where('company_id', $company->id)->first();
        $subscription = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->whereIn('status', ['draft', 'pending_payment', 'active', 'cancelled'])
            ->orderByDesc('created_at')
            ->first();

        if ($subscription !== null && $subscription->status === 'cancelled') {
            return $this->payload(SubscriptionLifecycleStatus::Cancelled, $adminActive, $trial, $subscription, $notes);
        }

        if ($subscription !== null && $subscription->status === 'draft') {
            return $this->payload(SubscriptionLifecycleStatus::Draft, $adminActive, $trial, $subscription, $notes);
        }

        if ($subscription !== null && $subscription->status === 'pending_payment') {
            return $this->payload(SubscriptionLifecycleStatus::PendingPayment, $adminActive, $trial, $subscription, $notes);
        }

        if ($subscription !== null && $subscription->status === 'active') {
            if ($subscription->cancellation_effective_on !== null
                && ! $now->startOfDay()->lessThan(CarbonImmutable::parse($subscription->cancellation_effective_on)->startOfDay())
            ) {
                $notes[] = 'Cancellation effective on common renewal date.';

                return $this->payload(SubscriptionLifecycleStatus::Cancelled, $adminActive, $trial, $subscription, $notes);
            }

            $paidThrough = $subscription->paid_through_date !== null
                ? CarbonImmutable::parse($subscription->paid_through_date)->startOfDay()
                : null;

            if ($paidThrough !== null && $now->startOfDay()->lessThanOrEqualTo($paidThrough)) {
                return $this->payload(SubscriptionLifecycleStatus::Active, $adminActive, $trial, $subscription, $notes);
            }

            $graceEnds = $subscription->grace_ends_on !== null
                ? CarbonImmutable::parse($subscription->grace_ends_on)->startOfDay()
                : null;

            if ($graceEnds !== null && $now->startOfDay()->lessThanOrEqualTo($graceEnds)) {
                $notes[] = 'Paid grace is conceptually view-only; action matrix Pending (AUD-CON-006).';

                return $this->payload(SubscriptionLifecycleStatus::PaidGrace, $adminActive, $trial, $subscription, $notes);
            }

            $notes[] = 'Past paid-through and grace; restricted/expired. Exact Branch action blocks Pending (AUD-CON-006).';

            return $this->payload(SubscriptionLifecycleStatus::Restricted, $adminActive, $trial, $subscription, $notes);
        }

        if ($trial !== null) {
            $ends = CarbonImmutable::parse($trial->ends_at)->timezone(config('subscription.timezone', 'UTC'));
            if ($now->lessThanOrEqualTo($ends)) {
                return $this->payload(SubscriptionLifecycleStatus::Trial, $adminActive, $trial, $subscription, $notes);
            }

            $notes[] = 'Trial expired with no grace. Action restrictions Pending (AUD-CON-006).';

            return $this->payload(SubscriptionLifecycleStatus::Restricted, $adminActive, $trial, $subscription, $notes);
        }

        return $this->payload(SubscriptionLifecycleStatus::None, $adminActive, null, $subscription, $notes);
    }

    /**
     * @param  list<string>  $notes
     * @return array{
     *     status: SubscriptionLifecycleStatus,
     *     company_admin_active: bool,
     *     trial_ends_at: ?string,
     *     paid_through_date: ?string,
     *     grace_ends_on: ?string,
     *     cancellation_effective_on: ?string,
     *     notes: list<string>
     * }
     */
    private function payload(
        SubscriptionLifecycleStatus $status,
        bool $adminActive,
        ?CompanySubscriptionTrial $trial,
        ?CompanySubscription $subscription,
        array $notes,
    ): array {
        return [
            'status' => $status,
            'company_admin_active' => $adminActive,
            'trial_ends_at' => $trial?->ends_at?->toIso8601String(),
            'paid_through_date' => $subscription?->paid_through_date?->toDateString(),
            'grace_ends_on' => $subscription?->grace_ends_on?->toDateString(),
            'cancellation_effective_on' => $subscription?->cancellation_effective_on?->toDateString(),
            'notes' => $notes,
        ];
    }
}
