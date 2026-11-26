<?php

namespace App\Domains\Subscription\Entitlement;

use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Subscription\Enums\SubscriptionLifecycleStatus;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionTrial;
use Carbon\CarbonImmutable;

/**
 * Resolves catalogue core-module and add-on entitlements from active/historical
 * subscription items and first-company trial coverage.
 *
 * Operational mutations strictly require Active or active Trial status.
 * Historical read-only access during PaidGrace / Restricted / Cancelled preserves
 * only the modules actually subscribed or trialed by that Company.
 */
final class CoreModuleEntitlementResolver
{
    private readonly SubscriptionEntitlementGate $gate;

    private readonly SubscriptionClock $clock;

    public function __construct(
        ?SubscriptionEntitlementGate $gate = null,
        ?SubscriptionClock $clock = null,
    ) {
        $this->gate = $gate ?? app(SubscriptionEntitlementGate::class);
        $this->clock = $clock ?? app(SubscriptionClock::class);
    }

    /**
     * Operationally entitled core modules (Active paid subscription or active Trial).
     *
     * @return list<SubscriptionModule>
     */
    public function entitledModules(Company $company): array
    {
        $decision = $this->gate->evaluate($company);
        if (! $decision['company_admin_active'] || ! $decision['branch_mutations_allowed']) {
            return [];
        }

        if ($decision['status'] === SubscriptionLifecycleStatus::Active) {
            return $this->orderModules($this->modulesFromSubscription($company, ['active']));
        }

        if ($this->trialGrantsAllCoreModules($company, $decision)) {
            return SubscriptionModule::cases();
        }

        return [];
    }

    /**
     * Modules available for historical read-only views (Active/Trial modules plus
     * previously activated/trialed modules during PaidGrace/Restricted/Cancelled).
     *
     * @return list<SubscriptionModule>
     */
    public function historicalModules(Company $company): array
    {
        $decision = $this->gate->evaluate($company);
        if (! $decision['company_admin_active'] || ! $decision['branch_view_allowed']) {
            return [];
        }

        $active = $this->entitledModules($company);
        if ($active !== []) {
            return $active;
        }

        if (! in_array($decision['status'], [
            SubscriptionLifecycleStatus::PaidGrace,
            SubscriptionLifecycleStatus::Restricted,
            SubscriptionLifecycleStatus::Cancelled,
        ], true)) {
            return [];
        }

        $fromSubscription = $this->modulesFromSubscription($company, ['active', 'cancelled'], requireActivated: true);
        if ($fromSubscription !== []) {
            return $this->orderModules($fromSubscription);
        }

        if (CompanySubscriptionTrial::query()->where('company_id', $company->id)->exists()) {
            return SubscriptionModule::cases();
        }

        return [];
    }

    /**
     * Active subscription add-on codes (e.g., accounting).
     *
     * @return list<string>
     */
    public function activeAddonCodes(Company $company): array
    {
        $decision = $this->gate->evaluate($company);
        if (! $decision['company_admin_active'] || ! $decision['branch_mutations_allowed']) {
            return [];
        }

        if ($decision['status'] === SubscriptionLifecycleStatus::Active) {
            return $this->addonCodesFromSubscription($company, ['active']);
        }

        if ($this->trialGrantsAllCoreModules($company, $decision)
            && (bool) config('subscription.trial.include_accounting', false)) {
            return ['accounting'];
        }

        return [];
    }

    /**
     * Historical subscription add-on codes (includes PaidGrace/Restricted/Cancelled).
     *
     * @return list<string>
     */
    public function historicalAddonCodes(Company $company): array
    {
        $decision = $this->gate->evaluate($company);
        if (! $decision['company_admin_active'] || ! $decision['branch_view_allowed']) {
            return [];
        }

        $active = $this->activeAddonCodes($company);
        $historical = $this->addonCodesFromSubscription($company, ['active', 'cancelled'], requireActivated: true);

        return array_values(array_unique([...$active, ...$historical]));
    }

    public function isEntitled(Company $company, SubscriptionModule|LrWorkflowContext $module): bool
    {
        $target = $module instanceof LrWorkflowContext
            ? $this->toSubscriptionModule($module)
            : $module;

        return in_array($target, $this->entitledModules($company), true);
    }

    public function isHistoricallyEntitled(Company $company, SubscriptionModule|LrWorkflowContext $module): bool
    {
        $target = $module instanceof LrWorkflowContext
            ? $this->toSubscriptionModule($module)
            : $module;

        return in_array($target, $this->historicalModules($company), true);
    }

    public function toSubscriptionModule(LrWorkflowContext $context): SubscriptionModule
    {
        return match ($context) {
            LrWorkflowContext::Broker => SubscriptionModule::Broker,
            LrWorkflowContext::OwnFleet => SubscriptionModule::OwnFleet,
            LrWorkflowContext::Partload => SubscriptionModule::Partload,
        };
    }

