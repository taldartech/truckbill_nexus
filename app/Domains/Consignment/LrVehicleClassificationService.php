<?php

namespace App\Domains\Consignment;

use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Vehicle;
use Illuminate\Validation\ValidationException;

/**
 * Resolves Broker/OwnFleet LR workflow context from trusted Vehicle ownership.
 * Never trusts client-submitted ownership or workflow_context overrides.
 */
final class LrVehicleClassificationService
{
    public function __construct(
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
    ) {}

    /**
     * Load a branch-visible active vehicle and resolve its entitled workflow context.
     *
     * @throws ValidationException
     */
    public function resolveFromVehicleId(
        Company $company,
        Branch $branch,
        string $vehicleId,
    ): array {
        $vehicle = Vehicle::query()
            ->visibleInBranch($company->id, $branch->id)
            ->whereKey($vehicleId)
            ->first();

        if ($vehicle === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Selected vehicle was not found or is not visible in this branch.',
            ]);
        }

        if (! $vehicle->is_active) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Selected vehicle is inactive.',
            ]);
        }

        $context = $this->contextForVehicle($vehicle);
        $this->assertEntitled($company, $context, $vehicle);

        return [
            'vehicle' => $vehicle,
            'context' => $context,
        ];
    }

    public function contextForVehicle(Vehicle $vehicle): LrWorkflowContext
    {
        $ownership = $vehicle->ownership;

        if ($ownership === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle ownership is not classified. Open Vehicle Master and set Own or Market before creating an LR.',
            ]);
        }

        return match ($ownership) {
            VehicleOwnership::Own => LrWorkflowContext::OwnFleet,
            VehicleOwnership::Market => LrWorkflowContext::Broker,
        };
    }

    public function assertEntitled(Company $company, LrWorkflowContext $context, Vehicle $vehicle): void
    {
        if (! $this->entitlements->isEntitled($company, $context)) {
            $label = $vehicle->ownership?->label() ?? 'unclassified';
            $module = match ($context) {
                LrWorkflowContext::OwnFleet => 'OwnFleet',
                LrWorkflowContext::Broker => 'Broker',
                LrWorkflowContext::Partload => 'Partload',
            };

            throw ValidationException::withMessages([
                'vehicle_id' => "This company is not entitled to {$module} LRs. Vehicle ownership ({$label}) cannot be used.",
            ]);
        }
    }
}
