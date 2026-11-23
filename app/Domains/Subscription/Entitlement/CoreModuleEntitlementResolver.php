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
 * Resolves catalogue core-module entitlements from active subscription items
 * and first-company trial coverage.
 *
 * CompanySubscriptionTrial stores no per-module list; an active trial that
 * permits Branch mutations therefore grants all catalogue core modules
 * (Rules §15: trial covers all features subject to configurable limits).
 */
final class CoreModuleEntitlementResolver
{
    public function __construct(
        private readonly SubscriptionEntitlementGate $gate = new SubscriptionEntitlementGate,
        private readonly SubscriptionClock $clock = new SubscriptionClock,
    ) {}

    /**
     * @return list<SubscriptionModule>
     */
    public function entitledModules(Company $company): array
    {
        /** @var array<string, SubscriptionModule> $modules */
        $modules = [];

        foreach ($this->modulesFromActiveSubscription($company) as $module) {
            $modules[$module->value] = $module;
        }

        $decision = $this->gate->evaluate($company);
        if ($this->trialGrantsAllCoreModules($company, $decision)) {
            foreach (SubscriptionModule::cases() as $module) {
                $modules[$module->value] = $module;
            }
        }

        $ordered = [];
        foreach (SubscriptionModule::cases() as $module) {
            if (isset($modules[$module->value])) {
                $ordered[] = $module;
            }
        }

        return $ordered;
    }

    public function isEntitled(Company $company, SubscriptionModule|LrWorkflowContext $module): bool
    {
        $target = $module instanceof LrWorkflowContext
            ? $this->toSubscriptionModule($module)
            : $module;

        foreach ($this->entitledModules($company) as $entitled) {
            if ($entitled === $target) {
                return true;
            }
        }

        return false;
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
     * @return list<SubscriptionModule>
     */
    private function modulesFromActiveSubscription(Company $company): array
    {
        $subscription = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->with('items')
            ->orderByDesc('activated_at')
            ->first();

        if ($subscription === null) {
            return [];
        }

        $modules = [];
        foreach ($subscription->items as $item) {
            if (! is_string($item->module) || $item->module === '') {
                continue;
            }

            $module = SubscriptionModule::tryFrom($item->module);
            if ($module !== null) {
                $modules[$module->value] = $module;
            }
        }

        return array_values($modules);
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
