<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Trip\Exceptions\TripAuthorizationException;
use App\Domains\OwnFleet\Trip\Exceptions\TripConflictException;
use App\Domains\OwnFleet\Trip\Exceptions\TripDomainException;
use App\Domains\OwnFleet\Trip\TripService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * OwnFleet Trip Inertia UI — Batch A foundation (1 OwnFleet LR = 1 Trip).
 */
class TripController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly TripService $trips,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_VIEW);

        $page = $this->trips->search(
            company: $company,
            branch: $branch,
            status: $request->string('status')->toString() ?: null,
            perPage: min(100, max(1, (int) $request->input('per_page', 15))),
        );

        return Inertia::render('branch/trips/index', [
            'filters' => [
                'status' => $request->string('status')->toString(),
            ],
            'permissions' => $this->tripPermissions($actor, $company, $branch),
            'statusOptions' => collect(TripStatus::cases())->map(fn (TripStatus $status): array => [
                'value' => $status->value,
                'label' => match ($status) {
                    TripStatus::Pending => 'Pending',
                    TripStatus::InProgress => 'In Progress',
                    TripStatus::Completed => 'Completed',
                    TripStatus::Cancelled => 'Cancelled',
                },
            ])->values()->all(),
            'trips' => [
                'data' => collect($page->items())->map(fn (Trip $trip): array => $this->listPayload($trip))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'urls' => [
                'index' => route('branch.trips.index', absolute: false),
            ],
        ]);
    }

    public function createFromLorryReceipt(
        Request $request,
        LorryReceipt $lorryReceipt,
        Company $company,
        Branch $branch,
    ): Response|RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_CREATE);
        $this->assertLr($lorryReceipt, $company, $branch);

        if ($lorryReceipt->workflow_context !== LrWorkflowContext::OwnFleet) {
            abort(422, 'Only OwnFleet Lorry Receipts can create an OwnFleet Trip.');
        }

        $existing = $this->trips->tripForLorryReceipt($lorryReceipt);
        if ($existing !== null) {
            return redirect()
                ->route('branch.trips.show', [$existing])
                ->with('info', 'This OwnFleet LR already has a Trip.');
        }

        $lorryReceipt->load(['fromStation', 'toStation', 'consignor', 'consignee']);

        return Inertia::render('branch/trips/create', [
            'lorryReceipt' => [
                'id' => $lorryReceipt->id,
                'lr_number' => $lorryReceipt->lr_number,
                'lr_date' => $lorryReceipt->lr_date?->format('d-m-Y'),
                'from_station_name' => $lorryReceipt->fromStation?->name,
                'to_station_name' => $lorryReceipt->toStation?->name,
                'consignor_name' => $lorryReceipt->consignor?->name,
                'consignee_name' => $lorryReceipt->consignee?->name,
                'freight_total' => $lorryReceipt->freight_total,
            ],
            'vehicles' => $this->vehicleOptions($company, $branch),
            'drivers' => $this->driverOptions($company, $branch),
            'permissions' => $this->tripPermissions($actor, $company, $branch),
            'urls' => [
                'store' => route('branch.trips.store-from-lr', [$lorryReceipt], absolute: false),
                'lr_show' => route('branch.lorry-receipts.show', [$lorryReceipt], absolute: false),
                'index' => route('branch.trips.index', absolute: false),
            ],
        ]);
    }

    public function storeFromLorryReceipt(
        Request $request,
        LorryReceipt $lorryReceipt,
        Company $company,
        Branch $branch,
    ): RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_CREATE);
        $this->assertLr($lorryReceipt, $company, $branch);

        $validated = $request->validate([
            'vehicle_id' => ['required', 'uuid'],
            'use_substitute_driver' => ['sometimes', 'boolean'],
            'substitute_driver_party_id' => ['nullable', 'uuid'],
            'expected_end_at' => ['nullable', 'date'],
        ]);

        try {
            $trip = $this->trips->createFromLorryReceipt($lorryReceipt, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripConflictException $e) {
            throw ValidationException::withMessages(['vehicle_id' => $e->getMessage()]);
        } catch (TripDomainException $e) {
            throw ValidationException::withMessages(['vehicle_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.trips.show', [$trip])
            ->with('success', 'OwnFleet Trip created.');
    }

    public function show(Request $request, Trip $trip, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_VIEW);

        try {
            $trip = $this->trips->findForBranch($company, $branch, $trip->id);
        } catch (TripDomainException $e) {
            abort(404, $e->getMessage());
        }

        return Inertia::render('branch/trips/show', [
            'trip' => $this->detailPayload($trip),
            'permissions' => $this->tripPermissions($actor, $company, $branch),
            'urls' => [
                'index' => route('branch.trips.index', absolute: false),
                'lr_show' => route('branch.lorry-receipts.show', [$trip->lorry_receipt_id], absolute: false),
            ],
        ]);
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for OwnFleet Trip operation.');
        }
    }

    private function assertLr(LorryReceipt $lorryReceipt, Company $company, Branch $branch): void
    {
        if ($lorryReceipt->company_id !== $company->id || $lorryReceipt->branch_id !== $branch->id) {
            abort(404);
        }
    }

    /**
     * @return array{
     *     can_view: bool,
     *     can_create: bool,
     *     can_edit: bool,
     *     can_dispatch: bool,
     *     can_complete: bool,
     *     can_cancel: bool,
     *     can_delete: bool
     * }
     */
    private function tripPermissions(User $actor, Company $company, Branch $branch): array
    {
        return [
            'can_view' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_VIEW, $company, $branch),
            'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_CREATE, $company, $branch),
            'can_edit' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_EDIT, $company, $branch),
            'can_dispatch' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_DISPATCH, $company, $branch),
            'can_complete' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_COMPLETE, $company, $branch),
            'can_cancel' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_CANCEL, $company, $branch),
            'can_delete' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_DELETE, $company, $branch),
        ];
    }

    /**
     * @return list<array{id: string, registration_number: string, primary_driver_party_id: string|null, primary_driver_name: string|null}>
     */
    private function vehicleOptions(Company $company, Branch $branch): array
    {
        return Vehicle::query()
            ->visibleInBranch($company->id, $branch->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->with('primaryDriver')
            ->orderBy('registration_number')
            ->get()
            ->map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'primary_driver_party_id' => $vehicle->primary_driver_party_id,
                'primary_driver_name' => $vehicle->primaryDriver?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function driverOptions(Company $company, Branch $branch): array
    {
        $driverPartyIds = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('role', PartyRoleType::Driver->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->pluck('party_id');

        return Party::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $driverPartyIds)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($branch): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Party $party): array => [
                'id' => $party->id,
                'name' => $party->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(Trip $trip): array
    {
        $lr = $trip->lorryReceipt;

        return [
            'id' => $trip->id,
            'status' => $trip->status->value,
            'lr_number' => $lr?->lr_number,
            'lorry_receipt_id' => $trip->lorry_receipt_id,
            'vehicle_registration' => $trip->vehicle?->registration_number,
            'driver_name' => $trip->driver?->name,
            'from_station_name' => $lr?->fromStation?->name,
            'to_station_name' => $lr?->toStation?->name,
            'created_at' => $trip->created_at?->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
            'urls' => [
                'show' => route('branch.trips.show', [$trip], absolute: false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Trip $trip): array
    {
        $lr = $trip->lorryReceipt;

        return [
            'id' => $trip->id,
            'status' => $trip->status->value,
            'lorry_receipt_id' => $trip->lorry_receipt_id,
            'lr_number' => $lr?->lr_number,
            'lr_freight_total' => $lr?->freight_total,
            'lr_additional_charges_total' => $lr?->additional_charges_total,
            'from_station_name' => $lr?->fromStation?->name,
            'to_station_name' => $lr?->toStation?->name,
            'vehicle' => $trip->vehicle === null ? null : [
                'id' => $trip->vehicle->id,
                'registration_number' => $trip->vehicle->registration_number,
            ],
            'driver' => $trip->driver === null ? null : [
                'id' => $trip->driver->id,
                'name' => $trip->driver->name,
            ],
            'primary_driver' => $trip->primaryDriver === null ? null : [
                'id' => $trip->primaryDriver->id,
                'name' => $trip->primaryDriver->name,
            ],
            'substitute_driver' => $trip->substituteDriver === null ? null : [
                'id' => $trip->substituteDriver->id,
                'name' => $trip->substituteDriver->name,
            ],
            'starting_odometer' => $trip->starting_odometer,
            'ending_odometer' => $trip->ending_odometer,
            'trip_distance' => $trip->trip_distance,
            'departure_at' => $trip->departure_at?->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
            'actual_arrival_at' => $trip->actual_arrival_at?->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
            'expected_end_at' => $trip->expected_end_at?->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
            'timing_performance' => $trip->timing_performance?->value,
            'cancelled_at' => $trip->cancelled_at?->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
            'cancellation_remark' => $trip->cancellation_remark,
            'pickup_count' => $trip->pickups->count(),
            'drop_count' => $trip->drops->count(),
            'allocation_count' => $trip->allocations->count(),
            'created_at' => $trip->created_at?->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
        ];
    }
}
