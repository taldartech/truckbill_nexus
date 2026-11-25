<?php

namespace App\Domains\Subscription\Lifecycle;

use App\Domains\Crm\LeadLifecycleService;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\Company;
use App\Models\CompanySubscriptionTrial;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Outbox\OutboxWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;
use Throwable;

/**
 * First-company 15-day trial (Rules §15). Race-safe; no subsequent-company trial.
 */
final class SubscriptionTrialService
{
    public function __construct(
        private readonly SubscriptionClock $clock = new SubscriptionClock,
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly OutboxWriter $outbox = new OutboxWriter,
        private readonly LeadLifecycleService $leadLifecycle = new LeadLifecycleService,
    ) {}

    public function startFirstCompanyTrial(User $owner, Company $company): ?CompanySubscriptionTrial
    {
        if ($company->owner_user_id !== $owner->id) {
            throw new SubscriptionDomainException('Trial may only start for the company owner.');
        }

        $days = (int) config('subscription.trial.days', 15);
        $timezone = (string) config('subscription.timezone', 'UTC');

        try {
            return $this->tx->run(function () use ($owner, $company, $days, $timezone): CompanySubscriptionTrial {
                $previous = TenantContextHolder::get();
                TenantContextHolder::set(new TenantContext(
                    companyId: $company->id,
                    branchId: null,
                    ownerId: (string) $owner->id,
                    isInternal: false,
                    isOwnerScope: true,
                ));

                try {
                    // Lock owner row to serialize first-company trial grants.
                    User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();

                    $existingForOwner = CompanySubscriptionTrial::query()
                        ->where('owner_user_id', $owner->id)
                        ->lockForUpdate()
                        ->first();

                    if ($existingForOwner !== null) {
                        throw new SubscriptionDomainException('Owner has already received a first-company trial.');
                    }

                    $now = $this->clock->now();
                    $trial = CompanySubscriptionTrial::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'owner_user_id' => $owner->id,
                        'starts_at' => $now,
                        'ends_at' => $now->addDays($days),
                        'timezone' => $timezone,
                        'activated_notified_at' => $now,
                        'reminder_sent_for_days' => [],
                    ]);

                    $this->audit->write(
                        action: AuditAction::Created,
                        subjectType: 'company_subscription_trial',
                        subjectId: $trial->id,
                        remark: 'Start first-company trial',
                        before: null,
                        after: $trial->toArray(),
                    );

                    $this->outbox->enqueue('subscription.trial.activated', [
                        'company_id' => $company->id,
                        'owner_user_id' => $owner->id,
                        'trial_id' => $trial->id,
                        'ends_at' => $trial->ends_at->toIso8601String(),
                    ]);

                    $this->leadLifecycle->syncTrialActivated($owner, $company, $trial);

                    return $trial;
                } finally {
                    if ($previous === null) {
                        TenantContextHolder::clear();
                    } else {
                        TenantContextHolder::set($previous);
                    }
                }
            });
        } catch (Throwable $e) {
            if ($e instanceof SubscriptionDomainException) {
                return null;
            }

            // Unique constraint race: treat as subsequent-company / already granted.
            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Emit reminder outbox events for 7/3/1 day windows (idempotent per day marker).
     *
     * @return list<int> reminder days emitted this call
     */
    public function dispatchDueReminders(?Company $company = null): array
    {
        $now = $this->clock->now();
        $windows = array_map('intval', config('subscription.trial.reminder_days_before_expiry', [7, 3, 1]));
        $emitted = [];

        $query = CompanySubscriptionTrial::query()->where('ends_at', '>', $now);
        if ($company !== null) {
            $query->where('company_id', $company->id);
        }

        foreach ($query->get() as $trial) {
            $ends = $trial->ends_at->timezone(config('subscription.timezone', 'UTC'));
            $daysLeft = (int) $now->startOfDay()->diffInDays($ends->startOfDay(), false);
            $sent = is_array($trial->reminder_sent_for_days) ? $trial->reminder_sent_for_days : [];

            foreach ($windows as $day) {
                if ($daysLeft === $day && ! in_array($day, $sent, true)) {
                    $previous = TenantContextHolder::get();
                    TenantContextHolder::set(new TenantContext(
                        companyId: $trial->company_id,
                        branchId: null,
                        ownerId: (string) $trial->owner_user_id,
                        isInternal: false,
                        isOwnerScope: true,
                    ));
                    try {
                        $this->outbox->enqueue('subscription.trial.reminder', [
                            'company_id' => $trial->company_id,
                            'trial_id' => $trial->id,
                            'days_before_expiry' => $day,
                            'ends_at' => $ends->toIso8601String(),
                        ]);
                    } finally {
                        if ($previous === null) {
                            TenantContextHolder::clear();
                        } else {
                            TenantContextHolder::set($previous);
                        }
                    }
                    $sent[] = $day;
                    $emitted[] = $day;
                }
            }

            $trial->reminder_sent_for_days = array_values(array_unique($sent));
            $trial->save();
        }

        return $emitted;
    }

    private function isUniqueViolation(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Unique violation')
            || str_contains($message, 'duplicate key')
            || str_contains($message, 'UNIQUE constraint');
    }
}
