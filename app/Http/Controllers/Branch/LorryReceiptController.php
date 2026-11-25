<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\Exceptions\LorryReceiptAuthorizationException;
use App\Domains\Consignment\Exceptions\LorryReceiptConflictException;
use App\Domains\Consignment\Exceptions\LorryReceiptDomainException;
use App\Domains\Consignment\LorryReceiptOperationalStatusService;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Consignment\LrVehicleClassificationService;
use App\Domains\Consignment\LrWorkflowContextResolver;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Master\Vehicle\VehicleOptionQuery;
use App\Domains\OwnFleet\Trip\OwnFleetBiltyTripOrchestrator;
use App\Domains\OwnFleet\Trip\TripService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\LrAdditionalCharge;
use App\Models\LrEwayBill;
use App\Models\LrGoodsRow;
use App\Models\OwnerCatalogueItem;
use App\Models\Station;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;
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
    use PresentsDocumentDrawer;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly LorryReceiptService $lorryReceipts,
        private readonly LrWorkflowContextResolver $contextResolver,
        private readonly LrVehicleClassificationService $vehicleClassification,
        private readonly TripService $trips,
        private readonly OwnFleetBiltyTripOrchestrator $ownFleetBiltyTrip,
        private readonly LorryReceiptOperationalStatusService $lrOperationalStatus,
        private readonly LorryHireService $lorryHires,
        private readonly FreightAmountPresentation $freightPresentation,
        private readonly DocumentAttachmentService $documents,
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_VIEW);

        $ownership = $request->string('ownership')->toString() ?: null;
        $awaitingTrip = $request->boolean('awaiting_trip');
        $hasTrip = $request->boolean('has_trip');

        $page = $this->lorryReceipts->search(
            company: $company,
            branch: $branch,
            query: $request->string('q')->toString() ?: null,
            status: $request->string('status')->toString() ?: null,
            workflowContext: $request->string('workflow_context')->toString() ?: null,
            ownership: $ownership,
            awaitingTrip: $awaitingTrip,
            hasTrip: $hasTrip,
            perPage: min(100, max(1, (int) $request->input('per_page', 15))),
        );

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $permissions = $this->lrPermissions($actor, $company, $branch, $maySeeFreight);

        return Inertia::render('branch/lorry-receipts/index', [
            'filters' => [
                'q' => $request->string('q')->toString(),
                'status' => $request->string('status')->toString(),
                'workflow_context' => $request->string('workflow_context')->toString(),
                'ownership' => $ownership ?? '',
                'awaiting_trip' => $awaitingTrip ? '1' : '',
                'has_trip' => $hasTrip ? '1' : '',
            ],
            'permissions' => $permissions,
            'statusOptions' => collect(LrStatus::cases())
                ->reject(fn (LrStatus $status): bool => $status === LrStatus::Cancelled)
                ->map(fn (LrStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])->values()->all(),
            'contextOptions' => $this->contextOptions(),
            'createAction' => $permissions['can_create']
                ? $this->createActionPayload($company, $actor, $branch)
                : null,
            'partloadCreateUrl' => $permissions['can_create'] && $this->contextResolver->hasPartloadWorkflow($company)
                ? route('branch.lorry-receipts.create', ['context' => 'partload'], absolute: false)
                : null,
            'lorryReceipts' => [
                'data' => collect($page->items())->map(
                    fn (LorryReceipt $lr): array => $this->listPayload($lr, $maySeeFreight, $actor, $company, $branch),
                )->values()->all(),
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
            'permissions' => $this->lrPermissions(
                $actor,
                $company,
                $branch,
                $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch),
            ),
            'urls' => [
                'index' => route('branch.lorry-receipts.index', absolute: false),
                'store' => route('branch.lorry-receipts.store', absolute: false),
                'parties_options' => route('branch.parties.options', absolute: false),
                'parties_tds_for_payee' => route('branch.parties.tds-for-payee', absolute: false),
                'parties_create' => route('branch.parties.create', absolute: false),
                'parties_store' => route('branch.parties.store', absolute: false),
                'vehicles_options' => route('branch.vehicles.options', absolute: false),
                'vehicles_create' => route('branch.vehicles.create', absolute: false),
                'vehicles_store' => route('branch.vehicles.store', absolute: false),
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
                'form_mode' => $resolution['reason'],
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

        $createTrip = ($payload['workflow_context'] ?? null) === LrWorkflowContext::OwnFleet->value
            && $this->authorizer->allowsCustomer(
                $actor,
                PermissionCodes::TRIP_CREATE,
                $company,
                $branch,
            );

        try {
            if ($createTrip) {
                $created = $this->ownFleetBiltyTrip->create($company, $branch, $actor, $payload, true);
                $lr = $created['lorry_receipt'];
                $trip = $created['trip'];
            } else {
                $lr = $this->lorryReceipts->create($company, $branch, $actor, $payload);
                $trip = null;
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (LorryReceiptAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (LorryReceiptConflictException $e) {
            throw ValidationException::withMessages(['lr_number' => $e->getMessage()]);
        } catch (LorryReceiptDomainException $e) {
            throw ValidationException::withMessages(['workflow_context' => $e->getMessage()]);
        }

        $redirectToHire = $request->boolean('redirect_to_hire')
            && $lr->workflow_context === LrWorkflowContext::Broker
            && $this->authorizer->allowsCustomer(
                $actor,
                PermissionCodes::BROKER_LORRY_HIRE_CREATE,
                $company,
                $branch,
            );

        if ($redirectToHire) {
            return redirect()
                ->route('branch.broker.lorry-hires.create-from-lr', [$lr])
                ->with('success', 'Bilty created. Continue with Lorry Hire.');
        }

        $openExpenses = $request->boolean('redirect_to_trip')
            && $trip !== null
            && $this->authorizer->allowsCustomer(
                $actor,
                PermissionCodes::TRIP_VIEW,
                $company,
                $branch,
            );

        if ($openExpenses) {
            return redirect()
                ->route('branch.trips.expenses', [$trip])
                ->with('success', 'Bilty and Trip created. Continue with expenses and status.');
        }

        if ($trip !== null) {
            return redirect()
                ->route('branch.lorry-receipts.show', [$lr])
                ->with('success', 'Bilty created with OwnFleet Trip context.');
        }

        return redirect()
            ->route('branch.lorry-receipts.show', [$lr])
            ->with('success', 'Lorry Receipt created.');
    }

    public function changeStatus(
        Request $request,
        LorryReceipt $lorryReceipt,
        Company $company,
        Branch $branch,
    ): RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_EDIT);
        $this->assertLr($lorryReceipt, $company, $branch);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                LrStatus::InProgress->value,
                LrStatus::Delivered->value,
            ])],
            'starting_odometer' => ['nullable', 'numeric', 'min:0'],
            'ending_odometer' => ['nullable', 'numeric', 'min:0'],
            'departure_at' => ['nullable', 'date'],
            'actual_arrival_at' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->lrOperationalStatus->changeStatus($lorryReceipt, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (LorryReceiptDomainException $e) {
            abort(403, $e->getMessage());
        }

        $statusLabel = LrStatus::from($validated['status'])->label();

        return redirect()
            ->route('branch.lorry-receipts.index')
            ->with('success', 'Bilty status updated to '.$statusLabel.'.');
    }

    public function show(Request $request, LorryReceipt $lorryReceipt, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::LR_VIEW);
        $this->assertLr($lorryReceipt, $company, $branch);

        $lorryReceipt->load([
            'goodsRows.goodsType',
            'ewayBills',
            'additionalCharges',
            'consignor',
            'consignee',
            'billingCustomer',
            'fromStation',
            'toStation',
            'vehicle',
        ]);

        $linkedTrip = $this->trips->tripForLorryReceipt($lorryReceipt);
        $lorryReceipt->setRelation('trip', $linkedTrip);
        $linkedLorryHire = $this->lorryHires->hireForLorryReceipt($lorryReceipt);
        $lorryReceipt->setRelation('lorryHire', $linkedLorryHire);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $primaryOperation = $this->primaryOperationAction($lorryReceipt, $actor, $company, $branch);

        return Inertia::render('branch/lorry-receipts/show', [
            'lorryReceipt' => $this->detailPayload($lorryReceipt, $maySeeFreight),
            'permissions' => $this->lrPermissions($actor, $company, $branch, $maySeeFreight),
            'primaryOperation' => $primaryOperation,
            'linkedTrip' => $linkedTrip === null ? null : [
                'id' => $linkedTrip->id,
                'status' => $linkedTrip->status->value,
                'show_url' => route('branch.trips.show', [$linkedTrip], absolute: false),
                'expenses_url' => route('branch.trips.expenses', [$linkedTrip], absolute: false),
            ],
            'linkedLorryHire' => $linkedLorryHire === null ? null : [
                'id' => $linkedLorryHire->id,
                'hire_number' => $linkedLorryHire->hire_number,
                'show_url' => route('branch.broker.lorry-hires.show', [$linkedLorryHire], absolute: false),
            ],
            'urls' => [
                'index' => route('branch.lorry-receipts.index', absolute: false),
                'edit' => route('branch.lorry-receipts.edit', [$lorryReceipt], absolute: false),
                'cancel' => route('branch.lorry-receipts.cancel', [$lorryReceipt], absolute: false),
                'destroy' => route('branch.lorry-receipts.destroy', [$lorryReceipt], absolute: false),
                'print' => route('branch.lorry-receipts.print', [$lorryReceipt], absolute: false),
                'documents' => route('branch.documents.index', [
                    'parentType' => 'lorry_receipt',
                    'parentId' => $lorryReceipt->id,
                ], absolute: false),
            ],
            'documentDrawer' => $this->documentDrawerPayload(
                $actor,
                $company,
                $branch,
                'lorry_receipt',
                $lorryReceipt->id,
                $this->documents,
                [
                    'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_EDIT, $company, $branch),
                    'can_edit' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_EDIT, $company, $branch),
                    'can_delete' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_EDIT, $company, $branch),
                ],
            ),
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

        $lorryReceipt->load(['goodsRows.goodsType', 'ewayBills', 'additionalCharges']);
        $context = $lorryReceipt->workflow_context;

        $formMode = $context === LrWorkflowContext::Partload
            ? LrWorkflowContextResolver::FORM_PARTLOAD
            : LrWorkflowContextResolver::FORM_TRUCK;

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('branch/lorry-receipts/edit', [
            'mode' => 'edit',
            'formMode' => $formMode,
            'workflowContext' => $context->value,
            'contextLabel' => $this->contextResolver->labelFor($context),
            'lorryReceipt' => $this->detailPayload($lorryReceipt, $maySeeFreight),
            'formOptions' => $this->formOptions($company, $branch, $actor, $formMode),
            'permissions' => $this->lrPermissions($actor, $company, $branch, $maySeeFreight),
            'urls' => [
                'index' => route('branch.lorry-receipts.index', absolute: false),
                'show' => route('branch.lorry-receipts.show', [$lorryReceipt], absolute: false),
                'update' => route('branch.lorry-receipts.update', [$lorryReceipt], absolute: false),
                'parties_options' => route('branch.parties.options', absolute: false),
                'parties_tds_for_payee' => route('branch.parties.tds-for-payee', absolute: false),
                'parties_create' => route('branch.parties.create', absolute: false),
                'parties_store' => route('branch.parties.store', absolute: false),
                'vehicles_options' => route('branch.vehicles.options', absolute: false),
                'vehicles_create' => route('branch.vehicles.create', absolute: false),
                'vehicles_store' => route('branch.vehicles.store', absolute: false),
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

        $validated = $request->validate([
            'remark' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $this->lorryReceipts->softDelete($lorryReceipt, $actor, $validated['remark']);
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

        $lorryReceipt->load(['goodsRows.goodsType', 'ewayBills', 'additionalCharges', 'consignor', 'consignee', 'fromStation', 'toStation']);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('branch/lorry-receipts/print', [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'lorryReceipt' => $this->detailPayload($lorryReceipt, $maySeeFreight),
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
    private function lrPermissions(User $actor, Company $company, Branch $branch, bool $maySeeFreight): array
    {
        return [
            'can_view' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_VIEW, $company, $branch),
            'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_CREATE, $company, $branch),
            'can_edit' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_EDIT, $company, $branch),
            'can_cancel' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_CANCEL, $company, $branch),
            'can_delete' => $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_DELETE, $company, $branch),
            'can_view_freight' => $maySeeFreight,
            'can_create_lorry_hire' => $this->authorizer->allowsCustomer(
                $actor,
                PermissionCodes::BROKER_LORRY_HIRE_CREATE,
                $company,
                $branch,
            ),
            'can_create_trip' => $this->authorizer->allowsCustomer(
                $actor,
                PermissionCodes::TRIP_CREATE,
                $company,
                $branch,
            ),
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
            'intended_payee_type' => ['nullable', Rule::in(['owner', 'agency'])],
            'intended_payee_party_id' => ['nullable', 'uuid'],
            'billing_customer_party_id' => ['required', 'uuid'],
            'lr_date' => ['nullable', 'date'],
            'from_text' => ['nullable', 'string', 'max:255'],
            'to_text' => ['nullable', 'string', 'max:255'],
            'from_station_id' => ['nullable', 'uuid'],
            'to_station_id' => ['nullable', 'uuid'],
            'consignor_party_id' => ['nullable', 'uuid'],
            'consignee_party_id' => ['nullable', 'uuid'],
            'goods_value' => ['nullable', 'numeric', 'min:0'],
            'external_gr_number' => ['nullable', 'string', 'max:100'],
            'client_invoice_number' => ['nullable', 'string', 'max:100'],
            'reference_by' => ['nullable', 'string', 'max:255'],
            'reference_by_party_id' => ['nullable', 'uuid'],
            'goods' => ['required', 'array', 'min:1'],
            'goods.*.goods_type_id' => ['nullable', 'uuid'],
            'goods.*.package_type_id' => ['nullable', 'uuid'],
            'goods.*.goods_description' => ['nullable', 'string', 'max:255'],
            'goods.*.package_count' => ['required', 'integer', 'min:1'],
            'goods.*.actual_weight' => ['required', 'numeric', 'gt:0'],
            'goods.*.charged_weight' => ['required', 'numeric', 'gt:0'],
            // Historical rows may still carry per_kg / per_mt; UI options expose only the four new types.
            'goods.*.freight_rate_type' => ['required', Rule::enum(FreightRateType::class)],
            'goods.*.rate_semantics' => ['nullable', 'string', Rule::in(['weight_kg', 'weight_mt'])],
            'goods.*.freight_rate' => ['required', 'numeric', 'gt:0'],
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
            // Optional for internal callers; public UI omits number_mode — service infers from lr_number.
            $rules['number_mode'] = ['nullable', Rule::in(['auto', 'manual'])];
            $rules['lr_number'] = ['nullable', 'string', 'max:64'];
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
        $partyOptions = app(PartyOptionQuery::class);
        $consignors = $partyOptions->forRoles($company, $branch, PartyRoleType::Consignor, 300);
        $consignees = $partyOptions->forRoles($company, $branch, PartyRoleType::Consignee, 300);
        $billingCustomers = $partyOptions->forRoles($company, $branch, PartyRoleType::Customer, 300);

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
                    $ownershipFilter[] = VehicleOwnership::Own;
                }
                if ($context === LrWorkflowContext::Broker) {
                    $ownershipFilter[] = VehicleOwnership::Market;
                }
            }

            $vehicles = app(VehicleOptionQuery::class)->forBranch(
                company: $company,
                branch: $branch,
                limit: 200,
                ownerships: $ownershipFilter !== [] ? $ownershipFilter : null,
            );
        }

        return [
            'rateTypeOptions' => collect([
                FreightRateType::FixedAmount,
                FreightRateType::PerPackageUnit,
                FreightRateType::PerChargedWeight,
                FreightRateType::PerActualWeight,
            ])->map(fn (FreightRateType $type): array => [
                'value' => $type->value,
                'label' => match ($type) {
                    FreightRateType::FixedAmount => 'Fixed Amount',
                    FreightRateType::PerPackageUnit => 'Per Unit',
                    FreightRateType::PerActualWeight => 'Per Actual Weight',
                    FreightRateType::PerChargedWeight => 'Per Charged Weight',
                    default => $type->value,
                },
            ])->values()->all(),
            'consignors' => $consignors,
            'consignees' => $consignees,
            'billingCustomers' => $billingCustomers,
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
            'vehicleQuickCreate' => $this->vehicleQuickCreateOptions($company, $branch),
        ];
    }

    /**
     * Catalogue / driver options for inline Own Vehicle quick create on Bilty.
     *
     * @return array{
     *     truckTypes: list<array{id: string, name: string}>,
     *     tyreLayouts: list<array{id: string, name: string}>,
     *     drivers: list<array{id: string, name: string}>
     * }
     */
    private function vehicleQuickCreateOptions(Company $company, Branch $branch): array
    {
        $truckTypes = OwnerCatalogueItem::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', OwnerCatalogueType::TruckType->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);

        $tyreLayouts = OwnerCatalogueItem::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', OwnerCatalogueType::TyreLayout->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);

        $drivers = app(PartyOptionQuery::class)->forRoles(
            $company,
            $branch,
            PartyRoleType::Driver,
            100,
        );

        return [
            'truckTypes' => $truckTypes->map(fn (OwnerCatalogueItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
            ])->values()->all(),
            'tyreLayouts' => $tyreLayouts->map(fn (OwnerCatalogueItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
            ])->values()->all(),
            'drivers' => collect($drivers)->map(fn (array $driver): array => [
                'id' => (string) $driver['id'],
                'name' => (string) $driver['name'],
            ])->values()->all(),
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
    private function listPayload(LorryReceipt $lr, bool $maySeeFreight, User $actor, Company $company, Branch $branch): array
    {
        $ownership = $lr->vehicle?->ownership;
        $loadingStation = $lr->fromStation !== null ? $lr->fromStation->name : $lr->from_text;
        $unloadingStation = $lr->toStation !== null ? $lr->toStation->name : $lr->to_text;
        $chargedWeight = '0.000';
        foreach ($lr->goodsRows as $row) {
            $weight = trim((string) ($row->charged_weight ?? '0'));
            if ($weight === '' || ! is_numeric($weight)) {
                continue;
            }
            $chargedWeight = bcadd($chargedWeight, $weight, 3);
        }

        return [
            'id' => $lr->id,
            'lr_number' => $lr->lr_number,
            'lr_date' => $lr->lr_date?->toDateString(),
            'workflow_context' => $lr->workflow_context->value,
            'status' => $lr->status->value,
            'status_label' => $lr->status->label(),
            'from_text' => $lr->from_text,
            'to_text' => $lr->to_text,
            'loading_station' => $loadingStation,
            'unloading_station' => $unloadingStation,
            'vehicle_registration' => $lr->vehicle?->registration_number,
            'vehicle_ownership' => $ownership?->value,
            'vehicle_ownership_label' => $ownership === VehicleOwnership::Own
                ? 'Own'
                : ($ownership === VehicleOwnership::Market ? 'Market' : null),
            'billing_customer_name' => $lr->billingCustomer?->name,
            'charged_weight_total' => $chargedWeight,
            'freight_total' => $this->freightPresentation->presentAmount($lr->freight_total, $maySeeFreight),
            'additional_charges_total' => $this->freightPresentation->presentAmount($lr->additional_charges_total, $maySeeFreight),
            'consignor_name' => $lr->consignor?->name,
            'consignee_name' => $lr->consignee?->name,
            'primary_operation' => $this->primaryOperationAction($lr, $actor, $company, $branch),
            'show_url' => route('branch.lorry-receipts.show', [$lr], absolute: false),
            'edit_url' => $lr->status === LrStatus::Pending
                ? route('branch.lorry-receipts.edit', [$lr], absolute: false)
                : null,
            'print_url' => route('branch.lorry-receipts.print', [$lr], absolute: false),
            'destroy_url' => $lr->status === LrStatus::Pending
                && $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_DELETE, $company, $branch)
                ? route('branch.lorry-receipts.destroy', [$lr], absolute: false)
                : null,
        ];
    }

    /**
     * @return array{
     *     label: string,
     *     url: string,
     *     kind: string,
     *     expenses_url?: string,
     *     change_status_url?: string,
     *     next_status?: string|null,
     *     destroy_url?: string
     * }|null
     */
    private function primaryOperationAction(LorryReceipt $lr, User $actor, Company $company, Branch $branch): ?array
    {
        if ($lr->status === LrStatus::Cancelled) {
            return null;
        }

        if ($lr->workflow_context === LrWorkflowContext::OwnFleet) {
            $trip = $lr->trip;
            if ($trip === null) {
                return null;
            }

            if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_VIEW, $company, $branch)) {
                return null;
            }

            $nextStatus = match ($lr->status) {
                LrStatus::Pending => LrStatus::InProgress->value,
                LrStatus::InProgress => LrStatus::Delivered->value,
                default => null,
            };

            return [
                'kind' => 'manage_expenses',
                'label' => 'Manage Expenses',
                'url' => route('branch.trips.expenses', [$trip], absolute: false),
                'expenses_url' => route('branch.trips.expenses', [$trip], absolute: false),
                'change_status_url' => $nextStatus !== null
                    ? route('branch.lorry-receipts.change-status', [$lr], absolute: false)
                    : null,
                'next_status' => $nextStatus,
            ];
        }

        if ($lr->workflow_context === LrWorkflowContext::Broker) {
            if (! $this->moduleEntitlements->isEntitled($company, SubscriptionModule::Broker)) {
                return null;
            }

            $hire = $lr->lorryHire;
            if ($hire !== null) {
                if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LORRY_HIRE_VIEW, $company, $branch)) {
                    return null;
                }

                return [
                    'kind' => 'view_lorry_hire',
                    'label' => 'View Lorry Hire',
                    'url' => route('branch.broker.lorry-hires.show', [$hire], absolute: false),
                ];
            }

            if (
                in_array($lr->status, [LrStatus::Pending, LrStatus::InProgress], true)
                && $lr->vehicle?->ownership === VehicleOwnership::Market
                && $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LORRY_HIRE_CREATE, $company, $branch)
            ) {
                return [
                    'kind' => 'create_lorry_hire',
                    'label' => 'Create Lorry Hire',
                    'url' => route('branch.broker.lorry-hires.create-from-lr', [$lr], absolute: false),
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(LorryReceipt $lr, bool $maySeeFreight): array
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
            'intended_payee_type' => $lr->intended_payee_type,
            'intended_payee_party_id' => $lr->intended_payee_party_id,
            'billing_customer_party_id' => $lr->billing_customer_party_id,
            'billing_customer_name' => $lr->billingCustomer?->name,
            'consignor_name' => $lr->consignor?->name,
            'consignee_name' => $lr->consignee?->name,
            'vehicle_registration' => $lr->vehicle?->registration_number,
            'goods_value' => $lr->goods_value,
            'external_gr_number' => $lr->external_gr_number,
            'client_invoice_number' => $lr->client_invoice_number,
            'reference_by' => $lr->reference_by,
            'reference_by_party_id' => $lr->reference_by_party_id,
            'reference_by_party_name' => $lr->referenceByParty?->name,
            'freight_total' => $this->freightPresentation->presentAmount($lr->freight_total, $maySeeFreight),
            'additional_charges_total' => $this->freightPresentation->presentAmount($lr->additional_charges_total, $maySeeFreight),
            'delivered_at' => $lr->delivered_at?->toIso8601String(),
            'cancelled_at' => $lr->cancelled_at?->toIso8601String(),
            'cancellation_remark' => $lr->cancellation_remark,
            'goods' => $lr->goodsRows->map(function (LrGoodsRow $row) use ($maySeeFreight): array {
                $description = $row->goods_description;
                $typeName = $row->goodsType?->name;

                return [
                    'id' => $row->id,
                    'position' => $row->position,
                    'goods_type_id' => $row->goods_type_id,
                    'package_type_id' => $row->package_type_id,
                    'goods_type_name' => $typeName,
                    'goods_description' => $description,
                    'goods_label' => filled($description)
                        ? (string) $description
                        : ($typeName ?? 'Goods'),
                    'package_count' => $row->package_count,
                    'actual_weight' => $row->actual_weight,
                    'charged_weight' => $row->charged_weight,
                    'freight_rate_type' => $row->freight_rate_type->value,
                    'rate_semantics' => $row->rate_semantics?->value ?? 'weight_kg',
                    'freight_rate' => $this->freightPresentation->presentAmount($row->freight_rate, $maySeeFreight),
                    'freight_amount' => $this->freightPresentation->presentAmount($row->freight_amount, $maySeeFreight),
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
            'additional_charges' => $lr->additionalCharges->map(function (LrAdditionalCharge $charge) use ($maySeeFreight): array {
                return [
                    'id' => $charge->id,
                    'description' => $charge->description,
                    'amount' => $this->freightPresentation->presentAmount($charge->amount, $maySeeFreight),
                ];
            })->values()->all(),
        ];
    }
}