    public function toWorkflowContext(SubscriptionModule $module): LrWorkflowContext
    {
        return match ($module) {
            SubscriptionModule::Broker => LrWorkflowContext::Broker,
            SubscriptionModule::OwnFleet => LrWorkflowContext::OwnFleet,
            SubscriptionModule::Partload => LrWorkflowContext::Partload,
        };
    }

    /**
     * @param  list<string>  $statuses
     * @return array<string, SubscriptionModule>
     */
    private function modulesFromSubscription(
        Company $company,
        array $statuses,
        bool $requireActivated = false,
    ): array {
        $today = $this->clock->now()->toDateString();

        $subscriptions = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->whereIn('status', $statuses)
            ->when($requireActivated, fn ($q) => $q->whereNotNull('activated_at'))
            ->when(
                $statuses === ['active'] && ! $requireActivated,
                fn ($q) => $q->where(fn ($sub) => $sub
                    ->whereNull('paid_through_date')
                    ->orWhereDate('paid_through_date', '>=', $today)),
            )
            ->whereNull('deleted_at')
            ->with('items')
            ->orderByDesc('activated_at')
            ->orderByDesc('created_at')
            ->get();

        if ($subscriptions->isEmpty()) {
            return [];
        }

        $modules = [];
        foreach ($subscriptions as $subscription) {
            foreach ($subscription->items as $item) {
                if (! is_string($item->module) || $item->module === '') {
                    continue;
                }

                $module = SubscriptionModule::tryFrom($item->module);
                if ($module !== null) {
                    $modules[$module->value] = $module;
                }
            }
        }

        return $modules;
    }

    /**
     * @param  list<string>  $statuses
     * @return list<string>
     */
    private function addonCodesFromSubscription(
        Company $company,
        array $statuses,
        bool $requireActivated = false,
    ): array {
        $today = $this->clock->now()->toDateString();

        $subscriptions = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->whereIn('status', $statuses)
            ->when($requireActivated, fn ($q) => $q->whereNotNull('activated_at'))
            ->when(
                $statuses === ['active'] && ! $requireActivated,
                fn ($q) => $q->where(fn ($sub) => $sub
                    ->whereNull('paid_through_date')
                    ->orWhereDate('paid_through_date', '>=', $today)),
            )
            ->whereNull('deleted_at')
            ->with(['items.addon'])
            ->orderByDesc('activated_at')
            ->orderByDesc('created_at')
            ->get();

        if ($subscriptions->isEmpty()) {
            return [];
        }

        $codes = [];
        foreach ($subscriptions as $subscription) {
            foreach ($subscription->items as $item) {
                if ($item->item_type !== 'addon' && $item->addon_id === null) {
                    continue;
                }
                $code = $item->code !== ''
                    ? $item->code
                    : ($item->addon !== null ? $item->addon->code : '');
                if ($code !== '') {
                    $codes[$code] = $code;
                }
            }
        }

        return array_values($codes);
    }

    /**
     * @param  array<string, SubscriptionModule>  $modules
     * @return list<SubscriptionModule>
     */
    private function orderModules(array $modules): array
    {
        $ordered = [];
        foreach (SubscriptionModule::cases() as $module) {
            if (isset($modules[$module->value])) {
                $ordered[] = $module;
            }
        }

        return $ordered;
    }

    /**
     * @param  array{
     *     status: SubscriptionLifecycleStatus,
     *     branch_mutations_allowed: bool,
     *     ...
     * }  $decision
     */
    private function trialGrantsAllCoreModules(Company $company, array $decision): bool
    {
        if (! $decision['branch_mutations_allowed']) {
            return false;
        }

        if (! $this->hasActiveTrial($company)) {
            return false;
        }

        // Paid Active / PaidGrace: module entitlement comes from subscription items only.
        if (in_array($decision['status'], [
            SubscriptionLifecycleStatus::Active,
            SubscriptionLifecycleStatus::PaidGrace,
        ], true)) {
            return false;
        }

        // Trial status, or draft/pending_payment kept open by an active first-company trial.
        return in_array($decision['status'], [
            SubscriptionLifecycleStatus::Trial,
            SubscriptionLifecycleStatus::Draft,
            SubscriptionLifecycleStatus::PendingPayment,
        ], true);
    }

    private function hasActiveTrial(Company $company): bool
    {
        $trial = CompanySubscriptionTrial::query()->where('company_id', $company->id)->first();
        if ($trial === null) {
            return false;
        }

        $ends = CarbonImmutable::parse($trial->ends_at)->timezone(config('subscription.timezone', 'UTC'));

        return $this->clock->now()->lessThanOrEqualTo($ends);
    }
}
