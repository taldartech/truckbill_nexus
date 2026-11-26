<?php

namespace App\Domains\Consignment;

use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;

/**
 * Resolves Unified LR create entry points from subscription entitlements.
 *
 * Broker/OwnFleet share one truck-based form; classification comes from Vehicle ownership.
 * Partload uses a dedicated bilty form without vehicle selection.
 * Never prompts the user to choose Broker/OwnFleet/Partload as an intermediate action.
 */
final class LrWorkflowContextResolver
{
    public const FORM_TRUCK = 'truck';

    public const FORM_PARTLOAD = 'partload';

    public function __construct(
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
    ) {}

    /**
     * Intersection of entitled modules as LR workflow contexts.
     *
     * @return list<LrWorkflowContext>
     */
    public function availableContexts(Company $company, User $user, Branch $branch): array
    {
        unset($user, $branch);

        $contexts = [];
        foreach ($this->entitlements->entitledModules($company) as $module) {
            $contexts[] = $this->entitlements->toWorkflowContext($module);
        }

        return $contexts;
    }

    public function hasTruckWorkflow(Company $company): bool
    {
        return $this->entitlements->isEntitled($company, LrWorkflowContext::Broker)
            || $this->entitlements->isEntitled($company, LrWorkflowContext::OwnFleet);
    }

    public function hasPartloadWorkflow(Company $company): bool
    {
        return $this->entitlements->isEntitled($company, LrWorkflowContext::Partload);
    }

    /**
     * @return array{
     *     status: 'ready',
     *     form_mode: 'truck'|'partload',
     *     context: ?LrWorkflowContext
     * }|array{
     *     status: 'blocked',
     *     reason: string
     * }
     */
    public function resolveForCreate(
        Company $company,
        User $user,
        Branch $branch,
        ?string $requestedOrigin = null,
    ): array {
        unset($user, $branch);

        $origin = $this->parseOrigin($requestedOrigin);
        $hasTruck = $this->hasTruckWorkflow($company);
        $hasPartload = $this->hasPartloadWorkflow($company);

        if ($origin === LrWorkflowContext::Partload) {
            if (! $hasPartload) {
                return [
                    'status' => 'blocked',
                    'reason' => 'Partload is not entitled for this company.',
                ];
            }

            return [
                'status' => 'ready',
                'form_mode' => self::FORM_PARTLOAD,
                'context' => LrWorkflowContext::Partload,
            ];
        }

        // Explicit Broker/OwnFleet origins are no longer used for create entry.
        // Users open the shared truck form; ownership classifies on submit.
        if ($origin === LrWorkflowContext::Broker || $origin === LrWorkflowContext::OwnFleet) {
            if (! $hasTruck) {
                return [
                    'status' => 'blocked',
                    'reason' => 'Broker/OwnFleet Lorry Receipt creation is not entitled for this company.',
                ];
            }

            return [
                'status' => 'ready',
                'form_mode' => self::FORM_TRUCK,
                'context' => null,
            ];
        }

        if ($hasTruck) {
            return [
                'status' => 'ready',
                'form_mode' => self::FORM_TRUCK,
                'context' => null,
            ];
        }

        if ($hasPartload) {
            return [
                'status' => 'ready',
                'form_mode' => self::FORM_PARTLOAD,
                'context' => LrWorkflowContext::Partload,
            ];
        }

        return [
            'status' => 'blocked',
            'reason' => app(CompanyCapabilityResolver::class)->denyMessage(
                $company,
                CompanyCapabilities::LR_UNIFIED,
            ),
        ];
    }

    public function labelFor(LrWorkflowContext $context): string
    {
        return match ($context) {
            LrWorkflowContext::Broker => 'Broker',
            LrWorkflowContext::OwnFleet => 'OwnFleet',
            LrWorkflowContext::Partload => 'Partload',
        };
    }

    public function parseOrigin(?string $requestedOrigin): ?LrWorkflowContext
    {
        if ($requestedOrigin === null) {
            return null;
        }

        $trimmed = strtolower(trim($requestedOrigin));
        if ($trimmed === '') {
            return null;
        }

        // Accept SubscriptionModule spelling for OwnFleet as a convenience.
        if ($trimmed === 'ownfleet') {
            $trimmed = LrWorkflowContext::OwnFleet->value;
        }

        // Dedicated Partload bilty entry may use context=partload or form=partload.
        if ($trimmed === 'bilty' || $trimmed === self::FORM_PARTLOAD) {
            return LrWorkflowContext::Partload;
        }

        return LrWorkflowContext::tryFrom($trimmed);
    }
}
