<?php

namespace App\Domains\OwnFleet\Trip;

use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Presentation\FreightAmountPresentation;

/**
 * Branch Dashboard OwnFleet operational snapshot — discoverability, not advanced reporting.
 */
final class OwnFleetOperationalOverview
{
    public function __construct(
        private readonly ?CompanyCapabilityResolver $capabilities = null,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly FreightAmountPresentation $freightPresentation = new FreightAmountPresentation,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forBranch(Company $company, Branch $branch, User $actor): ?array
    {
        $capabilities = $this->capabilities ?? app(CompanyCapabilityResolver::class);
        if (! $capabilities->allowsRead($company, CompanyCapabilities::OWNFLEET_LR)) {
            return null;
        }

        $canView = $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_VIEW, $company, $branch);
        $canCreate = $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_CREATE, $company, $branch);
        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        $ownVehicleIds = Vehicle::query()
            ->where('company_id', $company->id)
            ->where('ownership', VehicleOwnership::Own->value)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where(function ($query) use ($branch): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->pluck('id');

        $ownVehicles = $ownVehicleIds->count();

        $activeTripVehicleIds = Trip::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->whereIn('status', [TripStatus::Pending->value, TripStatus::InProgress->value])
            ->pluck('vehicle_id')
            ->unique()
            ->filter()
            ->values();

        $vehiclesOnTrip = $activeTripVehicleIds->intersect($ownVehicleIds)->count();
        $availableVehicles = max(0, $ownVehicles - $vehiclesOnTrip);

        $tripBase = Trip::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at');

        $tripsPending = (clone $tripBase)->where('status', TripStatus::Pending->value)->count();
        $tripsInProgress = (clone $tripBase)->where('status', TripStatus::InProgress->value)->count();
        $tripsCompleted = (clone $tripBase)->where('status', TripStatus::Completed->value)->count();

        $biltyAwaitingTrip = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('workflow_context', LrWorkflowContext::OwnFleet->value)
            ->where('status', LrStatus::Pending->value)
            ->whereNull('deleted_at')
            ->whereDoesntHave('trip', fn ($query) => $query->whereNull('deleted_at'))
            ->count();

        $expensesRecorded = TripExpense::query()
            ->where('trip_expenses.company_id', $company->id)
            ->whereNull('trip_expenses.deleted_at')
            ->whereHas('trip', function ($query) use ($company, $branch): void {
                $query->where('company_id', $company->id)
                    ->where('branch_id', $branch->id)
                    ->whereNull('deleted_at');
            })
            ->count();

        return [
            'entitled' => true,
            'can_view_trips' => $canView,
            'can_create_trips' => $canCreate,
            'can_view_freight' => $maySeeFreight,
            'metrics' => [
                'own_vehicles' => $ownVehicles,
                'available_vehicles' => $availableVehicles,
                'vehicles_on_trip' => $vehiclesOnTrip,
                'trips_pending' => $tripsPending,
                'trips_in_progress' => $tripsInProgress,
                'trips_completed' => $tripsCompleted,
                'bilty_awaiting_trip' => $biltyAwaitingTrip,
                'expenses_recorded' => $expensesRecorded,
            ],
            'links' => [
                'bilty_register' => route('branch.lorry-receipts.index', [
                    'ownership' => 'own',
                ], absolute: false),
                'create_bilty' => route('branch.lorry-receipts.create', absolute: false),
                'awaiting_trip' => route('branch.lorry-receipts.index', [
                    'ownership' => 'own',
                    'awaiting_trip' => '1',
                ], absolute: false),
                'active_trips' => route('branch.lorry-receipts.index', [
                    'ownership' => 'own',
                    'has_trip' => '1',
                ], absolute: false),
            ],
        ];
    }
}
