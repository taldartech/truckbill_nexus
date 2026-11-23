<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\Exceptions\LorryReceiptAuthorizationException;
use App\Domains\Consignment\Exceptions\LorryReceiptConflictException;
use App\Domains\Consignment\Exceptions\LorryReceiptDomainException;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Consignment\LrVehicleClassificationService;
use App\Domains\Consignment\LrWorkflowContextResolver;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\OwnFleet\Trip\TripService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\LrAdditionalCharge;
use App\Models\LrEwayBill;
use App\Models\LrGoodsRow;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branch Unified LR Inertia UI (Phase 4 Batch E).
 */
class LorryReceiptController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly LorryReceiptService $lorryReceipts,
        private readonly LrWorkflowContextResolver $contextResolver,
        private readonly LrVehicleClassificationService $vehicleClassification,
        private readonly TripService $trips,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_VIEW);

        $page = $this->lorryReceipts->search(
            company: $company,
            branch: $branch,
            query: $request->string('q')->toString() ?: null,
            status: $request->string('status')->toString() ?: null,
            workflowContext: $request->string('workflow_context')->toString() ?: null,
            perPage: min(100, max(1, (int) $request->input('per_page', 15))),
        );

        $permissions = $this->lrPermissions($actor, $company, $branch);

        return Inertia::render('branch/lorry-receipts/index', [
            'filters' => [
                'q' => $request->string('q')->toString(),
                'status' => $request->string('status')->toString(),
                'workflow_context' => $request->string('workflow_context')->toString(),
            ],
            'permissions' => $permissions,
            'statusOptions' => collect(LrStatus::cases())->map(fn (LrStatus $status): array => [
                'value' => $status->value,
                'label' => ucfirst($status->value),
            ])->values()->all(),
            'contextOptions' => $this->contextOptions(),
            'createAction' => $permissions['can_create']
                ? $this->createActionPayload($company, $actor, $branch)
                : null,
            'partloadCreateUrl' => $permissions['can_create'] && $this->contextResolver->hasPartloadWorkflow($company)
                ? route('branch.lorry-receipts.create', ['context' => 'partload'], absolute: false)
                : null,
            'lorryReceipts' => [
                'data' => collect($page->items())->map(fn (LorryReceipt $lr): array => $this->listPayload($lr))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'urls' => [
                'index' => route('branch.lorry-receipts.index', absolute: false),
                'create' => route('branch.lorry-receipts.create', absolute: false),
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response|RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_CREATE);

        $origin = $this->requestOrigin($request);
        $resolution = $this->contextResolver->resolveForCreate($company, $actor, $branch, $origin);

        if ($resolution['status'] === 'blocked') {
            return Inertia::render('branch/lorry-receipts/blocked', [
                'reason' => $resolution['reason'],
                'urls' => [
                    'index' => route('branch.lorry-receipts.index', absolute: false),
                ],
            ]);
        }

        $formMode = $resolution['form_mode'];
        $context = $resolution['context'];

        return Inertia::render('branch/lorry-receipts/create', [
            'mode' => 'create',
            'formMode' => $formMode,
            'workflowContext' => $context?->value,
            'contextLabel' => $formMode === LrWorkflowContextResolver::FORM_PARTLOAD
                ? 'Partload'
                : 'Lorry Receipt',
            'lorryReceipt' => null,
            'formOptions' => $this->formOptions($company, $branch, $actor, $formMode),
            'permissions' => $this->lrPermissions($actor, $company, $branch),
            'urls' => [
                'index' => route('branch.lorry-receipts.index', absolute: false),
                'store' => route('branch.lorry-receipts.store', absolute: false),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_CREATE);

        $formMode = strtolower(trim((string) $request->input('form_mode', '')));
        $origin = $this->requestOrigin($request);
        if ($formMode === LrWorkflowContextResolver::FORM_PARTLOAD) {
            $origin = LrWorkflowContext::Partload->value;
        } elseif ($formMode === LrWorkflowContextResolver::FORM_TRUCK) {
            $origin = null;
        }

        $resolution = $this->contextResolver->resolveForCreate($company, $actor, $branch, $origin);

        if ($resolution['status'] !== 'ready') {
            throw ValidationException::withMessages([
                'form_mode' => $resolution['reason'] ?? 'Lorry Receipt creation is not available.',
            ]);
        }

        $payload = $this->validatedPayload($request);

        if ($resolution['form_mode'] === LrWorkflowContextResolver::FORM_PARTLOAD) {
            if (! empty($payload['vehicle_id'])) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'Vehicle is not used for Partload LRs.',
                ]);
            }

            if (
                array_key_exists('workflow_context', $payload)
                && (string) $payload['workflow_context'] !== LrWorkflowContext::Partload->value
            ) {
                throw ValidationException::withMessages([
                    'workflow_context' => 'Forged workflow context was rejected.',
                ]);
            }

            $payload['workflow_context'] = LrWorkflowContext::Partload->value;
            $payload['vehicle_id'] = null;
        } else {
            $vehicleId = (string) ($payload['vehicle_id'] ?? '');
            if ($vehicleId === '') {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'Vehicle is required.',
                ]);
            }

            $classified = $this->vehicleClassification->resolveFromVehicleId($company, $branch, $vehicleId);
            $resolvedContext = $classified['context'];

            if (
                array_key_exists('workflow_context', $payload)
                && filled($payload['workflow_context'])
                && (string) $payload['workflow_context'] !== $resolvedContext->value
            ) {
                throw ValidationException::withMessages([
                    'workflow_context' => 'Forged workflow context was rejected. Classification follows vehicle ownership.',
                ]);
            }

            $payload['workflow_context'] = $resolvedContext->value;
            $payload['vehicle_id'] = $classified['vehicle']->id;
        }

        try {
            $lr = $this->lorryReceipts->create($company, $branch, $actor, $payload);
        } catch (ValidationException $e) {
            throw $e;
        } catch (LorryReceiptAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (LorryReceiptConflictException $e) {
            throw ValidationException::withMessages(['lr_number' => $e->getMessage()]);
        } catch (LorryReceiptDomainException $e) {
            throw ValidationException::withMessages(['workflow_context' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.lorry-receipts.show', [$lr])
            ->with('success', 'Lorry Receipt created.');
    }

    public function show(Request $request, LorryReceipt $lorryReceipt, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_VIEW);
        $this->assertLr($lorryReceipt, $company, $branch);

        $lorryReceipt->load(['goodsRows', 'ewayBills', 'additionalCharges', 'consignor', 'consignee', 'fromStation', 'toStation']);

        $linkedTrip = $this->trips->tripForLorryReceipt($lorryReceipt);
        $canCreateTrip = $lorryReceipt->workflow_context === LrWorkflowContext::OwnFleet
            && $lorryReceipt->status === LrStatus::Pending
            && $linkedTrip === null
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_CREATE, $company, $branch);

        return Inertia::render('branch/lorry-receipts/show', [
            'lorryReceipt' => $this->detailPayload($lorryReceipt),
            'permissions' => array_merge($this->lrPermissions($actor, $company, $branch), [
                'can_create_trip' => $canCreateTrip,
            ]),
            'linkedTrip' => $linkedTrip === null ? null : [
                'id' => $linkedTrip->id,
                'status' => $linkedTrip->status->value,
                'show_url' => route('branch.trips.show', [$linkedTrip], absolute: false),
            ],
            'urls' => [
                'index' => route('branch.lorry-receipts.index', absolute: false),
                'edit' => route('branch.lorry-receipts.edit', [$lorryReceipt], absolute: false),
                'cancel' => route('branch.lorry-receipts.cancel', [$lorryReceipt], absolute: false),
                'destroy' => route('branch.lorry-receipts.destroy', [$lorryReceipt], absolute: false),
                'print' => route('branch.lorry-receipts.print', [$lorryReceipt], absolute: false),
                'create_trip' => route('branch.trips.create-from-lr', [$lorryReceipt], absolute: false),
            ],
        ]);
    }

    public function edit(Request $request, LorryReceipt $lorryReceipt, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_EDIT);
        $this->assertLr($lorryReceipt, $company, $branch);

        if ($lorryReceipt->status !== LrStatus::Pending) {
            abort(422, 'Only pending Lorry Receipts can be edited.');
        }

        $lorryReceipt->load(['goodsRows', 'ewayBills', 'additionalCharges']);
        $context = $lorryReceipt->workflow_context;

        $formMode = $context === LrWorkflowContext::Partload
            ? LrWorkflowContextResolver::FORM_PARTLOAD
            : LrWorkflowContextResolver::FORM_TRUCK;

        return Inertia::render('branch/lorry-receipts/edit', [
            'mode' => 'edit',
            'formMode' => $formMode,
            'workflowContext' => $context->value,
            'contextLabel' => $this->contextResolver->labelFor($context),
            'lorryReceipt' => $this->detailPayload($lorryReceipt),
            'formOptions' => $this->formOptions($company, $branch, $actor, $formMode),
            'permissions' => $this->lrPermissions($actor, $company, $branch),
            'urls' => [
                'index' => route('branch.lorry-receipts.index', absolute: false),
                'show' => route('branch.lorry-receipts.show', [$lorryReceipt], absolute: false),
                'update' => route('branch.lorry-receipts.update', [$lorryReceipt], absolute: false),
            ],
        ]);
    }

    public function update(Request $request, LorryReceipt $lorryReceipt, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_EDIT);
        $this->assertLr($lorryReceipt, $company, $branch);

        $payload = $this->validatedPayload($request, forUpdate: true);
        $payload['workflow_context'] = $lorryReceipt->workflow_context->value;

        try {
            $updated = $this->lorryReceipts->update($lorryReceipt, $actor, $payload);
        } catch (ValidationException $e) {
            throw $e;
        } catch (LorryReceiptAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (LorryReceiptDomainException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.lorry-receipts.show', [$updated])
            ->with('success', 'Lorry Receipt updated.');
    }

    public function destroy(Request $request, LorryReceipt $lorryReceipt, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_DELETE);
        $this->assertLr($lorryReceipt, $company, $branch);

        try {
            $this->lorryReceipts->softDelete($lorryReceipt, $actor);
        } catch (LorryReceiptAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (LorryReceiptConflictException|LorryReceiptDomainException $e) {
            throw ValidationException::withMessages(['delete' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.lorry-receipts.index')
            ->with('success', 'Lorry Receipt deleted.');
    }

    public function cancel(Request $request, LorryReceipt $lorryReceipt, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_CANCEL);
        $this->assertLr($lorryReceipt, $company, $branch);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->lorryReceipts->cancel($lorryReceipt, $actor, $validated['remark']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (LorryReceiptAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (LorryReceiptConflictException|LorryReceiptDomainException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.lorry-receipts.show', [$lorryReceipt])
            ->with('success', 'Lorry Receipt cancelled.');
    }

    public function print(Request $request, LorryReceipt $lorryReceipt, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_VIEW);
        $this->assertLr($lorryReceipt, $company, $branch);

        $lorryReceipt->load(['goodsRows', 'ewayBills', 'additionalCharges', 'consignor', 'consignee', 'fromStation', 'toStation']);

        return Inertia::render('branch/lorry-receipts/print', [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'lorryReceipt' => $this->detailPayload($lorryReceipt),
            'urls' => [
                'show' => route('branch.lorry-receipts.show', [$lorryReceipt], absolute: false),
            ],
        ]);
    }

    private function requestOrigin(Request $request): ?string
    {
        $context = trim($request->string('context')->toString());
        if ($context !== '') {
            return $context;
        }

        $origin = trim($request->string('origin')->toString());

        return $origin !== '' ? $origin : null;
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for Lorry Receipt operation.');
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
     *     can_cancel: bool,
     *     can_delete: bool
     * }
     */
    private function lrPermissions(User $actor, Company $company, Branch $branch): array
    {
        return [
            'can_view' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_VIEW, $company, $branch),
            'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_CREATE, $company, $branch),
            'can_edit' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_EDIT, $company, $branch),
            'can_cancel' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_CANCEL, $company, $branch),
            'can_delete' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_DELETE, $company, $branch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $forUpdate = false): array
    {
        $rules = [
            'form_mode' => ['nullable', Rule::in([LrWorkflowContextResolver::FORM_TRUCK, LrWorkflowContextResolver::FORM_PARTLOAD])],
            'workflow_context' => ['nullable', Rule::enum(LrWorkflowContext::class)],
            'vehicle_id' => ['nullable', 'uuid'],
            'billing_customer_party_id' => ['nullable', 'uuid'],
            'lr_date' => ['nullable', 'date'],
            'from_text' => ['nullable', 'string', 'max:255'],
            'to_text' => ['nullable', 'string', 'max:255'],
            'from_station_id' => ['nullable', 'uuid'],
            'to_station_id' => ['nullable', 'uuid'],
            'consignor_party_id' => ['nullable', 'uuid'],
            'consignee_party_id' => ['nullable', 'uuid'],
            'goods_value' => ['nullable', 'numeric', 'min:0'],
            'goods' => ['required', 'array', 'min:1'],
            'goods.*.goods_type_id' => ['nullable', 'uuid'],
            'goods.*.package_type_id' => ['nullable', 'uuid'],
            'goods.*.goods_description' => ['nullable', 'string', 'max:255'],
            'goods.*.package_count' => ['nullable', 'numeric', 'min:0'],
            'goods.*.actual_weight' => ['nullable', 'numeric', 'min:0'],
            'goods.*.charged_weight' => ['nullable', 'numeric', 'min:0'],
            'goods.*.freight_rate_type' => ['required', Rule::enum(FreightRateType::class)],
            'goods.*.freight_rate' => ['required', 'numeric', 'min:0'],
            'goods.*.goods_value' => ['nullable', 'numeric', 'min:0'],
            'eway_bills' => ['nullable', 'array'],
            'eway_bills.*.ewb_number' => ['required', 'string', 'max:64'],
            'eway_bills.*.ewb_date' => ['nullable', 'date'],
            'eway_bills.*.valid_until' => ['required', 'date'],
            'additional_charges' => ['nullable', 'array'],
            'additional_charges.*.description' => ['required', 'string', 'max:255'],
            'additional_charges.*.amount' => ['required', 'numeric', 'gt:0'],
        ];

        if (! $forUpdate) {
            $rules['number_mode'] = ['required', Rule::in(['auto', 'manual'])];
            $rules['lr_number'] = ['nullable', 'string', 'max:64', 'required_if:number_mode,manual'];
            $rules['context'] = ['nullable', 'string', 'max:32'];
            $rules['origin'] = ['nullable', 'string', 'max:32'];
        }

        return $request->validate($rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Company $company, Branch $branch, User $actor, string $formMode): array
    {
        $consignors = Party::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereHas('roles', function ($query): void {
                $query->where('role', PartyRoleType::Consignor->value)
                    ->whereNull('deleted_at')
                    ->whereNull('deactivated_at');
            })
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name']);

        $consignees = Party::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereHas('roles', function ($query): void {
                $query->where('role', PartyRoleType::Consignee->value)
                    ->whereNull('deleted_at')
                    ->whereNull('deactivated_at');
            })
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name']);

        $billingCustomers = Party::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereHas('roles', function ($query): void {
                $query->where('role', PartyRoleType::Customer->value)
                    ->whereNull('deleted_at')
                    ->whereNull('deactivated_at');
            })
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name']);

        $stations = Station::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'code']);

        $goodsTypes = OwnerCatalogueItem::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', OwnerCatalogueType::GoodsType->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);

        $packageTypes = OwnerCatalogueItem::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', OwnerCatalogueType::PackageType->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);

        $vehicles = [];
        if ($formMode === LrWorkflowContextResolver::FORM_TRUCK) {
            $ownershipFilter = [];
            foreach ($this->contextResolver->availableContexts($company, $actor, $branch) as $context) {
                if ($context === LrWorkflowContext::OwnFleet) {
                    $ownershipFilter[] = VehicleOwnership::Own->value;
                }
                if ($context === LrWorkflowContext::Broker) {
                    $ownershipFilter[] = VehicleOwnership::Market->value;
                }
            }

            $vehicleQuery = Vehicle::query()
                ->visibleInBranch($company->id, $branch->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereNotNull('ownership')
                ->orderBy('registration_number')
                ->limit(300);

            if ($ownershipFilter !== []) {
                $vehicleQuery->whereIn('ownership', $ownershipFilter);
            }

            $vehicles = $vehicleQuery->get(['id', 'registration_number', 'ownership', 'make', 'model'])
                ->map(fn (Vehicle $vehicle): array => [
                    'id' => $vehicle->id,
                    'registration_number' => $vehicle->registration_number,
                    'ownership' => $vehicle->ownership?->value,
                    'ownership_label' => $vehicle->ownership?->label(),
                    'label' => trim($vehicle->registration_number.' · '.($vehicle->ownership?->label() ?? 'Unclassified')),
                ])->values()->all();
        }

        return [
            'rateTypeOptions' => collect(FreightRateType::cases())->map(fn (FreightRateType $type): array => [
                'value' => $type->value,
                'label' => str_replace('_', ' ', ucfirst($type->value)),
            ])->values()->all(),
            'consignors' => $consignors->map(fn (Party $party): array => [
                'id' => $party->id,
                'name' => $party->name,
            ])->values()->all(),
            'consignees' => $consignees->map(fn (Party $party): array => [
                'id' => $party->id,
                'name' => $party->name,
            ])->values()->all(),
            'billingCustomers' => $billingCustomers->map(fn (Party $party): array => [
                'id' => $party->id,
                'name' => $party->name,
            ])->values()->all(),
            'stations' => $stations->map(fn (Station $station): array => [
                'id' => $station->id,
                'name' => $station->name,
                'code' => $station->code,
            ])->values()->all(),
            'goodsTypes' => $goodsTypes->map(fn (OwnerCatalogueItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
            ])->values()->all(),
            'packageTypes' => $packageTypes->map(fn (OwnerCatalogueItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
            ])->values()->all(),
            'vehicles' => $vehicles,
        ];
    }

    /**
     * Direct Create LR control — never a Broker/OwnFleet/Partload chooser menu.
     *
     * @return array{
     *     mode: 'direct'|'blocked',
     *     label: string,
     *     url?: string,
     *     reason?: string
     * }
     */
    private function createActionPayload(
        Company $company,
        User $actor,
        Branch $branch,
    ): array {
        $resolution = $this->contextResolver->resolveForCreate($company, $actor, $branch, null);

        if ($resolution['status'] === 'blocked') {
            return [
                'mode' => 'blocked',
                'label' => 'Create LR',
                'reason' => $resolution['reason'],
            ];
        }

        $params = [];
        if ($resolution['form_mode'] === LrWorkflowContextResolver::FORM_PARTLOAD) {
            $params['context'] = 'partload';
        }

        return [
            'mode' => 'direct',
            'label' => 'Create LR',
            'url' => route('branch.lorry-receipts.create', $params, absolute: false),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function contextOptions(): array
    {
        $options = [];
        foreach (LrWorkflowContext::cases() as $context) {
            $options[] = [
                'value' => $context->value,
                'label' => $this->contextResolver->labelFor($context),
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(LorryReceipt $lr): array
    {
        return [
            'id' => $lr->id,
            'lr_number' => $lr->lr_number,
            'lr_date' => $lr->lr_date?->toDateString(),
            'workflow_context' => $lr->workflow_context->value,
            'status' => $lr->status->value,
            'from_text' => $lr->from_text,
            'to_text' => $lr->to_text,
            'freight_total' => $lr->freight_total,
            'additional_charges_total' => $lr->additional_charges_total,
            'consignor_name' => $lr->consignor?->name,
            'consignee_name' => $lr->consignee?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(LorryReceipt $lr): array
    {
        return [
            'id' => $lr->id,
            'lr_number' => $lr->lr_number,
            'lr_date' => $lr->lr_date?->toDateString(),
            'workflow_context' => $lr->workflow_context->value,
            'status' => $lr->status->value,
            'from_text' => $lr->from_text,
            'to_text' => $lr->to_text,
            'from_station_id' => $lr->from_station_id,
            'to_station_id' => $lr->to_station_id,
            'from_station_name' => $lr->fromStation?->name,
            'to_station_name' => $lr->toStation?->name,
            'consignor_party_id' => $lr->consignor_party_id,
            'consignee_party_id' => $lr->consignee_party_id,
            'vehicle_id' => $lr->vehicle_id,
            'billing_customer_party_id' => $lr->billing_customer_party_id,
            'consignor_name' => $lr->consignor?->name,
            'consignee_name' => $lr->consignee?->name,
            'goods_value' => $lr->goods_value,
            'freight_total' => $lr->freight_total,
            'additional_charges_total' => $lr->additional_charges_total,
            'delivered_at' => $lr->delivered_at?->toIso8601String(),
            'cancelled_at' => $lr->cancelled_at?->toIso8601String(),
            'cancellation_remark' => $lr->cancellation_remark,
            'goods' => $lr->goodsRows->map(function (LrGoodsRow $row): array {
                return [
                    'id' => $row->id,
                    'position' => $row->position,
                    'goods_type_id' => $row->goods_type_id,
                    'package_type_id' => $row->package_type_id,
                    'goods_description' => $row->goods_description,
                    'package_count' => $row->package_count,
                    'actual_weight' => $row->actual_weight,
                    'charged_weight' => $row->charged_weight,
                    'freight_rate_type' => $row->freight_rate_type->value,
                    'freight_rate' => $row->freight_rate,
                    'freight_amount' => $row->freight_amount,
                    'goods_value' => $row->goods_value,
                ];
            })->values()->all(),
            'eway_bills' => $lr->ewayBills->map(function (LrEwayBill $bill): array {
                return [
                    'id' => $bill->id,
                    'ewb_number' => $bill->ewb_number,
                    'ewb_date' => $bill->ewb_date?->toDateString(),
                    'valid_until' => $bill->valid_until?->toDateString(),
                ];
            })->values()->all(),
            'additional_charges' => $lr->additionalCharges->map(function (LrAdditionalCharge $charge): array {
                return [
                    'id' => $charge->id,
                    'description' => $charge->description,
                    'amount' => $charge->amount,
                ];
            })->values()->all(),
        ];
    }
}
