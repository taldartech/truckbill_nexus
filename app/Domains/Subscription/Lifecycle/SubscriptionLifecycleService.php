<?php

namespace App\Domains\Subscription\Lifecycle;

use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionChangeProposal;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Confirmed lifecycle transitions (Rules §15). No paid activation without verified payment evidence.
 */
final class SubscriptionLifecycleService
{
    public function __construct(
        private readonly SubscriptionStatusEvaluator $evaluator = new SubscriptionStatusEvaluator,
        private readonly SubscriptionClock $clock = new SubscriptionClock,
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly CompanySubscriptionContractService $contracts = new CompanySubscriptionContractService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function effectiveStatus(Company $company): array
    {
        return $this->evaluator->evaluate($company);
    }

    public function requestCancellation(User $actor, CompanySubscription $subscription, string $remark): CompanySubscription
    {
        $this->assertOwner($actor, $subscription);
        if (trim($remark) === '') {
            throw new SubscriptionDomainException('Cancellation remark is required.');
        }

        return $this->tx->run(function () use ($actor, $subscription, $remark): CompanySubscription {
            $company = $subscription->company()->firstOrFail();

            return $this->withTenant($actor, $company, function () use ($actor, $subscription, $remark): CompanySubscription {
                /** @var CompanySubscription $locked */
                $locked = CompanySubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== 'active') {
                    throw new SubscriptionDomainException('Only active subscriptions may request cancellation.');
                }

                if ($locked->cancellation_requested_at !== null && $locked->cancelled_at === null) {
                    throw new SubscriptionDomainException('Cancellation is already pending.');
                }

                if ($locked->common_renewal_date === null) {
                    throw new SubscriptionDomainException('Common renewal date is required to schedule cancellation.');
                }

                $before = $locked->toArray();
                $locked->fill([
                    'cancellation_requested_at' => $this->clock->now(),
                    'cancellation_effective_on' => $locked->common_renewal_date->toDateString(),
                    'cancellation_requested_by' => $actor->id,
                    'cancellation_remark' => $remark,
                ]);
                $locked->save();

                $this->contracts->writeImmutableSnapshot($locked->load('items'), $actor, 'cancellation_requested');
                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: 'company_subscription',
                    subjectId: $locked->id,
                    remark: $remark,
                    before: $before,
                    after: $locked->fresh()?->toArray(),
                );

                return $locked->fresh() ?? $locked;
            });
        });
    }

    public function withdrawCancellation(User $actor, CompanySubscription $subscription, string $remark): CompanySubscription
    {
        $this->assertOwner($actor, $subscription);
        if (trim($remark) === '') {
            throw new SubscriptionDomainException('Withdrawal remark is required.');
        }

        return $this->tx->run(function () use ($actor, $subscription, $remark): CompanySubscription {
            $company = $subscription->company()->firstOrFail();

            return $this->withTenant($actor, $company, function () use ($actor, $subscription, $remark): CompanySubscription {
                /** @var CompanySubscription $locked */
                $locked = CompanySubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

                if ($locked->cancellation_requested_at === null || $locked->cancelled_at !== null) {
                    throw new SubscriptionDomainException('No pending cancellation to withdraw.');
                }

                $effective = CarbonImmutable::parse($locked->cancellation_effective_on)->startOfDay();
                if (! $this->clock->now()->startOfDay()->lessThan($effective)) {
                    throw new SubscriptionDomainException('Cancellation can only be withdrawn before the effective renewal date.');
                }

                $before = $locked->toArray();
                $locked->fill([
                    'cancellation_requested_at' => null,
                    'cancellation_effective_on' => null,
                    'cancellation_requested_by' => null,
                    'cancellation_remark' => null,
                ]);
                $locked->save();

                $this->contracts->writeImmutableSnapshot($locked->load('items'), $actor, 'cancellation_withdrawn');
                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: 'company_subscription',
                    subjectId: $locked->id,
                    remark: $remark,
                    before: $before,
                    after: $locked->fresh()?->toArray(),
                );

                return $locked->fresh() ?? $locked;
            });
        });
    }

    /**
     * Activate a draft/pending subscription after verified payment settlement (simulation or future gateway).
     */
    public function activateAfterVerifiedPayment(
        CompanySubscription $subscription,
        CarbonImmutable $paidThroughDate,
        ?User $actor = null,
        string $remark = 'Activate after verified payment',
    ): CompanySubscription {
        return $this->tx->run(function () use ($subscription, $paidThroughDate, $actor, $remark): CompanySubscription {
            $company = $subscription->company()->firstOrFail();

            return $this->withTenant($actor, $company, function () use ($subscription, $paidThroughDate, $actor, $remark): CompanySubscription {
                /** @var CompanySubscription $locked */
                $locked = CompanySubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

                if (! in_array($locked->status, ['draft', 'pending_payment'], true)) {
                    if ($locked->status === 'active' && $locked->activated_at !== null) {
                        return $locked; // idempotent
                    }
                    throw new SubscriptionDomainException('Subscription cannot be activated from status '.$locked->status);
                }

                $graceDays = (int) config('subscription.paid_grace.days', 10);
                $before = $locked->toArray();
                $locked->fill([
                    'status' => 'active',
                    'activated_at' => $this->clock->now(),
                    'paid_through_date' => $paidThroughDate->toDateString(),
                    'grace_ends_on' => $paidThroughDate->addDays($graceDays)->toDateString(),
                    'common_renewal_date' => $locked->common_renewal_date?->toDateString() ?? $paidThroughDate->toDateString(),
                ]);
                $locked->save();

                $this->contracts->writeImmutableSnapshot($locked->load('items'), $actor, 'activated_after_payment');
                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: 'company_subscription',
                    subjectId: $locked->id,
                    remark: $remark,
                    before: $before,
                    after: $locked->fresh()?->toArray(),
                );

                return $locked->fresh(['items']) ?? $locked;
            });
        });
    }

    /**
     * Schedule a downgrade/removal for next common renewal (not immediately entitling).
     *
     * @param  array<string, mixed>  $payload
     */
    public function scheduleChange(
        User $actor,
        Company $company,
        string $changeType,
        array $payload,
        ?CompanySubscription $subscription = null,
        ?string $remark = null,
    ): SubscriptionChangeProposal {
        $immediateTypes = ['upgrade', 'addon_add', 'capacity_increase', 'renewal'];
        $scheduledTypes = ['downgrade', 'addon_remove', 'capacity_decrease'];

        if (! in_array($changeType, [...$immediateTypes, ...$scheduledTypes], true)) {
            throw new SubscriptionDomainException('Unsupported change type.');
        }

        $effectiveOn = null;
        $status = 'awaiting_payment';

        if (in_array($changeType, $scheduledTypes, true)) {
            $sub = $subscription ?? CompanySubscription::query()
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->first();
            if ($sub?->common_renewal_date === null) {
                throw new SubscriptionDomainException('Scheduled changes require an active common renewal date.');
            }
            $effectiveOn = $sub->common_renewal_date->toDateString();
            $status = 'scheduled';
        }

        return $this->tx->run(function () use ($actor, $company, $changeType, $payload, $subscription, $remark, $effectiveOn, $status): SubscriptionChangeProposal {
            return $this->withTenant($actor, $company, function () use ($actor, $company, $changeType, $payload, $subscription, $remark, $effectiveOn, $status): SubscriptionChangeProposal {
                $proposal = SubscriptionChangeProposal::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'company_subscription_id' => $subscription?->id,
                    'change_type' => $changeType,
                    'status' => $status,
                    'effective_on' => $effectiveOn,
                    'catalogue_version_id' => $payload['catalogue_version_id'] ?? $subscription?->catalogue_version_id,
                    'payload' => $payload,
                    'pricing_breakdown' => $payload['pricing_breakdown'] ?? null,
                    'remark' => $remark,
                    'requested_by' => $actor->id,
                ]);

                $this->audit->write(
                    action: AuditAction::Created,
                    subjectType: 'subscription_change_proposal',
                    subjectId: $proposal->id,
                    remark: $remark ?? 'Schedule subscription change',
                    before: null,
                    after: $proposal->toArray(),
                );

                return $proposal;
            });
        });
    }

    /**
     * Apply a scheduled downgrade if usage still fits; otherwise retain current plan and flag retention.
     *
     * @return array{applied: bool, retained_current_plan: bool, proposal: SubscriptionChangeProposal}
     */
    public function applyScheduledDowngradeIfEligible(
        SubscriptionChangeProposal $proposal,
        int $currentBranchCount,
        int $currentSeatCount,
        int $proposedBranchLimit,
        int $proposedSeatLimit,
    ): array {
        if ($proposal->change_type !== 'downgrade' || $proposal->status !== 'scheduled') {
            throw new SubscriptionDomainException('Proposal is not a scheduled downgrade.');
        }

        return $this->tx->run(function () use ($proposal, $currentBranchCount, $currentSeatCount, $proposedBranchLimit, $proposedSeatLimit): array {
            /** @var SubscriptionChangeProposal $locked */
            $locked = SubscriptionChangeProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($currentBranchCount > $proposedBranchLimit || $currentSeatCount > $proposedSeatLimit) {
                $locked->status = 'rejected';
                $payload = $locked->payload;
                $payload['retention_reason'] = 'Usage exceeds proposed downgrade limits at effective date.';
                $locked->payload = $payload;
                $locked->save();

                return ['applied' => false, 'retained_current_plan' => true, 'proposal' => $locked];
            }

            $locked->status = 'applied';
            $locked->save();

            return ['applied' => true, 'retained_current_plan' => false, 'proposal' => $locked];
        });
    }

    private function assertOwner(User $actor, CompanySubscription $subscription): void
    {
        $company = $subscription->company()->firstOrFail();
        if (! $actor->isActive() || (! $actor->isSuperAdmin() && ! $company->isOwnedBy($actor))) {
            throw new SubscriptionDomainException('Not authorized for subscription lifecycle mutation.');
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(?User $actor, Company $company, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::set(new TenantContext(
            companyId: $company->id,
            branchId: null,
            ownerId: (string) $company->owner_user_id,
            isInternal: $actor?->isInternal() ?? false,
            isOwnerScope: $actor !== null && $actor->isCustomer() && $company->isOwnedBy($actor),
        ));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::set($previous);
            }
        }
    }
}
