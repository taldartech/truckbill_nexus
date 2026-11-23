<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Enquiry\EnquiryService;
use App\Domains\Enquiry\Exceptions\EnquiryAuthorizationException;
use App\Domains\Enquiry\Exceptions\EnquiryDomainException;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Enquiry;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\User;
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
                'data' => $this->enquiryPayload($enquiry->load('goodsRows', 'statusHistories')),
            ], 201);
        }

        return redirect()
            ->route('branch.enquiries.show', [$enquiry])
            ->with('success', 'Enquiry created.');
    }

    public function update(Request $request, Enquiry $enquiry, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_EDIT);

        if ($enquiry->company_id !== $company->id || $enquiry->branch_id !== $branch->id) {
            abort(404);
        }

        $remark = trim((string) $request->input('remark', ''));
        $payload = $this->validatedUpdatePayload($request);

        try {
            $updated = $this->enquiries->updateManual($company, $branch, $enquiry, $actor, $payload, $remark);
        } catch (ValidationException $e) {
            throw $e;
        } catch (EnquiryAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (EnquiryDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->enquiryPayload($updated->load('goodsRows')),
        ]);
    }

    /**
     * Branch-scoped list. Unassigned company queue requires enquiry.assign (Batch C visibility gate documented).
     */
    public function index(Request $request, Company $company, Branch $branch): JsonResponse|Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::ENQUIRY_VIEW);

        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $includeUnassigned = $request->boolean('unassigned')
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::ENQUIRY_ASSIGN, $company, $branch);

        $query = Enquiry::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->with(['goodsRows' => fn ($q) => $q->orderBy('position')])
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
            'data' => collect($page->items())->map(fn (Enquiry $enquiry): array => $this->enquiryPayload($enquiry))->values()->all(),
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

        $parties = Party::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);

        $vehicleTypes = OwnerCatalogueItem::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', OwnerCatalogueType::TruckType->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);

        return Inertia::render('branch/enquiries/create', [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'parties' => $parties->map(fn ($party): array => [
                'id' => $party->id,
                'name' => $party->name,
            ])->values()->all(),
            'vehicle_types' => $vehicleTypes->map(fn ($type): array => [
                'id' => $type->id,
                'name' => $type->name,
            ])->values()->all(),
            'urls' => [
                'index' => route('branch.enquiries.index'),
                'store' => route('branch.enquiries.store'),
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

        $data = $this->enquiryPayload($enquiry->load(['goodsRows', 'statusHistories']));

        if ($request->expectsJson()) {
            return response()->json(['data' => $data]);
        }

        return Inertia::render('branch/enquiries/show', [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'enquiry' => $data,
            'urls' => [
                'index' => route('branch.enquiries.index'),
            ],
        ]);
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
            'customer_party_id' => ['required', 'uuid'],
            'pickup_location' => ['required', 'string', 'max:255'],
            'delivery_location' => ['required', 'string', 'max:255'],
            'vehicle_type_id' => ['required', 'uuid'],
            'vehicle_quantity' => ['required', 'integer', 'min:1'],
            'vehicle_types' => ['prohibited'],
            'expected_loading_date' => ['nullable', 'date'],
            'instructions' => ['nullable', 'string'],
            'goods' => ['required', 'array', 'min:1', 'max:50'],
            'goods.*.goods_type_id' => ['nullable', 'uuid'],
            'goods.*.package_type_id' => ['nullable', 'uuid'],
            'goods.*.goods_description' => ['nullable', 'string', 'max:255'],
            'goods.*.package_count' => ['nullable', 'numeric', 'min:0'],
            'goods.*.actual_weight' => ['nullable', 'numeric', 'min:0'],
            'goods.*.charged_weight' => ['nullable', 'numeric', 'min:0'],
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
            'customer_party_id' => ['sometimes', 'uuid'],
            'pickup_location' => ['sometimes', 'string', 'max:255'],
            'delivery_location' => ['sometimes', 'string', 'max:255'],
            'vehicle_type_id' => ['sometimes', 'uuid'],
            'vehicle_quantity' => ['sometimes', 'integer', 'min:1'],
            'vehicle_types' => ['prohibited'],
            'expected_loading_date' => ['nullable', 'date'],
            'instructions' => ['nullable', 'string'],
            'goods' => ['sometimes', 'array', 'min:1', 'max:50'],
            'goods.*.goods_type_id' => ['nullable', 'uuid'],
            'goods.*.package_type_id' => ['nullable', 'uuid'],
            'goods.*.goods_description' => ['nullable', 'string', 'max:255'],
            'goods.*.package_count' => ['nullable', 'numeric', 'min:0'],
            'goods.*.actual_weight' => ['nullable', 'numeric', 'min:0'],
            'goods.*.charged_weight' => ['nullable', 'numeric', 'min:0'],
            'company_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'status' => ['prohibited'],
            'source' => ['prohibited'],
            'assigned_user_id' => ['prohibited'],
            'external_reference' => ['prohibited'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function enquiryPayload(Enquiry $enquiry): array
    {
        return [
            'id' => $enquiry->id,
            'company_id' => $enquiry->company_id,
            'branch_id' => $enquiry->branch_id,
            'enquiry_number' => $enquiry->enquiry_number,
            'source' => $enquiry->source->value,
            'status' => $enquiry->status->value,
            'enquiry_date' => $enquiry->enquiry_date?->toDateString(),
            'customer_party_id' => $enquiry->customer_party_id,
            'pickup_location' => $enquiry->pickup_location,
            'delivery_location' => $enquiry->delivery_location,
            'vehicle_type_id' => $enquiry->vehicle_type_id,
            'vehicle_quantity' => $enquiry->vehicle_quantity,
            'expected_loading_date' => $enquiry->expected_loading_date?->toDateString(),
            'instructions' => $enquiry->instructions,
            'goods' => $enquiry->relationLoaded('goodsRows')
                ? $enquiry->goodsRows->map(fn ($row): array => [
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
            'status_history' => $enquiry->relationLoaded('statusHistories')
                ? $enquiry->statusHistories->map(fn ($row): array => [
                    'from_status' => $row->from_status?->value,
                    'to_status' => $row->to_status->value,
                    'remark' => $row->remark,
                    'changed_at' => $row->changed_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }
}
