<?php

namespace App\Domains\Crm;

use App\Domains\Crm\Enums\LeadStatus;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionTrial;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\Crm\LeadOnboardingInvitation;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Collection;

/**
 * Central CRM Lead status synchronization with Owner / trial / subscription events.
 * Idempotent; does not invent Lead↔Owner links (uses completed onboarding invitations).
 */
final class LeadLifecycleService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
    ) {}

    /**
     * After eligible first-company trial activation.
     */
    public function syncTrialActivated(User $owner, Company $company, CompanySubscriptionTrial $trial): void
    {
        $leads = $this->leadsForOwner($owner);
        foreach ($leads as $lead) {
            $this->applySystemStatus(
                $lead,
                LeadStatus::Trial,
                sprintf(
                    'Trial activated for %s. Lead status changed from %%s to Trial.',
                    $company->name,
                ),
                [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'trial_id' => $trial->id,
                    'trial_ends_at' => $trial->ends_at->toIso8601String(),
                    'event' => 'trial_activated',
                ],
                allowedFrom: [LeadStatus::Onboarded, LeadStatus::OnboardingInviteSent, LeadStatus::Qualified, LeadStatus::New, LeadStatus::Contacted],
            );
        }
    }

    /**
     * After paid subscription activation for any of the Owner's companies.
     */
    public function syncSubscriptionActivated(User $owner, Company $company): void
    {
        $leads = $this->leadsForOwner($owner);
        foreach ($leads as $lead) {
            $this->applySystemStatus(
                $lead,
                LeadStatus::Converted,
                sprintf(
                    'Paid subscription activated for %s. Lead status changed from %%s to Converted.',
                    $company->name,
                ),
                [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'event' => 'subscription_activated',
                ],
                allowedFrom: [
                    LeadStatus::Trial,
                    LeadStatus::Onboarded,
                    LeadStatus::Expired,
                    LeadStatus::OnboardingInviteSent,
                    LeadStatus::Qualified,
                ],
            );
        }
    }

    /**
     * Trial or subscription expiration for an Owner relationship.
     * Skips Expired when another company still has active paid entitlement.
     */
    public function syncEntitlementExpired(User $owner, Company $company, string $reason = 'entitlement_expired'): void
    {
        if ($this->ownerHasActivePaidSubscription($owner)) {
            return;
        }

        $leads = $this->leadsForOwner($owner);
        foreach ($leads as $lead) {
            // Do not expire Converted customers while they retain paid coverage elsewhere
            // (handled above). Trial-only relationships may move to Expired.
            $this->applySystemStatus(
                $lead,
                LeadStatus::Expired,
                sprintf(
                    'Entitlement ended for %s. Lead status changed from %%s to Expired.',
                    $company->name,
                ),
                [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'event' => $reason,
                ],
                allowedFrom: [LeadStatus::Trial],
            );
        }
    }

    /**
     * Process expired trials (and paid-past-grace) for CRM Lead Expired sync.
     * Converted leads are retained after paid lapse (CRM acquisition outcome).
     * Expired applies to Trial relationships only; multi-company paid coverage skips Expire.
     *
     * @return int number of owners processed
     */
    public function processExpiredEntitlements(): int
    {
        $processedOwners = [];

        $expiredTrials = CompanySubscriptionTrial::query()
            ->where('ends_at', '<=', now())
            ->orderBy('ends_at')
            ->get();

        foreach ($expiredTrials as $trial) {
            $ownerId = (int) $trial->owner_user_id;
            if (isset($processedOwners[$ownerId])) {
                continue;
            }

            $owner = User::query()->find($ownerId);
            $company = Company::query()->find($trial->company_id);
            if (! $owner instanceof User || ! $company instanceof Company) {
                continue;
            }

            $this->syncEntitlementExpired($owner, $company, 'trial_expired');
            $processedOwners[$ownerId] = true;
        }

        return count($processedOwners);
    }

    /**
     * Non-destructive reconciliation for already-activated trials/subscriptions.
     *
     * @return array{updated: int, ambiguous: list<string>}
     */
    public function reconcileOwner(User $owner): array
    {
        $updated = 0;
        $ambiguous = [];

        $leads = $this->leadsForOwner($owner);
        if ($leads->isEmpty()) {
            return ['updated' => 0, 'ambiguous' => ["No onboarding-linked lead for owner #{$owner->id}"]];
        }

        if ($this->ownerHasActivePaidSubscription($owner)) {
            foreach ($leads as $lead) {
                if ($this->applySystemStatus(
                    $lead,
                    LeadStatus::Converted,
                    'Reconciled: Owner has an active paid subscription. Lead status changed from %s to Converted.',
                    ['event' => 'reconcile_paid'],
                    allowedFrom: [LeadStatus::Trial, LeadStatus::Onboarded, LeadStatus::Expired],
                )) {
                    $updated++;
                }
            }

            return ['updated' => $updated, 'ambiguous' => $ambiguous];
        }

        $activeTrial = CompanySubscriptionTrial::query()
            ->where('owner_user_id', $owner->id)
            ->where('ends_at', '>', now())
            ->orderByDesc('starts_at')
            ->first();

        if ($activeTrial instanceof CompanySubscriptionTrial) {
            $company = Company::query()->find($activeTrial->company_id);
            $companyName = $company instanceof Company ? $company->name : 'company';
            foreach ($leads as $lead) {
                if ($this->applySystemStatus(
                    $lead,
                    LeadStatus::Trial,
                    sprintf(
                        'Reconciled: Trial active for %s. Lead status changed from %%s to Trial.',
                        $companyName,
                    ),
                    [
                        'event' => 'reconcile_trial',
                        'company_id' => $activeTrial->company_id,
                        'trial_id' => $activeTrial->id,
                    ],
                    allowedFrom: [LeadStatus::Onboarded, LeadStatus::OnboardingInviteSent],
                )) {
                    $updated++;
                }
            }
        }

        return ['updated' => $updated, 'ambiguous' => $ambiguous];
    }

    /**
     * @return Collection<int, Lead>
     */
    public function leadsForOwner(User $owner): Collection
    {
        $leadIds = LeadOnboardingInvitation::query()
            ->where('completed_user_id', $owner->id)
            ->whereNotNull('used_at')
            ->pluck('lead_id')
            ->unique()
            ->filter()
            ->values();

        if ($leadIds->isEmpty()) {
            return collect();
        }

        return Lead::query()->whereIn('id', $leadIds->all())->get();
    }

    /**
     * @param  list<LeadStatus>  $allowedFrom
     * @param  array<string, mixed>  $meta
     */
    private function applySystemStatus(
        Lead $lead,
        LeadStatus $to,
        string $bodyTemplate,
        array $meta,
        array $allowedFrom,
    ): bool {
        if ($lead->status === $to) {
            return false;
        }

        // Converted / Disqualified / Lost are terminal CRM outcomes — do not overwrite.
        // Expired is reserved for Trial relationships (paid lapse keeps Converted).
        if ($lead->status->isTerminal()) {
            return false;
        }

        if (! in_array($lead->status, $allowedFrom, true)) {
            return false;
        }

        $from = $lead->status;

        $this->withPlatformTenant(function () use ($lead, $from, $to, $bodyTemplate, $meta): void {
            $this->auditedTransaction->run(function () use ($lead, $from, $to, $bodyTemplate, $meta): void {
                $before = [
                    'status' => $from->value,
                ];
                $lead->forceFill(['status' => $to])->save();

                $body = sprintf($bodyTemplate, $from->value);
                // Prefer human labels in activity body.
                $body = str_replace(
                    [$from->value, $to->value],
                    [ucfirst(str_replace('_', ' ', $from->value)), ucfirst(str_replace('_', ' ', $to->value))],
                    $body,
                );

                LeadActivity::query()->create([
                    'lead_id' => $lead->id,
                    'actor_user_id' => null,
                    'activity_type' => 'status_changed',
                    'body' => $body,
                    'meta' => array_merge($meta, [
                        'from' => $from->value,
                        'to' => $to->value,
                        'system' => true,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Lead::class,
                    $lead->id,
                    remark: "Lead lifecycle {$from->value} → {$to->value}",
                    before: $before,
                    after: ['status' => $to->value],
                );
            });
        });

        return true;
    }

    private function ownerHasActivePaidSubscription(User $owner): bool
    {
        // Active paid company subscriptions for companies owned by this user.
        if (! class_exists(CompanySubscription::class)) {
            return false;
        }

        return CompanySubscription::query()
            ->whereIn('company_id', Company::query()->where('owner_user_id', $owner->id)->select('id'))
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('paid_through_date')
                    ->orWhereDate('paid_through_date', '>=', now()->toDateString())
                    ->orWhereDate('grace_ends_on', '>=', now()->toDateString());
            })
            ->exists();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withPlatformTenant(callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::set(new TenantContext(isInternal: true));

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
