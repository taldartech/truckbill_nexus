<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Master\Vehicle\VehicleOptionQuery;
use App\Domains\Partload\Enums\PartloadMainChallanStatus;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Partload\Exceptions\PartloadDomainException;
use App\Domains\Partload\MainChallan\PartloadMainChallanService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartloadMainChallan;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PartloadMainChallanController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly PartloadMainChallanService $mainChallans,
        private readonly PartyOptionQuery $partyOptions = new PartyOptionQuery,
        private readonly VehicleOptionQuery $vehicleOptions = new VehicleOptionQuery,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_CHALLAN_VIEW);

        $page = $this->mainChallans->search(
            company: $company,
            branch: $branch,
            status: $request->string('status')->toString() ?: null,
            perPage: min(100, max(1, (int) $request->input('per_page', 15))),
        );

        return Inertia::render('partload/main-challans/index', [
            'filters' => [
                'status' => $request->string('status')->toString(),
            ],
            'permissions' => $this->permissions($actor, $company, $branch),
            'statusOptions' => collect(PartloadMainChallanStatus::cases())->map(fn (PartloadMainChallanStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ])->values()->all(),
            'mainChallans' => [
                'data' => collect($page->items())->map(fn (PartloadMainChallan $main): array => $this->listRow($main))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'urls' => [
                'index' => route('branch.partload.main-challans.index', absolute: false),
                'create' => route('branch.partload.main-challans.create', absolute: false),
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_CHALLAN_CREATE);

        $eligible = $this->mainChallans->eligibleChallans($company, $branch);

        return Inertia::render('partload/main-challans/create', [
            'eligibleChallans' => $eligible->map(fn ($challan): array => [
                'id' => $challan->id,
                'challan_number' => $challan->challan_number,
                'challan_date' => $challan->challan_date?->toDateString(),
                'from_station_name' => $challan->fromStation?->name,
                'to_station_name' => $challan->toStation?->name,
                'vehicle_registration' => $challan->vehicle?->registration_number,
                'total_packages' => (string) $challan->total_packages,
                'total_weight_kg' => (string) $challan->total_weight_kg,
                'to_pay_amount' => (string) $challan->to_pay_amount,
            ])->values()->all(),
            'stationOptions' => Station::query()
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('owner_user_id', $company->owner_user_id)
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name', 'city', 'state'])
                ->map(fn (Station $station): array => [
                    'id' => $station->id,
                    'name' => $station->name,
                    'city' => $station->city,
                    'state' => $station->state,
                ])->values()->all(),
            'vehicleOptions' => $this->vehicleOptions->forBranch($company, $branch),
            'driverOptions' => $this->partyOptions->forRoles($company, $branch, PartyRoleType::Driver),
            'permissions' => array_merge($this->permissions($actor, $company, $branch), [
                'can_create_masters' => $this->masterPermissions(
                    $actor,
                    $company,
                    $branch,
                    $this->access,
                    $this->authorizer,
                )['can_create'],
                'can_quick_create_stations' => (int) $actor->id === (int) $company->owner_user_id,
            ]),
            'urls' => [
                'store' => route('branch.partload.main-challans.store', absolute: false),
                'index' => route('branch.partload.main-challans.index', absolute: false),
                'stations_store' => route('branch.stations.store', absolute: false),
                'vehicles_store' => route('branch.vehicles.store', absolute: false),
                'vehicles_options' => route('branch.vehicles.options', absolute: false),
                'parties_store' => route('branch.parties.store', absolute: false),
                'parties_options' => route('branch.parties.options', absolute: false),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $validated = $request->validate([
            'main_challan_date' => ['nullable', 'date'],
            'from_station_id' => ['required', 'uuid'],
            'to_station_id' => ['required', 'uuid', 'different:from_station_id'],
            'vehicle_id' => ['required', 'uuid'],
            'driver_party_id' => ['required', 'uuid'],
            'partload_challan_ids' => ['required', 'array', 'min:1'],
            'partload_challan_ids.*' => ['uuid'],
            'truck_freight' => ['nullable', 'numeric', 'min:0'],
            'labour_charge' => ['nullable', 'numeric', 'min:0'],
            'kat' => ['nullable', 'numeric', 'min:0'],
            'home_delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'other_charge' => ['nullable', 'numeric', 'min:0'],
            'cash_received' => ['nullable', 'numeric', 'min:0'],
            'cash_paid' => ['nullable', 'numeric', 'min:0'],
            'crossing_freight' => ['nullable', 'numeric', 'min:0'],
            'cartage_dalali_pf' => ['nullable', 'numeric', 'min:0'],
            'gst_amount' => ['nullable', 'numeric', 'min:0'],
            'to_account_party_id' => ['nullable', 'uuid'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'dispatch' => ['sometimes', 'boolean'],
        ]);

        try {
            $main = $this->mainChallans->create($company, $branch, $actor, $validated);
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PartloadConflictException $e) {
            throw ValidationException::withMessages(['partload_challan_ids' => $e->getMessage()]);
        } catch (PartloadDomainException $e) {
            throw ValidationException::withMessages(['main_challan' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.partload.main-challans.show', $main)
            ->with('success', 'Main Challan '.$main->main_challan_number.' created.');
    }

    public function show(Request $request, Company $company, Branch $branch, PartloadMainChallan $partloadMainChallan): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_CHALLAN_VIEW);

        $main = $this->mainChallans->findForBranch($company, $branch, $partloadMainChallan->id);

        return Inertia::render('partload/main-challans/show', [
            'permissions' => $this->permissions($actor, $company, $branch),
            'mainChallan' => $this->detailPayload($main),
            'urls' => [
                'index' => route('branch.partload.main-challans.index', absolute: false),
                'dispatch' => $main->status === PartloadMainChallanStatus::Draft
                    ? route('branch.partload.main-challans.dispatch', $main, absolute: false)
                    : null,
                'cancel' => $main->status === PartloadMainChallanStatus::Draft
                    ? route('branch.partload.main-challans.cancel', $main, absolute: false)
                    : null,
            ],
        ]);
    }

    public function dispatch(Request $request, Company $company, Branch $branch, PartloadMainChallan $partloadMainChallan): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        try {
            $main = $this->mainChallans->dispatch(
                $this->mainChallans->findForBranch($company, $branch, $partloadMainChallan->id),
                $actor,
            );
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PartloadConflictException $e) {
            throw ValidationException::withMessages(['main_challan' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.partload.main-challans.show', $main)
            ->with('success', 'Main Challan dispatched.');
    }

    public function cancel(Request $request, Company $company, Branch $branch, PartloadMainChallan $partloadMainChallan): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $validated = $request->validate([
            'cancellation_remark' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $main = $this->mainChallans->cancel(
                $this->mainChallans->findForBranch($company, $branch, $partloadMainChallan->id),
                $actor,
                $validated['cancellation_remark'],
            );
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PartloadConflictException $e) {
            throw ValidationException::withMessages(['cancellation_remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.partload.main-challans.show', $main)
            ->with('success', 'Main Challan cancelled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(PartloadMainChallan $main): array
    {
        return [
            'id' => $main->id,
            'main_challan_number' => $main->main_challan_number,
            'main_challan_date' => $main->main_challan_date?->toDateString(),
            'status' => $main->status->value,
            'status_label' => $main->status->label(),
            'from_station_name' => $main->fromStation?->name,
            'to_station_name' => $main->toStation?->name,
            'vehicle_registration' => $main->vehicle?->registration_number,
            'driver_name' => $main->driver?->name,
            'challan_count' => $main->lines->count(),
            'total_amount' => (string) $main->total_amount,
            'urls' => [
                'show' => route('branch.partload.main-challans.show', $main, absolute: false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(PartloadMainChallan $main): array
    {
        return [
            'id' => $main->id,
            'main_challan_number' => $main->main_challan_number,
            'main_challan_date' => $main->main_challan_date?->toDateString(),
            'status' => $main->status->value,
            'status_label' => $main->status->label(),
            'from_station_name' => $main->fromStation?->name,
            'to_station_name' => $main->toStation?->name,
            'vehicle_registration' => $main->vehicle?->registration_number,
            'driver_name' => $main->driver?->name,
            'ownership_mode' => $main->ownership_mode->value,
            'truck_freight' => (string) $main->truck_freight,
            'labour_charge' => (string) $main->labour_charge,
            'kat' => (string) $main->kat,
            'home_delivery_charge' => (string) $main->home_delivery_charge,
            'other_charge' => (string) $main->other_charge,
            'cash_received' => (string) $main->cash_received,
            'cash_paid' => (string) $main->cash_paid,
            'total_packages' => (string) $main->total_packages,
            'total_weight_kg' => (string) $main->total_weight_kg,
            'to_pay_amount' => (string) $main->to_pay_amount,
            'paid_amount' => (string) $main->paid_amount,
            'total_amount' => (string) $main->total_amount,
            'to_account_party_name' => $main->toAccountParty?->name,
            'remark' => $main->remark,
            'lines' => $main->lines->map(fn ($line): array => [
                'id' => $line->id,
                'challan_id' => $line->partload_challan_id,
                'challan_number' => $line->challan?->challan_number,
                'challan_status' => $line->challan?->status?->value,
                'from_station_name' => $line->challan?->fromStation?->name,
                'to_station_name' => $line->challan?->toStation?->name,
                'vehicle_registration' => $line->challan?->vehicle?->registration_number,
                'urls' => [
                    'show' => $line->challan
                        ? route('branch.partload.challans.show', $line->challan, absolute: false)
                        : null,
                ],
            ])->values()->all(),
        ];
    }

    /**
     * @return array{can_create: bool, can_dispatch: bool, can_cancel: bool}
     */
    private function permissions(User $actor, Company $company, Branch $branch): array
    {
        $owner = (int) $actor->id === (int) $company->owner_user_id;

        return [
            'can_create' => $owner || $this->authorizer->allowsCustomer($actor, PermissionCodes::PARTLOAD_CHALLAN_CREATE, $company, $branch),
            'can_dispatch' => $owner || $this->authorizer->allowsCustomer($actor, PermissionCodes::PARTLOAD_CHALLAN_DISPATCH, $company, $branch),
            'can_cancel' => $owner || $this->authorizer->allowsCustomer($actor, PermissionCodes::PARTLOAD_CHALLAN_CANCEL, $company, $branch),
        ];
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if ((int) $actor->id === (int) $company->owner_user_id) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403);
        }
    }
}
