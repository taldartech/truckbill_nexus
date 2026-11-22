<?php

namespace App\Domains\Subscription\Contract;

use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Pricing\SubscriptionPricingCalculator;
use App\Domains\Subscription\Support\DecimalMath;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionItem;
use App\Models\CompanySubscriptionSnapshot;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionCreditEntry;
use App\Models\SubscriptionCreditLedger;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Company subscription contract structures and immutable snapshots (P3-WS-4).
 * Does not perform payment activation (P3-WS-6 gate).
 */
final class CompanySubscriptionContractService
{
    public function __construct(
        private readonly SubscriptionPricingCalculator $pricing = new SubscriptionPricingCalculator,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  list<string>  $planIds
     * @param  list<string>  $addonIds
     */
    public function createDraftContract(
        User $actor,
        Company $company,
        SubscriptionCatalogueVersion $catalogue,
        array $planIds,
        SubscriptionTenure $tenure,
        CarbonInterface $commonRenewalDate,
        bool $renewalEligible = false,
        array $addonIds = [],
        string $remark = 'Create subscription draft contract',
    ): CompanySubscription {
        if ($planIds === []) {
            throw new SubscriptionDomainException('At least one core plan is required.');
        }

        $this->assertActorMayManageCompany($actor, $company);

        return $this->auditedTransaction->run(function () use (
            $actor,
            $company,
            $catalogue,
            $planIds,
            $tenure,
            $commonRenewalDate,
            $renewalEligible,
            $addonIds,
            $remark,
        ): CompanySubscription {
            $previous = TenantContextHolder::get();
            TenantContextHolder::set(new TenantContext(
                companyId: $company->id,
                branchId: null,
                ownerId: (string) $company->owner_user_id,
                isInternal: $actor->isInternal(),
                isOwnerScope: $actor->isCustomer() && $company->isOwnedBy($actor),
            ));

            try {
                $plans = SubscriptionPlan::query()
                    ->where('catalogue_version_id', $catalogue->id)
                    ->whereIn('id', $planIds)
                    ->where('is_active', true)
                    ->get();

                if ($plans->count() !== count(array_unique($planIds))) {
                    throw new SubscriptionDomainException('One or more plans are invalid for this catalogue.');
                }

                $modules = $plans->pluck('module')->unique()->values();
                if ($modules->count() !== $plans->count()) {
                    throw new SubscriptionDomainException('Only one plan per core module is allowed.');
                }

                $addons = SubscriptionAddon::query()
                    ->where('catalogue_version_id', $catalogue->id)
                    ->whereIn('id', $addonIds)
                    ->where('is_active', true)
                    ->get();

                if ($addons->count() !== count(array_unique($addonIds))) {
                    throw new SubscriptionDomainException('One or more add-ons are invalid for this catalogue.');
                }

                /** @var list<string> $corePrices */
                $corePrices = array_values($plans->pluck('annual_price')->map(fn ($p) => (string) $p)->all());

                $breakdown = $this->pricing->calculate(
                    coreModuleAnnualPrices: $corePrices,
                    tenure: $tenure,
                    renewalEligible: $renewalEligible,
                );

                $subscription = new CompanySubscription([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'catalogue_version_id' => $catalogue->id,
                    'status' => 'draft',
                    'common_renewal_date' => $commonRenewalDate->toDateString(),
                    'tenure' => $tenure->value,
                    'renewal_eligible' => $renewalEligible,
                    'core_module_count' => $plans->count(),
                    'list_annual_total' => $breakdown['base_annual'],
                    'discounted_annual_total' => $breakdown['after_bundle'],
                    'pricing_breakdown' => $breakdown,
                ]);
                $subscription->save();

                foreach ($plans as $plan) {
                    CompanySubscriptionItem::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'company_subscription_id' => $subscription->id,
                        'item_type' => 'plan',
                        'plan_id' => $plan->id,
                        'addon_id' => null,
                        'module' => $plan->module,
                        'code' => $plan->code,
                        'name' => $plan->name,
                        'annual_price_snapshot' => $plan->annual_price,
                        'capacity_units_snapshot' => $plan->capacity_units,
                    ]);
                }

                foreach ($addons as $addon) {
                    CompanySubscriptionItem::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'company_subscription_id' => $subscription->id,
                        'item_type' => 'addon',
                        'plan_id' => null,
                        'addon_id' => $addon->id,
                        'module' => null,
                        'code' => $addon->code,
                        'name' => $addon->name,
                        'annual_price_snapshot' => $addon->annual_price,
                        'capacity_units_snapshot' => null,
                    ]);
                }

                $this->writeImmutableSnapshot($subscription, $actor, 'draft_created');

                $this->audit->write(
                    action: AuditAction::Created,
                    subjectType: 'company_subscription',
                    subjectId: $subscription->id,
                    remark: $remark,
                    before: null,
                    after: $subscription->toArray(),
                );

                return $subscription->fresh(['items']) ?? $subscription;
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::set($previous);
                }
            }
        });
    }

    /**
     * Update an existing draft only. Does not activate payment or change catalogue version.
     *
     * @param  list<string>  $planIds
     * @param  list<string>  $addonIds
     */
    public function updateDraftContract(
        User $actor,
        CompanySubscription $subscription,
        array $planIds,
        SubscriptionTenure $tenure,
        CarbonInterface $commonRenewalDate,
        bool $renewalEligible = false,
        array $addonIds = [],
        string $remark = 'Update subscription draft contract',
        ?int $expectedSnapshotCount = null,
    ): CompanySubscription {
        if ($planIds === []) {
            throw new SubscriptionDomainException('At least one core plan is required.');
        }

        $company = $subscription->company()->firstOrFail();
        $this->assertActorMayManageCompany($actor, $company);

        if ($subscription->company_id !== $company->id) {
            throw new SubscriptionDomainException('Subscription does not belong to company.');
        }

        return $this->auditedTransaction->run(function () use (
            $actor,
            $company,
            $subscription,
            $planIds,
            $tenure,
            $commonRenewalDate,
            $renewalEligible,
            $addonIds,
            $remark,
            $expectedSnapshotCount,
        ): CompanySubscription {
            $previous = TenantContextHolder::get();
            TenantContextHolder::set(new TenantContext(
                companyId: $company->id,
                branchId: null,
                ownerId: (string) $company->owner_user_id,
                isInternal: $actor->isInternal(),
                isOwnerScope: $actor->isCustomer() && $company->isOwnedBy($actor),
            ));

            try {
                /** @var CompanySubscription $locked */
                $locked = CompanySubscription::query()
                    ->whereKey($subscription->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== 'draft') {
                    throw new SubscriptionDomainException('Only draft contracts may be edited.');
                }

                // Snapshot count is a discrete optimistic lock (timestamps are second-precision).
                if ($expectedSnapshotCount !== null) {
                    $currentSnapshotCount = CompanySubscriptionSnapshot::query()
                        ->where('company_subscription_id', $locked->id)
                        ->count();
                    if ($currentSnapshotCount !== $expectedSnapshotCount) {
                        throw new SubscriptionDomainException('Draft was modified by another session. Reload and try again.');
                    }
                }

                // Catalogue version is immutable on draft edit — never accept a client-supplied replacement.

                $catalogue = SubscriptionCatalogueVersion::query()
                    ->whereKey($locked->catalogue_version_id)
                    ->firstOrFail();

                $plans = SubscriptionPlan::query()
                    ->where('catalogue_version_id', $catalogue->id)
                    ->whereIn('id', $planIds)
                    ->where('is_active', true)
                    ->get();

                if ($plans->count() !== count(array_unique($planIds))) {
                    throw new SubscriptionDomainException('One or more plans are invalid for this catalogue.');
                }

                $modules = $plans->pluck('module')->unique()->values();
                if ($modules->count() !== $plans->count()) {
                    throw new SubscriptionDomainException('Only one plan per core module is allowed.');
                }

                $addons = SubscriptionAddon::query()
                    ->where('catalogue_version_id', $catalogue->id)
                    ->whereIn('id', $addonIds)
                    ->where('is_active', true)
                    ->get();

                if ($addons->count() !== count(array_unique($addonIds))) {
                    throw new SubscriptionDomainException('One or more add-ons are invalid for this catalogue.');
                }

                $beforeSnapshot = $this->writeImmutableSnapshot($locked->load('items'), $actor, 'draft_before_update');
                $before = $locked->fresh(['items'])?->toArray() ?? $locked->toArray();

                /** @var list<string> $corePrices */
                $corePrices = array_values($plans->pluck('annual_price')->map(fn ($p) => (string) $p)->all());

                $breakdown = $this->pricing->calculate(
                    coreModuleAnnualPrices: $corePrices,
                    tenure: $tenure,
                    renewalEligible: $renewalEligible,
                );

                $locked->fill([
                    'tenure' => $tenure->value,
                    'renewal_eligible' => $renewalEligible,
                    'common_renewal_date' => $commonRenewalDate->toDateString(),
                    'core_module_count' => $plans->count(),
                    'list_annual_total' => $breakdown['base_annual'],
                    'discounted_annual_total' => $breakdown['after_bundle'],
                    'pricing_breakdown' => $breakdown,
                ]);
                // Catalogue version is intentionally immutable for draft edits.
                $locked->save();

                CompanySubscriptionItem::query()
                    ->where('company_subscription_id', $locked->id)
                    ->delete();

                foreach ($plans as $plan) {
                    CompanySubscriptionItem::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'company_subscription_id' => $locked->id,
                        'item_type' => 'plan',
                        'plan_id' => $plan->id,
                        'addon_id' => null,
                        'module' => $plan->module,
                        'code' => $plan->code,
                        'name' => $plan->name,
                        'annual_price_snapshot' => $plan->annual_price,
                        'capacity_units_snapshot' => $plan->capacity_units,
                    ]);
                }

                foreach ($addons as $addon) {
                    CompanySubscriptionItem::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'company_subscription_id' => $locked->id,
                        'item_type' => 'addon',
                        'plan_id' => null,
                        'addon_id' => $addon->id,
                        'module' => null,
                        'code' => $addon->code,
                        'name' => $addon->name,
                        'annual_price_snapshot' => $addon->annual_price,
                        'capacity_units_snapshot' => null,
                    ]);
                }

                $updated = $locked->fresh(['items']) ?? $locked;
                $this->writeImmutableSnapshot($updated, $actor, 'draft_updated');

                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: 'company_subscription',
                    subjectId: $updated->id,
                    remark: $remark.' (before snapshot '.$beforeSnapshot->id.')',
                    before: $before,
                    after: $updated->toArray(),
                );

                return $updated;
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::set($previous);
                }
            }
        });
    }

    public function writeImmutableSnapshot(
        CompanySubscription $subscription,
        ?User $actor,
        string $reason,
    ): CompanySubscriptionSnapshot {
        $payload = [
            'subscription' => $subscription->withoutRelations()->toArray(),
            'items' => $subscription->items()->get()->map->toArray()->all(),
            'captured_at' => now()->toIso8601String(),
        ];

        $snapshot = new CompanySubscriptionSnapshot([
            'id' => (string) Str::uuid(),
            'company_id' => $subscription->company_id,
            'company_subscription_id' => $subscription->id,
            'reason' => $reason,
            'payload' => $payload,
            'created_by_user_id' => $actor?->id,
            'created_at' => now(),
        ]);
        $snapshot->save();

        return $snapshot;
    }

    public function ensureCreditLedger(Company $company, string $ledgerType, ?string $serviceKey = null): SubscriptionCreditLedger
    {
        $ledger = SubscriptionCreditLedger::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'ledger_type' => $ledgerType,
                'service_key' => $serviceKey,
            ],
            [
                'id' => (string) Str::uuid(),
                'balance' => '0.0000',
            ],
        );

        return $ledger;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function appendCreditEntry(
        SubscriptionCreditLedger $ledger,
        string $amount,
        string $entryType,
        string $reason,
        ?string $idempotencyKey = null,
        ?array $meta = null,
    ): SubscriptionCreditEntry {
        if ($idempotencyKey !== null) {
            $existing = SubscriptionCreditEntry::query()
                ->where('ledger_id', $ledger->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->auditedTransaction->run(function () use ($ledger, $amount, $entryType, $reason, $idempotencyKey, $meta): SubscriptionCreditEntry {
            $locked = SubscriptionCreditLedger::query()->whereKey($ledger->id)->lockForUpdate()->firstOrFail();
            $signed = match ($entryType) {
                'grant', 'release', 'adjust' => DecimalMath::of($amount, 4),
                'consume' => DecimalMath::mul($amount, '-1', 4),
                default => throw new SubscriptionDomainException('Invalid credit entry type.'),
            };

            $locked->balance = DecimalMath::add((string) $locked->balance, $signed, 4);
            $locked->save();

            return SubscriptionCreditEntry::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $locked->company_id,
                'ledger_id' => $locked->id,
                'amount' => $signed,
                'entry_type' => $entryType,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'meta' => $meta,
                'created_at' => now(),
            ]);
        });
    }

    private function assertActorMayManageCompany(User $actor, Company $company): void
    {
        if (! $actor->isActive()) {
            throw new SubscriptionDomainException('Actor is not active.');
        }

        if ($actor->isSuperAdmin() || $actor->isInternal()) {
            return;
        }

        if ($actor->isCustomer() && $company->isOwnedBy($actor)) {
            return;
        }

        throw new SubscriptionDomainException('Not authorized to manage company subscription contracts.');
    }
}
