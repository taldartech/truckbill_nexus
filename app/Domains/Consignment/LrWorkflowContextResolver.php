<?php

namespace App\Domains\Consignment;

use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;

/**
 * Resolves Unified LR workflow context from subscription entitlements and
 * originating action (query origin/context). Never trusts client invention of
 * an unentitled context.
 */
final class LrWorkflowContextResolver
{
    public function __construct(
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
    ) {}

    /**
     * Intersection of entitled modules as LR workflow contexts.
     * Caller is responsible for LR create permission checks.
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

    /**
     * @return array{
     *     status: 'ready',
     *     context: LrWorkflowContext
     * }|array{
     *     status: 'choose',
     *     options: list<array{value: string, label: string, create_url: string}>
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
        $available = $this->availableContexts($company, $user, $branch);

        if ($available === []) {
            return [
                'status' => 'blocked',
                'reason' => 'No subscribed core module entitles Lorry Receipt creation. Activate Broker, OwnFleet, or Partload from the Owner Panel.',
            ];
        }

        $origin = $this->parseOrigin($requestedOrigin);

        if ($origin !== null) {
            foreach ($available as $context) {
                if ($context === $origin) {
                    return [
                        'status' => 'ready',
                        'context' => $context,
                    ];
                }
            }

            return [
                'status' => 'blocked',
                'reason' => 'The requested workflow context is not entitled for this company.',
            ];
        }

        if (count($available) === 1) {
            return [
                'status' => 'ready',
                'context' => $available[0],
            ];
        }

        return [
            'status' => 'choose',
            'options' => array_map(
                fn (LrWorkflowContext $context): array => [
                    'value' => $context->value,
                    'label' => $this->labelFor($context),
                    'create_url' => route('branch.lorry-receipts.create', [
                        'context' => $context->value,
                    ], absolute: false),
                ],
                $available,
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

        return LrWorkflowContext::tryFrom($trimmed);
    }
}
