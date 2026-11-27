<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Enquiry\EnquiryAssignmentService;
use App\Domains\Enquiry\EnquiryConversionService;
use App\Domains\Enquiry\EnquiryFollowUpService;
use App\Domains\Enquiry\EnquiryQuotationService;
use App\Domains\Enquiry\EnquiryService;
use App\Domains\Enquiry\Exceptions\EnquiryAuthorizationException;
use App\Domains\Enquiry\Exceptions\EnquiryConflictException;
use App\Domains\Enquiry\Exceptions\EnquiryDomainException;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Enquiry;
use App\Models\EnquiryAssignmentHistory;
use App\Models\EnquiryFollowUp;
use App\Models\EnquiryGoodsRow;
use App\Models\EnquiryQuotation;
use App\Models\EnquiryQuotationBroadcastRequest;
use App\Models\EnquiryStatusHistory;
use App\Models\OwnerCatalogueItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Presentation\FreightAmountPresentation;
use App\Support\Weight\OperationalWeight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branch enquiry JSON API + Inertia product UI (PO-P4-DESIGN-FORMAL).
 */
class EnquiryController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly EnquiryService $enquiries,
        private readonly EnquiryAssignmentService $assignments,
        private readonly EnquiryFollowUpService $followUps,
        private readonly EnquiryQuotationService $quotations,
        private readonly EnquiryConversionService $conversions,
        private readonly FreightAmountPresentation $freightPresentation = new FreightAmountPresentation,
    ) {}

    public function store(Request $request, Company $company, Branch $branch): JsonResponse|RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_CREATE);

        $payload = $this->validatedCreatePayload($request);

        try {
            $enquiry = $this->enquiries->createManual($company, $branch, $actor, $payload);
        } catch (ValidationException $e) {
            throw $e;
        } catch (EnquiryAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (EnquiryDomainException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            throw ValidationException::withMessages(['customer_party_id' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $this->enquiryPayload(
                    $enquiry->load('goodsRows', 'statusHistories'),
                    $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch),
                ),
            ], 201);
        }

        return redirect()
            ->route('branch.enquiries.show', [$enquiry])
            ->with('success', 'Enquiry created.');
    }

    public function update(Request $request, Enquiry $enquiry, Company $company, Branch $branch): JsonResponse|RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_EDIT);

        if ($enquiry->company_id !== $company->id || $enquiry->branch_id !== $branch->id) {
            abort(404);
        }

        $payload = $this->validatedUpdatePayload($request);
        $remark = trim((string) ($payload['remark'] ?? $request->input('remark', '')));

        try {
            $updated = $this->enquiries->updateManual($company, $branch, $enquiry, $actor, $payload, $remark);
        } catch (ValidationException $e) {
            throw $e;
        } catch (EnquiryAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (EnquiryDomainException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $this->enquiryPayload(
                    $updated->load('goodsRows'),
                    $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch),
                ),
            ]);
        }

        return redirect()
            ->route('branch.enquiries.show', [$enquiry])
            ->with('success', 'Enquiry updated.');
    }

    /**
     * Branch-scoped list. Unassigned company queue requires enquiry.assign (Batch C visibility gate documented).
     */
    public function index(Request $request, Company $company, Branch $branch): JsonResponse|Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_VIEW);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $includeUnassigned = $request->boolean('unassigned')
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::ENQUIRY_ASSIGN, $company, $branch);

        $query = Enquiry::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->with([
                'goodsRows' => fn ($q) => $q->orderBy('position'),
                'goodsRows.goodsType:id,name',
                'customerParty:id,name,short_name',
                'consignorParty:id,name,short_name',
                'consigneeParty:id,name,short_name',
                'fromCity:id,name',
                'fromState:id,name',
                'toCity:id,name',
                'toState:id,name',
                'vehicleType:id,name',
            ])
            ->orderByDesc('created_at');

        if ($includeUnassigned) {
            $query->where(function ($builder) use ($branch): void {
                $builder->where('branch_id', $branch->id)->orWhereNull('branch_id');
            });
        } else {
            $query->where('branch_id', $branch->id);
        }

        $page = $query->paginate($perPage);
        $payload = [
            'data' => collect($page->items())
                ->map(fn (Enquiry $enquiry): array => $this->enquiryPayload($enquiry, $maySeeFreight))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'includes_unassigned' => $includeUnassigned,
                'unassigned_visibility_note' => 'Company unassigned queue requires enquiry.assign.',
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('branch/enquiries/index', [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'filters' => [
                'unassigned' => $includeUnassigned,
            ],
            'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::ENQUIRY_CREATE, $company, $branch),
            'can_include_unassigned' => $this->authorizer->allowsCustomer($actor, PermissionCodes::ENQUIRY_ASSIGN, $company, $branch),
            'can_view_freight' => $maySeeFreight,
            'enquiries' => $payload,
            'urls' => [
                'index' => route('branch.enquiries.index'),
                'create' => route('branch.enquiries.create'),
                'masters' => route('branch.masters.index'),
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_CREATE);

        $customers = app(PartyOptionQuery::class)
            ->forRoles($company, $branch, PartyRoleType::Customer);

        $vehicleTypes = OwnerCatalogueItem::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', OwnerCatalogueType::TruckType->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);

        $goodsTypes = OwnerCatalogueItem::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', OwnerCatalogueType::GoodsType->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);

        $consignors = app(PartyOptionQuery::class)->forRoles($company, $branch, PartyRoleType::Consignor);
        $consignees = app(PartyOptionQuery::class)->forRoles($company, $branch, PartyRoleType::Consignee);

        return Inertia::render('branch/enquiries/create', [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'customers' => $customers,
            'consignors' => $consignors,
            'consignees' => $consignees,
            'goods_types' => $goodsTypes->map(fn ($type): array => [
                'id' => $type->id,
                'name' => $type->name,
            ])->values()->all(),
            'vehicle_types' => $vehicleTypes->map(fn ($type): array => [
                'id' => $type->id,
                'name' => $type->name,
            ])->values()->all(),
            'urls' => [
                'index' => route('branch.enquiries.index'),
                'store' => route('branch.enquiries.store'),
                'parties_create' => route('branch.parties.create', absolute: false),
                'parties_store' => route('branch.parties.store', absolute: false),
                'parties_options' => route('branch.parties.options', absolute: false),
                'goods_types_store' => route('branch.goods-types.store', absolute: false),
                'truck_types_store' => route('branch.truck-types.store', absolute: false),
            ],
        ]);
    }

    public function show(Request $request, Enquiry $enquiry, Company $company, Branch $branch): JsonResponse|Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_VIEW);

        if ($enquiry->company_id !== $company->id) {
            abort(404);
        }

        $canSeeUnassigned = $enquiry->branch_id === null
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::ENQUIRY_ASSIGN, $company, $branch);

        if ($enquiry->branch_id !== $branch->id && ! $canSeeUnassigned) {
            abort(404);
        }

        $enquiry->load([
            'goodsRows',
            'statusHistories',
            'assignmentHistories',
            'followUps',
            'quotations.transporter',
            'broadcastRequests.targets',
            'conversion.lorryReceiptLinks',
            'assignedUser',
            'customerParty',
        ]);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $data = $this->enquiryPayload($enquiry, $maySeeFreight);
        $canAssign = $this->authorizer->allowsCustomer($actor, PermissionCodes::ENQUIRY_ASSIGN, $company, $branch);
        $canEdit = $this->authorizer->allowsCustomer($actor, PermissionCodes::ENQUIRY_EDIT, $company, $branch);
        $canConvert = $this->authorizer->allowsCustomer($actor, PermissionCodes::ENQUIRY_CONVERT, $company, $branch);
        $conversionPreview = $canConvert
            ? $this->redactConversionPreview(
                $this->conversions->preview($company, $branch, $enquiry),
                $maySeeFreight,
            )
            : null;

        if ($request->expectsJson()) {
            return response()->json(['data' => $data]);
        }

        $customers = app(PartyOptionQuery::class)
            ->forRoles(
                $company,
                $branch,
                PartyRoleType::Customer,
                selectedId: $enquiry->customer_party_id,
            );

        return Inertia::render('branch/enquiries/show', [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'enquiry' => $data,
            'customers' => $customers,
            'branches' => $this->branchOptions($company),
            'assignable_users' => $this->assignableUsers($company, $branch),
            'transporters' => $this->transporterOptions($company, $branch),
            'vehicles' => $this->vehicleOptions($company),
            'conversion_preview' => $conversionPreview,
            'capabilities' => [
                'assign' => $canAssign,
                'edit' => $canEdit,
                'convert' => $canConvert,
                'view_freight' => $maySeeFreight,
            ],
            'urls' => [
                'index' => route('branch.enquiries.index'),
                'update' => route('branch.enquiries.update', [$enquiry]),
                'assign' => route('branch.enquiries.assign', [$enquiry]),
                'follow_up' => route('branch.enquiries.follow-ups.store', [$enquiry]),
                'quotation_store' => route('branch.enquiries.quotations.store', [$enquiry]),
                'broadcast' => route('branch.enquiries.quotation-broadcasts.store', [$enquiry]),
                'conversion_preview' => route('branch.enquiries.conversion-preview', [$enquiry]),
                'convert' => route('branch.enquiries.convert', [$enquiry]),
                'parties_create' => route('branch.parties.create', absolute: false),
                'parties_store' => route('branch.parties.store', absolute: false),
                'parties_options' => route('branch.parties.options', absolute: false),
            ],
        ]);
    }

    public function assign(Request $request, Enquiry $enquiry, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_ASSIGN);
        $this->assertEnquiryRouteScope($enquiry, $company, $branch, $actor);

        $payload = $request->validate([
            'remark' => ['required', 'string', 'min:1'],
            'to_branch_id' => ['nullable', 'uuid'],
            'to_assigned_user_id' => ['nullable', 'integer'],
        ]);

        try {
            $updated = $this->assignments->assign($company, $branch, $enquiry, $actor, $payload);
        } catch (ValidationException $e) {
            throw $e;
        } catch (EnquiryAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (EnquiryDomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $this->enquiryPayload(
                    $updated,
                    $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch),
                ),
            ]);
        }

        return back()->with('success', 'Enquiry assignment updated.');
    }

    public function storeFollowUp(Request $request, Enquiry $enquiry, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_EDIT);
        $this->assertEnquiryRouteScope($enquiry, $company, $branch, $actor);

        $payload = $request->validate([
            'follow_up_at' => ['required', 'date'],
            'remarks' => ['required', 'string', 'min:1'],
        ]);

        try {
            $followUp = $this->followUps->add($company, $branch, $enquiry, $actor, $payload);
        } catch (EnquiryAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (EnquiryDomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $followUp], 201);
        }

        return back()->with('success', 'Follow-up added.');
    }

    public function completeFollowUp(
        Request $request,
        Enquiry $enquiry,
        EnquiryFollowUp $followUp,
        Company $company,
        Branch $branch,
    ): RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_EDIT);
        $this->assertEnquiryRouteScope($enquiry, $company, $branch, $actor);

        try {
            $this->followUps->complete($company, $branch, $enquiry, $followUp, $actor);
        } catch (EnquiryAuthorizationException|EnquiryDomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        return back()->with('success', 'Follow-up marked complete.');
    }

    public function storeQuotation(Request $request, Enquiry $enquiry, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_EDIT);
        $this->assertEnquiryRouteScope($enquiry, $company, $branch, $actor);

        $payload = $request->validate([
            'transporter_party_id' => ['required', 'uuid'],
            'quoted_freight' => ['required', 'numeric', 'gt:0'],
            'rate_basis' => ['nullable', 'string', 'max:64'],
            'advance_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'expected_delivery_duration' => ['nullable', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
            'extra_charges_amount' => ['nullable', 'numeric', 'min:0'],
            'extra_charges_description' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);

        try {
            $quotation = $this->quotations->create($company, $branch, $enquiry, $actor, $payload);
        } catch (EnquiryConflictException $e) {
            return back()->withErrors(['transporter_party_id' => $e->getMessage()]);
        } catch (EnquiryAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (EnquiryDomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $quotation], 201);
        }

        return back()->with('success', 'Quotation saved.');
    }

    public function updateQuotation(
        Request $request,
        Enquiry $enquiry,
        EnquiryQuotation $quotation,
        Company $company,
        Branch $branch,
    ): RedirectResponse|JsonResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_EDIT);
        $this->assertEnquiryRouteScope($enquiry, $company, $branch, $actor);

        $payload = $request->validate([
            'remark' => ['required', 'string', 'min:1'],
            'quoted_freight' => ['sometimes', 'numeric', 'gt:0'],
            'rate_basis' => ['nullable', 'string', 'max:64'],
            'advance_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'expected_delivery_duration' => ['nullable', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
            'extra_charges_amount' => ['nullable', 'numeric', 'min:0'],
            'extra_charges_description' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->quotations->update(
                $company,
                $branch,
                $enquiry,
                $quotation,
                $actor,
                $payload,
                (string) $payload['remark'],
            );
        } catch (EnquiryAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (EnquiryDomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $updated]);
        }

        return back()->with('success', 'Quotation updated.');
    }

    public function selectQuotation(
        Request $request,
        Enquiry $enquiry,
        EnquiryQuotation $quotation,
        Company $company,
        Branch $branch,
    ): RedirectResponse|JsonResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_EDIT);
        $this->assertEnquiryRouteScope($enquiry, $company, $branch, $actor);

        $remark = $request->validate(['remark' => ['nullable', 'string']])['remark'] ?? null;

        try {
            $updated = $this->quotations->selectPreferred($company, $branch, $enquiry, $quotation, $actor, $remark);
        } catch (EnquiryAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (EnquiryDomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $this->enquiryPayload(
                    $updated,
                    $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch),
                ),
            ]);
        }

        return back()->with('success', 'Preferred quotation selected.');
    }

    public function storeQuotationBroadcast(Request $request, Enquiry $enquiry, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_EDIT);
        $this->assertEnquiryRouteScope($enquiry, $company, $branch, $actor);

        $payload = $request->validate([
            'transporter_party_ids' => ['required', 'array', 'min:1'],
            'transporter_party_ids.*' => ['uuid'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:whatsapp,sms,email'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $broadcast = $this->quotations->requestBroadcast($company, $branch, $enquiry, $actor, $payload);
        } catch (EnquiryAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (EnquiryDomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $broadcast], 201);
        }

        return back()->with('success', 'Broadcast request queued for Communications (Phase 9).');
    }

    public function conversionPreview(Request $request, Enquiry $enquiry, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_CONVERT);
        $this->assertEnquiryRouteScope($enquiry, $company, $branch, $actor);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return response()->json([
            'data' => $this->redactConversionPreview(
                $this->conversions->preview($company, $branch, $enquiry),
                $maySeeFreight,
            ),
        ]);
    }

    public function convert(Request $request, Enquiry $enquiry, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_CONVERT);
        $this->assertEnquiryRouteScope($enquiry, $company, $branch, $actor);

        $payload = $request->validate([
            'workflow_context' => ['required', 'string', 'in:broker,own_fleet,partload'],
            'vehicle_id' => ['nullable', 'uuid'],
            'from_station_id' => ['nullable', 'uuid'],
            'to_station_id' => ['nullable', 'uuid'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'remark' => ['nullable', 'string'],
            'vehicles' => ['required', 'array', 'min:1'],
            'vehicles.*.goods' => ['required', 'array', 'min:1'],
            'vehicles.*.goods.*.goods_row_id' => ['required', 'uuid'],
            'vehicles.*.goods.*.package_count' => ['nullable', 'numeric', 'min:0'],
            'vehicles.*.goods.*.actual_weight' => ['nullable', 'numeric', 'min:0'],
            'vehicles.*.goods.*.charged_weight' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $result = $this->conversions->convert($company, $branch, $enquiry, $actor, $payload);
        } catch (EnquiryConflictException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return back()->withErrors($e->getMessage());
        } catch (EnquiryAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (EnquiryDomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'conversion_id' => $result['conversion']->id,
                    'lorry_receipt_ids' => collect($result['lorry_receipts'])->pluck('id')->all(),
                ],
            ], 201);
        }

        $lr = $result['lorry_receipts'][0] ?? null;

        return redirect()
            ->route('branch.lorry-receipts.show', [$lr])
            ->with('success', 'Enquiry converted to '.count($result['lorry_receipts']).' Unified LR(s).');
    }

    private function assertEnquiryRouteScope(Enquiry $enquiry, Company $company, Branch $branch, User $actor): void
    {
        if ($enquiry->company_id !== $company->id) {
            abort(404);
        }

        $canSeeUnassigned = $enquiry->branch_id === null
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::ENQUIRY_ASSIGN, $company, $branch);

        if ($enquiry->branch_id !== null && $enquiry->branch_id !== $branch->id && ! $canSeeUnassigned) {
            abort(404);
        }
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function branchOptions(Company $company): array
    {
        $rows = Branch::query()
            ->where('company_id', $company->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $row): array => ['id' => $row->id, 'name' => $row->name])
            ->values()
            ->all();

        return array_values($rows);
    }

    /**
     * @return list<array{id: int, name: string, email: string|null}>
     */
    private function assignableUsers(Company $company, Branch $branch): array
    {
        $membershipIds = BranchAssignment::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deactivated_at')
            ->pluck('company_membership_id');

        $userIds = CompanyMembership::query()
            ->whereIn('id', $membershipIds)
            ->whereNull('deactivated_at')
            ->pluck('user_id');

        $rows = User::query()
            ->whereIn('id', $userIds)
            ->whereNull('deactivated_at')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->values()
            ->all();

        return array_values($rows);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function transporterOptions(Company $company, Branch $branch): array
    {
        return app(PartyOptionQuery::class)
            ->forRoles($company, $branch, PartyRoleType::Transporter);
    }

    /**
     * @return list<array{id: string, registration_number: string|null}>
     */
    private function vehicleOptions(Company $company): array
    {
        $rows = Vehicle::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->orderBy('registration_number')
            ->limit(200)
            ->get(['id', 'registration_number'])
            ->map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
            ])
            ->values()
            ->all();

        return array_values($rows);
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for enquiry operation.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCreatePayload(Request $request): array
    {
        return $request->validate([
            'enquiry_date' => ['nullable', 'date'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'customer_party_id' => ['nullable', 'uuid'],
            'consignor_party_id' => ['nullable', 'uuid'],
            'consignee_party_id' => ['nullable', 'uuid'],
            'from_state_id' => ['nullable', 'uuid'],
            'from_city_id' => ['nullable', 'uuid'],
            'to_state_id' => ['nullable', 'uuid'],
            'to_city_id' => ['nullable', 'uuid'],
            'pickup_location' => ['prohibited'],
            'delivery_location' => ['prohibited'],
            'vehicle_type_id' => ['required', 'uuid'],
            'vehicle_quantity' => ['nullable', 'integer', 'min:1'],
            'vehicle_types' => ['prohibited'],
            'pickup_date' => ['nullable', 'date'],
            'expected_loading_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'goods_type_id' => ['nullable', 'uuid'],
            'weight_mt' => ['nullable', 'numeric', 'gt:0'],
            'goods' => ['sometimes', 'array', 'max:1'],
            'goods.*.goods_type_id' => ['nullable', 'uuid'],
            'goods.*.weight_mt' => ['nullable', 'numeric', 'gt:0'],
            'company_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'assigned_user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'source' => ['prohibited'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedUpdatePayload(Request $request): array
    {
        return $request->validate([
            'remark' => ['required', 'string', 'min:1'],
            'enquiry_date' => ['sometimes', 'date'],
            'party_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_party_id' => ['sometimes', 'nullable', 'uuid'],
            'consignor_party_id' => ['sometimes', 'nullable', 'uuid'],
            'consignee_party_id' => ['sometimes', 'nullable', 'uuid'],
            'from_state_id' => ['sometimes', 'nullable', 'uuid'],
            'from_city_id' => ['sometimes', 'nullable', 'uuid'],
            'to_state_id' => ['sometimes', 'nullable', 'uuid'],
            'to_city_id' => ['sometimes', 'nullable', 'uuid'],
            'pickup_location' => ['prohibited'],
            'delivery_location' => ['prohibited'],
            'vehicle_type_id' => ['sometimes', 'uuid'],
            'vehicle_quantity' => ['sometimes', 'integer', 'min:1'],
            'vehicle_types' => ['prohibited'],
            'pickup_date' => ['nullable', 'date'],
            'expected_loading_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'goods_type_id' => ['sometimes', 'nullable', 'uuid'],
            'weight_mt' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'goods' => ['sometimes', 'array', 'max:1'],
            'goods.*.goods_type_id' => ['nullable', 'uuid'],
            'goods.*.weight_mt' => ['nullable', 'numeric', 'gt:0'],
            'company_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'status' => ['prohibited'],
            'source' => ['prohibited'],
            'assigned_user_id' => ['prohibited'],
            'external_reference' => ['prohibited'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function redactConversionPreview(array $preview, bool $maySeeFreight): array
    {
        if ($maySeeFreight) {
            return $preview;
        }

        $preview['freight_per_vehicle'] = array_map(
            static fn (): mixed => null,
            is_array($preview['freight_per_vehicle'] ?? null) ? $preview['freight_per_vehicle'] : [],
        );

        if (isset($preview['reconciliation_totals']) && is_array($preview['reconciliation_totals'])) {
            $preview['reconciliation_totals']['freight_sum'] = null;
        }

        return $preview;
    }

    /**
     * @return array<string, mixed>
     */
    private function enquiryPayload(Enquiry $enquiry, bool $maySeeFreight): array
    {
        $firstGoodsRow = $enquiry->relationLoaded('goodsRows') ? $enquiry->goodsRows->first() : null;

        return [
            'id' => $enquiry->id,
            'company_id' => $enquiry->company_id,
            'branch_id' => $enquiry->branch_id,
            'enquiry_number' => $enquiry->enquiry_number,
            'source' => $enquiry->source->value,
            'status' => $enquiry->status->value,
            'enquiry_date' => $enquiry->enquiry_date?->toDateString(),
            'party_name' => $enquiry->party_name,
            'customer_party_id' => $enquiry->customer_party_id,
            'customer_name' => $enquiry->relationLoaded('customerParty') && $enquiry->customerParty
                ? $enquiry->customerParty->name
                : null,
            'consignor_party_id' => $enquiry->consignor_party_id,
            'consignor_name' => $enquiry->relationLoaded('consignorParty') && $enquiry->consignorParty
                ? $enquiry->consignorParty->name
                : null,
            'consignee_party_id' => $enquiry->consignee_party_id,
            'consignee_name' => $enquiry->relationLoaded('consigneeParty') && $enquiry->consigneeParty
                ? $enquiry->consigneeParty->name
                : null,
            'from_state_id' => $enquiry->from_state_id,
            'from_city_id' => $enquiry->from_city_id,
            'to_state_id' => $enquiry->to_state_id,
            'to_city_id' => $enquiry->to_city_id,
            'from_city_name' => $enquiry->relationLoaded('fromCity') ? $enquiry->fromCity?->name : null,
            'from_state_name' => $enquiry->relationLoaded('fromState') ? $enquiry->fromState?->name : null,
            'to_city_name' => $enquiry->relationLoaded('toCity') ? $enquiry->toCity?->name : null,
            'to_state_name' => $enquiry->relationLoaded('toState') ? $enquiry->toState?->name : null,
            'pickup_location' => $enquiry->pickup_location,
            'delivery_location' => $enquiry->delivery_location,
            'party_label' => $this->partyLabel($enquiry),
            'route_label' => $this->routeLabel($enquiry),
            'pickup_date' => $enquiry->expected_loading_date?->toDateString(),
            'goods_type_name' => $firstGoodsRow?->relationLoaded('goodsType')
                ? $firstGoodsRow->goodsType?->name
                : null,
            'weight_mt' => $firstGoodsRow?->actual_weight !== null
                ? OperationalWeight::kgToMt((string) $firstGoodsRow->actual_weight)
                : null,
            'vehicle_type_name' => $enquiry->relationLoaded('vehicleType') ? $enquiry->vehicleType?->name : null,
            'pending_master_resolution' => $enquiry->pending_master_resolution,
            'external_business_name' => $enquiry->external_business_name,
            'external_contact_person_name' => $enquiry->external_contact_person_name,
            'vehicle_type_id' => $enquiry->vehicle_type_id,
            'vehicle_quantity' => $enquiry->vehicle_quantity,
            'expected_loading_date' => $enquiry->expected_loading_date?->toDateString(),
            'instructions' => $enquiry->instructions,
            'goods' => $enquiry->relationLoaded('goodsRows')
                ? $enquiry->goodsRows->map(fn (EnquiryGoodsRow $row): array => [
                    'id' => $row->id,
                    'position' => $row->position,
                    'goods_type_id' => $row->goods_type_id,
                    'package_type_id' => $row->package_type_id,
                    'goods_description' => $row->goods_description,
                    'package_count' => $row->package_count,
                    'actual_weight' => $row->actual_weight,
                    'charged_weight' => $row->charged_weight,
                ])->values()->all()
                : [],
            'assigned_user_id' => $enquiry->assigned_user_id,
            'assigned_user_name' => $enquiry->relationLoaded('assignedUser') && $enquiry->assignedUser
                ? $enquiry->assignedUser->name
                : null,
            'status_history' => $enquiry->relationLoaded('statusHistories')
                ? $enquiry->statusHistories->map(fn (EnquiryStatusHistory $row): array => [
                    'from_status' => $row->from_status?->value,
                    'to_status' => $row->to_status->value,
                    'remark' => $row->remark,
                    'changed_at' => $row->changed_at?->toIso8601String(),
                ])->values()->all()
                : [],
            'assignment_history' => $enquiry->relationLoaded('assignmentHistories')
                ? $enquiry->assignmentHistories
                    ->sortByDesc('assigned_at')
                    ->map(fn (EnquiryAssignmentHistory $row): array => [
                        'from_branch_id' => $row->from_branch_id,
                        'to_branch_id' => $row->to_branch_id,
                        'from_assigned_user_id' => $row->from_assigned_user_id,
                        'to_assigned_user_id' => $row->to_assigned_user_id,
                        'remark' => $row->remark,
                        'assigned_at' => $row->assigned_at?->toIso8601String(),
                    ])->values()->all()
                : [],
            'follow_ups' => $enquiry->relationLoaded('followUps')
                ? $enquiry->followUps
                    ->sortByDesc('follow_up_at')
                    ->map(fn (EnquiryFollowUp $row): array => [
                        'id' => $row->id,
                        'follow_up_at' => $row->follow_up_at->toIso8601String(),
                        'remarks' => $row->remarks,
                        'completed_at' => $row->completed_at?->toIso8601String(),
                    ])->values()->all()
                : [],
            'quotations' => $enquiry->relationLoaded('quotations')
                ? $enquiry->quotations->map(fn (EnquiryQuotation $row): array => [
                    'id' => $row->id,
                    'transporter_party_id' => $row->transporter_party_id,
                    'transporter_name' => $row->relationLoaded('transporter') ? $row->transporter?->name : null,
                    'quoted_freight' => $this->freightPresentation->presentAmount($row->quoted_freight, $maySeeFreight),
                    'selection_status' => $row->selection_status->value,
                    'remarks' => $row->remarks,
                    'select_url' => route('branch.enquiries.quotations.select', [$enquiry, $row]),
                ])->values()->all()
                : [],
            'broadcast_requests' => $enquiry->relationLoaded('broadcastRequests')
                ? $enquiry->broadcastRequests
                    ->sortByDesc('requested_at')
                    ->map(fn (EnquiryQuotationBroadcastRequest $row): array => [
                        'id' => $row->id,
                        'status' => $row->status->value,
                        'channels' => $row->channels,
                        'target_count' => $row->relationLoaded('targets') ? $row->targets->count() : 0,
                        'requested_at' => $row->requested_at->toIso8601String(),
                    ])->values()->all()
                : [],
            'conversion' => $enquiry->relationLoaded('conversion') && $enquiry->conversion
                ? [
                    'id' => $enquiry->conversion->id,
                    'converted_at' => $enquiry->conversion->converted_at->toIso8601String(),
                    'lorry_receipt_ids' => $enquiry->conversion->relationLoaded('lorryReceiptLinks')
                        ? $enquiry->conversion->lorryReceiptLinks->pluck('lorry_receipt_id')->all()
                        : [],
                ]
                : null,
        ];
    }

    private function partyLabel(Enquiry $enquiry): string
    {
        if (filled($enquiry->party_name)) {
            return (string) $enquiry->party_name;
        }

        if ($enquiry->relationLoaded('customerParty') && $enquiry->customerParty) {
            return $enquiry->customerParty->short_name ?: $enquiry->customerParty->name;
        }

        if ($enquiry->relationLoaded('consignorParty') && $enquiry->consignorParty) {
            return $enquiry->consignorParty->short_name ?: $enquiry->consignorParty->name;
        }

        if ($enquiry->external_business_name) {
            return (string) $enquiry->external_business_name;
        }

        if ($enquiry->external_customer_name) {
            return (string) $enquiry->external_customer_name;
        }

        return '—';
    }

    private function routeLabel(Enquiry $enquiry): string
    {
        $from = $enquiry->pickup_location;
        $to = $enquiry->delivery_location;
        if ($from === null && $to === null) {
            return '—';
        }

        return trim((string) $from).' → '.trim((string) $to);
    }
}
