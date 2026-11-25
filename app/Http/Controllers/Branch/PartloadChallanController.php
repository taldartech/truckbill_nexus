<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Master\Vehicle\VehicleOptionQuery;
use App\Domains\Partload\Challan\PartloadChallanService;
use App\Domains\Partload\Enums\PartloadChallanStatus;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Partload\Exceptions\PartloadDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartloadChallan;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PartloadChallanController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly PartloadChallanService $challans,
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
        private readonly PartyOptionQuery $partyOptions = new PartyOptionQuery,
        private readonly VehicleOptionQuery $vehicleOptions = new VehicleOptionQuery,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPartloadModule($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_CHALLAN_VIEW);

        $page = $this->challans->search(
            company: $company,
            branch: $branch,
            status: $request->string('status')->toString() ?: null,
            perPage: min(100, max(1, (int) $request->input('per_page', 15))),
        );

        return Inertia::render('partload/challans/index', [
            'filters' => [
                'status' => $request->string('status')->toString(),
            ],
            'permissions' => $this->permissions($actor, $company, $branch),
            'statusOptions' => collect(PartloadChallanStatus::cases())->map(fn (PartloadChallanStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ])->values()->all(),
            'challans' => [
                'data' => collect($page->items())->map(fn (PartloadChallan $challan): array => $this->listRow($challan))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'urls' => [
                'index' => route('branch.partload.challans.index', absolute: false),
                'create' => route('branch.partload.challans.create', absolute: false),
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPartloadModule($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_CHALLAN_CREATE);

        $fromStationId = $request->string('from_station_id')->toString() ?: null;
        $toStationId = $request->string('to_station_id')->toString() ?: null;

        $eligible = $this->challans->eligibleBilties($company, $branch, $fromStationId, $toStationId);

        return Inertia::render('partload/challans/create', [
            'permissions' => $this->permissions($actor, $company, $branch),
            'eligibleBilties' => $eligible->map(fn ($lr): array => [
                'id' => $lr->id,
                'lr_number' => $lr->lr_number,
                'lr_date' => $lr->lr_date?->toDateString(),
                'billing_customer_name' => $lr->billingCustomer?->name,
                'from_station_name' => $lr->fromStation?->name,
                'to_station_name' => $lr->toStation?->name,
                'freight_total' => (string) $lr->freight_total,
                'freight_payment_term' => $lr->freight_payment_term?->value,
            ])->values()->all(),
            'stationOptions' => Station::query()
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('owner_user_id', $company->owner_user_id)
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name', 'code'])
                ->map(fn (Station $station): array => [
                    'id' => $station->id,
                    'name' => $station->name,
                    'code' => $station->code,
                ])->values()->all(),
            'vehicleOptions' => $this->vehicleOptions->forBranch($company, $branch),
            'driverOptions' => $this->partyOptions->forRoles($company, $branch, PartyRoleType::Driver),
            'urls' => [
                'store' => route('branch.partload.challans.store', absolute: false),
                'index' => route('branch.partload.challans.index', absolute: false),
            ],
            'filters' => [
                'from_station_id' => $fromStationId,
                'to_station_id' => $toStationId,
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPartloadModule($company);

        $validated = $request->validate([
            'challan_date' => ['nullable', 'date'],
            'from_station_id' => ['required', 'uuid'],
            'to_station_id' => ['required', 'uuid', 'different:from_station_id'],
            'vehicle_id' => ['required', 'uuid'],
            'driver_party_id' => ['nullable', 'uuid'],
            'lorry_receipt_ids' => ['required', 'array', 'min:1'],
            'lorry_receipt_ids.*' => ['uuid'],
            'truck_freight' => ['nullable', 'numeric', 'min:0'],
            'labour_charge' => ['nullable', 'numeric', 'min:0'],
            'kat' => ['nullable', 'numeric', 'min:0'],
            'home_delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'cash_received' => ['nullable', 'numeric', 'min:0'],
            'cash_payment' => ['nullable', 'numeric', 'min:0'],
            'crossing_freight' => ['nullable', 'numeric', 'min:0'],
            'cartage_dalali_pf' => ['nullable', 'numeric', 'min:0'],
            'gst_amount' => ['nullable', 'numeric', 'min:0'],
            'to_account_party_id' => ['nullable', 'uuid'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'dispatch' => ['sometimes', 'boolean'],
        ]);

        try {
            $challan = $this->challans->create($company, $branch, $actor, $validated);
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PartloadConflictException $e) {
            throw ValidationException::withMessages(['lorry_receipt_ids' => $e->getMessage()]);
        } catch (PartloadDomainException $e) {
            throw ValidationException::withMessages(['challan' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.partload.challans.show', $challan)
            ->with('success', 'Challan '.$challan->challan_number.' created.');
    }

    public function show(Request $request, Company $company, Branch $branch, PartloadChallan $partloadChallan): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPartloadModule($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_CHALLAN_VIEW);

        $challan = $this->challans->findForBranch($company, $branch, $partloadChallan->id);

        return Inertia::render('partload/challans/show', [
            'permissions' => $this->permissions($actor, $company, $branch),
            'challan' => $this->detailPayload($challan),
            'urls' => [
                'index' => route('branch.partload.challans.index', absolute: false),
                'dispatch' => $challan->status === PartloadChallanStatus::Draft
                    && blank($challan->partload_main_challan_id)
                    ? route('branch.partload.challans.dispatch', $challan, absolute: false)
                    : null,
                'cancel' => in_array($challan->status, [PartloadChallanStatus::Draft, PartloadChallanStatus::Dispatched], true)
                    ? route('branch.partload.challans.cancel', $challan, absolute: false)
                    : null,
                'create_inward' => $challan->status === PartloadChallanStatus::Dispatched
                    ? route('branch.partload.inwards.store', absolute: false)
                    : null,
            ],
        ]);
    }

    public function dispatch(Request $request, Company $company, Branch $branch, PartloadChallan $partloadChallan): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPartloadModule($company);

        try {
            $challan = $this->challans->dispatch(
                $this->challans->findForBranch($company, $branch, $partloadChallan->id),
                $actor,
            );
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PartloadConflictException $e) {
            throw ValidationException::withMessages(['challan' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.partload.challans.show', $challan)
            ->with('success', 'Challan dispatched.');
    }

    public function cancel(Request $request, Company $company, Branch $branch, PartloadChallan $partloadChallan): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPartloadModule($company);

        $validated = $request->validate([
            'cancellation_remark' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $challan = $this->challans->cancel(
                $this->challans->findForBranch($company, $branch, $partloadChallan->id),
                $actor,
                $validated['cancellation_remark'],
            );
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PartloadConflictException $e) {
            throw ValidationException::withMessages(['cancellation_remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.partload.challans.show', $challan)
            ->with('success', 'Challan cancelled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(PartloadChallan $challan): array
    {
        return [
            'id' => $challan->id,
            'challan_number' => $challan->challan_number,
            'challan_date' => $challan->challan_date?->toDateString(),
            'status' => $challan->status->value,
            'status_label' => $challan->status->label(),
            'from_station_name' => $challan->fromStation?->name,
            'to_station_name' => $challan->toStation?->name,
            'vehicle_registration' => $challan->vehicle?->registration_number,
            'driver_name' => $challan->driver?->name,
            'line_count' => $challan->lines->count(),
            'total_packages' => (string) $challan->total_packages,
            'total_amount' => (string) $challan->total_amount,
            'urls' => [
                'show' => route('branch.partload.challans.show', $challan, absolute: false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(PartloadChallan $challan): array
    {
        return [
            'id' => $challan->id,
            'challan_number' => $challan->challan_number,
            'challan_date' => $challan->challan_date?->toDateString(),
            'status' => $challan->status->value,
            'status_label' => $challan->status->label(),
            'ownership_mode' => $challan->ownership_mode->value,
            'from_station_name' => $challan->fromStation?->name,
            'to_station_name' => $challan->toStation?->name,
            'vehicle_registration' => $challan->vehicle?->registration_number,
            'driver_name' => $challan->driver?->name,
            'truck_freight' => (string) $challan->truck_freight,
            'labour_charge' => (string) $challan->labour_charge,
            'kat' => (string) $challan->kat,
            'home_delivery_charge' => (string) $challan->home_delivery_charge,
            'cash_received' => (string) $challan->cash_received,
            'cash_payment' => (string) $challan->cash_payment,
            'to_pay_amount' => (string) $challan->to_pay_amount,
            'paid_amount' => (string) $challan->paid_amount,
            'total_packages' => (string) $challan->total_packages,
            'total_weight_kg' => (string) $challan->total_weight_kg,
            'total_amount' => (string) $challan->total_amount,
            'remark' => $challan->remark,
            'lines' => $challan->lines->map(fn ($line): array => [
                'id' => $line->id,
                'lr_number' => $line->lorryReceipt?->lr_number,
                'billing_customer_name' => $line->lorryReceipt?->billingCustomer?->name,
                'to_station_name' => $line->toStation?->name,
                'packages' => (string) $line->packages,
                'weight_kg' => (string) $line->weight_kg,
                'freight_amount' => (string) $line->freight_amount,
                'freight_payment_term' => $line->freight_payment_term?->value,
            ])->values()->all(),
            'inward_id' => $challan->inwardRegister?->id,
        ];
    }

    /**
     * @return array{can_view: bool, can_create: bool, can_dispatch: bool, can_cancel: bool, can_create_inward: bool}
     */
    private function permissions(User $actor, Company $company, Branch $branch): array
    {
        $isOwner = (int) $actor->id === (int) $company->owner_user_id;

        return [
            'can_view' => $isOwner || $this->authorizer->allowsCustomer($actor, PermissionCodes::PARTLOAD_CHALLAN_VIEW, $company, $branch),
            'can_create' => $isOwner || $this->authorizer->allowsCustomer($actor, PermissionCodes::PARTLOAD_CHALLAN_CREATE, $company, $branch),
            'can_dispatch' => $isOwner || $this->authorizer->allowsCustomer($actor, PermissionCodes::PARTLOAD_CHALLAN_DISPATCH, $company, $branch),
            'can_cancel' => $isOwner || $this->authorizer->allowsCustomer($actor, PermissionCodes::PARTLOAD_CHALLAN_CANCEL, $company, $branch),
            'can_create_inward' => $isOwner || $this->authorizer->allowsCustomer($actor, PermissionCodes::PARTLOAD_INWARD_CREATE, $company, $branch),
        ];
    }

    private function assertPartloadModule(Company $company): void
    {
        if (! $this->moduleEntitlements->isEntitled($company, SubscriptionModule::Partload)) {
            abort(403, 'Partload module is not entitled for this company.');
        }
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
