<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Trip\Exceptions\TripAuthorizationException;
use App\Domains\OwnFleet\Trip\Exceptions\TripConflictException;
use App\Domains\OwnFleet\Trip\Exceptions\TripDomainException;
use App\Domains\OwnFleet\Trip\TripExpenseService;
use App\Domains\OwnFleet\Trip\TripMovementService;
use App\Domains\OwnFleet\Trip\TripService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\PaymentMode;
use App\Models\Station;
use App\Models\Trip;
use App\Models\TripDrop;
use App\Models\TripDropPickupAllocation;
use App\Models\TripExpense;
use App\Models\TripPickup;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Presentation\FreightAmountPresentation;
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
    use PresentsDocumentDrawer;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly TripService $trips,
        private readonly TripMovementService $movements,
        private readonly TripExpenseService $expenses,
        private readonly DocumentAttachmentService $documents,
        private readonly FreightAmountPresentation $freightPresentation,
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertOwnFleetModule($company);
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
            'permissions' => $this->tripPermissions($actor, $company, $branch, $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch)),
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

        $lorryReceipt->load(['fromStation', 'toStation', 'consignor', 'consignee', 'vehicle.primaryDriver']);

        $lrVehicle = $lorryReceipt->vehicle;
        $vehicles = $lrVehicle !== null && $lrVehicle->deleted_at === null
            ? [[
                'id' => $lrVehicle->id,
                'registration_number' => $lrVehicle->registration_number,
                'primary_driver_party_id' => $lrVehicle->primary_driver_party_id,
                'primary_driver_name' => $lrVehicle->primaryDriver?->name,
            ]]
            : [];

        return Inertia::render('branch/trips/create', [
            'lorryReceipt' => [
                'id' => $lorryReceipt->id,
                'lr_number' => $lorryReceipt->lr_number,
                'lr_date' => $lorryReceipt->lr_date?->format('d-m-Y'),
                'from_station_name' => $lorryReceipt->fromStation?->name,
                'to_station_name' => $lorryReceipt->toStation?->name,
                'consignor_name' => $lorryReceipt->consignor?->name,
                'consignee_name' => $lorryReceipt->consignee?->name,
                'vehicle_id' => $lorryReceipt->vehicle_id,
                'vehicle_registration' => $lrVehicle?->registration_number,
                'freight_total' => $this->freightPresentation->presentAmount(
                    $lorryReceipt->freight_total,
                    $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch),
                ),
            ],
            'vehicles' => $vehicles,
            'drivers' => $this->driverOptions($company, $branch),
            'permissions' => $this->tripPermissions($actor, $company, $branch, $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch)),
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

        $trip->load([
            'pickups.station',
            'pickups.consignor',
            'drops.station',
            'drops.consignee',
            'allocations',
            'expenses.category',
            'expenses.paymentMode',
            'lorryReceipt.fromStation',
            'lorryReceipt.toStation',
        ]);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $profit = $this->freightPresentation->redactKeys(
            $this->expenses->profitSummary($trip),
            ['agreed_freight', 'additional_charges', 'trip_profit'],
            $maySeeFreight,
        );

        return Inertia::render('branch/trips/show', [
            'trip' => $this->detailPayload($trip, $maySeeFreight),
            'movements' => $this->movementsPayload($trip, $maySeeFreight),
            'reconciliation' => $this->freightPresentation->redactKeys(
                $this->movements->reconciliationSummary($trip),
                ['total_freight'],
                $maySeeFreight,
            ),
            'expenses' => $trip->expenses->map(fn (TripExpense $expense): array => [
                'id' => $expense->id,
                'expense_date' => $expense->expense_date->format('d-m-Y'),
                'category_name' => $expense->category?->name,
                'amount' => $this->freightPresentation->presentAmount($expense->amount, $maySeeFreight),
                'is_diesel' => (bool) $expense->is_diesel,
                'diesel_litres' => $expense->diesel_litres,
                'diesel_rate_per_litre' => $this->freightPresentation->presentAmount($expense->diesel_rate_per_litre, $maySeeFreight),
                'diesel_odometer' => $expense->diesel_odometer,
                'remarks' => $expense->remarks,
                'payment_mode' => $expense->paymentMode?->name,
            ])->values()->all(),
            'profit' => $profit,
            'formOptions' => [
                'stations' => $this->stationOptions($company),
                'consignors' => $this->partyRoleOptions($company, $branch, PartyRoleType::Consignor),
                'consignees' => $this->partyRoleOptions($company, $branch, PartyRoleType::Consignee),
                'expense_categories' => $this->expenseCategoryOptions($company),
                'payment_modes' => PaymentMode::query()->orderBy('sort_order')->get(['id', 'name'])
                    ->map(fn (PaymentMode $mode): array => ['id' => $mode->id, 'name' => $mode->name])
                    ->values()->all(),
                'bank_cash_accounts' => BankCashAccount::query()
                    ->where('company_id', $company->id)
                    ->whereNull('deleted_at')
                    ->where(function ($query) use ($branch): void {
                        $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
                    })
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (BankCashAccount $account): array => ['id' => $account->id, 'name' => $account->name])
                    ->values()->all(),
            ],
            'permissions' => $this->tripPermissions($actor, $company, $branch, $maySeeFreight),
            'urls' => [
                'index' => route('branch.trips.index', absolute: false),
                'lr_show' => route('branch.lorry-receipts.show', [$trip->lorry_receipt_id], absolute: false),
                'add_pickup' => route('branch.trips.pickups.store', [$trip], absolute: false),
                'add_drop' => route('branch.trips.drops.store', [$trip], absolute: false),
                'upsert_allocation' => route('branch.trips.allocations.store', [$trip], absolute: false),
                'remove_allocation' => route('branch.trips.allocations.destroy', [$trip, 'ALLOCATION_ID'], absolute: false),
                'dispatch' => route('branch.trips.dispatch', [$trip], absolute: false),
                'complete' => route('branch.trips.complete', [$trip], absolute: false),
                'add_expense' => route('branch.trips.expenses.store', [$trip], absolute: false),
                'remove_expense' => route('branch.trips.expenses.destroy', [$trip, 'EXPENSE_ID'], absolute: false),
                'cancel' => route('branch.trips.cancel', [$trip], absolute: false),
                'destroy' => route('branch.trips.destroy', [$trip], absolute: false),
                'documents' => route('branch.documents.index', [
                    'parentType' => 'trip',
                    'parentId' => $trip->id,
                ], absolute: false),
                'lr_documents' => route('branch.documents.index', [
                    'parentType' => 'lorry_receipt',
                    'parentId' => $trip->lorry_receipt_id,
                ], absolute: false),
            ],
            'documentDrawer' => $this->documentDrawerPayload(
                $actor,
                $company,
                $branch,
                'trip',
                $trip->id,
                $this->documents,
                [
                    'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_EDIT, $company, $branch),
                    'can_edit' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_EDIT, $company, $branch),
                    'can_delete' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_EDIT, $company, $branch),
                ],
            ),
        ]);
    }

    public function cancel(Request $request, Trip $trip, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_CANCEL);
        $this->assertTrip($trip, $company, $branch);

        $validated = $request->validate([
            'cancellation_remark' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->trips->cancel($trip, $actor, $validated['cancellation_remark']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripConflictException|TripDomainException $e) {
            throw ValidationException::withMessages(['cancellation_remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.trips.show', [$trip])
            ->with('success', 'Trip cancelled.');
    }

    public function destroy(Request $request, Trip $trip, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_DELETE);
        $this->assertTrip($trip, $company, $branch);

        try {
            $this->trips->softDelete($trip, $actor);
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripConflictException|TripDomainException $e) {
            throw ValidationException::withMessages(['delete' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.trips.index')
            ->with('success', 'Trip deleted.');
    }

    public function storeExpense(Request $request, Trip $trip, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_EDIT);
        $this->assertTrip($trip, $company, $branch);

        $validated = $request->validate([
            'expense_category_id' => ['required', 'uuid'],
            'expense_date' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'payment_mode_id' => ['nullable', 'uuid'],
            'paid_from_bank_cash_account_id' => ['nullable', 'uuid'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'is_diesel' => ['sometimes', 'boolean'],
            'diesel_litres' => ['nullable', 'numeric', 'gt:0'],
            'diesel_rate_per_litre' => ['nullable', 'numeric', 'gt:0'],
            'diesel_odometer' => ['nullable', 'numeric', 'min:0'],
            'planned_diesel_quantity' => ['nullable', 'numeric', 'min:0'],
            'planned_diesel_rate' => ['nullable', 'numeric', 'min:0'],
            'planned_efficiency' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $this->expenses->add($trip, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripDomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.trips.show', [$trip])
            ->with('success', 'Expense saved.');
    }

    public function destroyExpense(
        Request $request,
        Trip $trip,
        TripExpense $expense,
        Company $company,
        Branch $branch,
    ): RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_EDIT);
        $this->assertTrip($trip, $company, $branch);

        try {
            $this->expenses->softDelete($trip, $actor, $expense);
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripDomainException $e) {
            throw ValidationException::withMessages(['expense' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.trips.show', [$trip])
            ->with('success', 'Expense removed.');
    }

    public function dispatch(Request $request, Trip $trip, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_DISPATCH);
        $this->assertTrip($trip, $company, $branch);

        $validated = $request->validate([
            'starting_odometer' => ['required', 'numeric', 'min:0'],
            'departure_at' => ['nullable', 'date'],
        ]);

        try {
            $this->trips->dispatch($trip, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripConflictException|TripDomainException $e) {
            throw ValidationException::withMessages(['starting_odometer' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.trips.show', [$trip])
            ->with('success', 'Trip dispatched.');
    }

    public function complete(Request $request, Trip $trip, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_COMPLETE);
        $this->assertTrip($trip, $company, $branch);

        $validated = $request->validate([
            'ending_odometer' => ['required', 'numeric', 'min:0'],
            'actual_arrival_at' => ['nullable', 'date'],
        ]);

        try {
            $this->trips->complete($trip, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripConflictException|TripDomainException $e) {
            throw ValidationException::withMessages(['ending_odometer' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.trips.show', [$trip])
            ->with('success', 'Trip completed.');
    }

    public function storePickup(Request $request, Trip $trip, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_EDIT);
        $this->assertTrip($trip, $company, $branch);

        $validated = $request->validate([
            'station_id' => ['required', 'uuid'],
            'consignor_party_id' => ['nullable', 'uuid'],
            'goods_description' => ['nullable', 'string', 'max:500'],
            'weight' => ['required', 'numeric', 'gt:0'],
            'pickup_date' => ['required', 'date'],
        ]);

        try {
            $this->movements->addPickup($trip, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripDomainException $e) {
            throw ValidationException::withMessages(['weight' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.trips.show', [$trip])
            ->with('success', 'Pickup added.');
    }

    public function storeDrop(Request $request, Trip $trip, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_EDIT);
        $this->assertTrip($trip, $company, $branch);

        $validated = $request->validate([
            'station_id' => ['required', 'uuid'],
            'consignee_party_id' => ['nullable', 'uuid'],
        ]);

        try {
            $this->movements->addDrop($trip, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripDomainException $e) {
            throw ValidationException::withMessages(['station_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.trips.show', [$trip])
            ->with('success', 'Drop added.');
    }

    public function storeAllocation(Request $request, Trip $trip, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_EDIT);
        $this->assertTrip($trip, $company, $branch);

        $validated = $request->validate([
            'trip_pickup_id' => ['required', 'uuid'],
            'trip_drop_id' => ['required', 'uuid'],
            'allocation_weight' => ['required', 'numeric', 'gt:0'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'freight' => ['nullable', 'numeric', 'min:0'],
            'distance' => ['nullable', 'numeric', 'min:0'],
            'package_count' => ['nullable', 'numeric', 'min:0'],
            'ewb_number' => ['nullable', 'string', 'max:64'],
            'goods_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $this->movements->upsertAllocation($trip, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripConflictException|TripDomainException $e) {
            throw ValidationException::withMessages(['allocation_weight' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.trips.show', [$trip])
            ->with('success', 'Allocation saved.');
    }

    public function destroyAllocation(
        Request $request,
        Trip $trip,
        TripDropPickupAllocation $allocation,
        Company $company,
        Branch $branch,
    ): RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_EDIT);
        $this->assertTrip($trip, $company, $branch);

        try {
            $this->movements->removeAllocation($trip, $actor, $allocation);
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripDomainException $e) {
            throw ValidationException::withMessages(['allocation' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.trips.show', [$trip])
            ->with('success', 'Allocation removed.');
    }

    private function assertTrip(Trip $trip, Company $company, Branch $branch): void
    {
        if ($trip->company_id !== $company->id || $trip->branch_id !== $branch->id || $trip->trashed()) {
            abort(404);
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        $this->assertOwnFleetModule($company);

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for OwnFleet Trip operation.');
        }
    }

    private function assertOwnFleetModule(Company $company): void
    {
        if (! $this->moduleEntitlements->isEntitled($company, SubscriptionModule::OwnFleet)) {
            abort(403, 'OwnFleet module is not entitled for this company.');
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
    private function tripPermissions(User $actor, Company $company, Branch $branch, bool $maySeeFreight): array
    {
        return [
            'can_view' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_VIEW, $company, $branch),
            'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_CREATE, $company, $branch),
            'can_edit' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_EDIT, $company, $branch),
            'can_dispatch' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_DISPATCH, $company, $branch),
            'can_complete' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_COMPLETE, $company, $branch),
            'can_cancel' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_CANCEL, $company, $branch),
            'can_delete' => $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_DELETE, $company, $branch),
            'can_view_freight' => $maySeeFreight,
        ];
    }

    /**
     * @return array<int, array{id: string, registration_number: string, primary_driver_party_id: string|null, primary_driver_name: string|null}>
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
     * @return array<int, array{id: string, name: string}>
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
    private function detailPayload(Trip $trip, bool $maySeeFreight): array
    {
        $lr = $trip->lorryReceipt;

        return [
            'id' => $trip->id,
            'status' => $trip->status->value,
            'lorry_receipt_id' => $trip->lorry_receipt_id,
            'lr_number' => $lr?->lr_number,
            'lr_freight_total' => $this->freightPresentation->presentAmount($lr?->freight_total, $maySeeFreight),
            'lr_additional_charges_total' => $this->freightPresentation->presentAmount($lr?->additional_charges_total, $maySeeFreight),
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

    /**
     * @return array{
     *     pickups: array<int, array<string, mixed>>,
     *     drops: array<int, array<string, mixed>>,
     *     allocations: array<int, array<string, mixed>>
     * }
     */
    private function movementsPayload(Trip $trip, bool $maySeeFreight): array
    {
        return [
            'pickups' => $trip->pickups->map(fn (TripPickup $pickup): array => [
                'id' => $pickup->id,
                'position' => $pickup->position,
                'station_id' => $pickup->station_id,
                'station_name' => $pickup->station?->name,
                'consignor_party_id' => $pickup->consignor_party_id,
                'consignor_name' => $pickup->consignor?->name,
                'goods_description' => $pickup->goods_description,
                'weight' => $pickup->weight,
                'pickup_date' => $pickup->pickup_date->format('d-m-Y'),
            ])->values()->all(),
            'drops' => $trip->drops->map(fn (TripDrop $drop): array => [
                'id' => $drop->id,
                'position' => $drop->position,
                'station_id' => $drop->station_id,
                'station_name' => $drop->station?->name,
                'consignee_party_id' => $drop->consignee_party_id,
                'consignee_name' => $drop->consignee?->name,
            ])->values()->all(),
            'allocations' => $trip->allocations->map(fn (TripDropPickupAllocation $allocation): array => [
                'id' => $allocation->id,
                'trip_pickup_id' => $allocation->trip_pickup_id,
                'trip_drop_id' => $allocation->trip_drop_id,
                'allocation_weight' => $allocation->allocation_weight,
                'rate' => $this->freightPresentation->presentAmount($allocation->rate, $maySeeFreight),
                'freight' => $this->freightPresentation->presentAmount($allocation->freight, $maySeeFreight),
                'distance' => $allocation->distance,
                'package_count' => $allocation->package_count,
                'ewb_number' => $allocation->ewb_number,
                'goods_value' => $allocation->goods_value,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function stationOptions(Company $company): array
    {
        return Station::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name'])
            ->map(fn (Station $station): array => [
                'id' => $station->id,
                'name' => $station->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function expenseCategoryOptions(Company $company): array
    {
        return OwnerCatalogueItem::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', OwnerCatalogueType::ExpenseCategory->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (OwnerCatalogueItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function partyRoleOptions(Company $company, Branch $branch, PartyRoleType $role): array
    {
        $partyIds = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('role', $role->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->pluck('party_id');

        return Party::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $partyIds)
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
}
