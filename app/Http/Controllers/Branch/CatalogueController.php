<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Catalogue\OwnerCatalogueService;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OwnerCatalogueItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CatalogueController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly OwnerCatalogueService $catalogues,
    ) {}

    public function legacyIndex(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);

        return redirect()->route('branch.goods-types.index');
    }

    public function goodsTypes(Request $request, Company $company, Branch $branch): Response
    {
        return $this->renderTypeIndex($request, $company, $branch, OwnerCatalogueType::GoodsType);
    }

    public function truckTypes(Request $request, Company $company, Branch $branch): Response
    {
        return $this->renderTypeIndex($request, $company, $branch, OwnerCatalogueType::TruckType);
    }

    public function documentTypes(Request $request, Company $company, Branch $branch): Response
    {
        return $this->renderTypeIndex($request, $company, $branch, OwnerCatalogueType::DocumentType);
    }

    public function expenseCategories(Request $request, Company $company, Branch $branch): Response
    {
        return $this->renderTypeIndex($request, $company, $branch, OwnerCatalogueType::ExpenseCategory);
    }

    public function packageTypes(Request $request, Company $company, Branch $branch): Response
    {
        return $this->renderTypeIndex($request, $company, $branch, OwnerCatalogueType::PackageType);
    }

    public function storeGoodsTypes(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        return $this->storeTyped($request, $company, $branch, OwnerCatalogueType::GoodsType);
    }

    public function storeTruckTypes(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        return $this->storeTyped($request, $company, $branch, OwnerCatalogueType::TruckType);
    }

    public function storeDocumentTypes(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        return $this->storeTyped($request, $company, $branch, OwnerCatalogueType::DocumentType);
    }

    public function storeExpenseCategories(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        return $this->storeTyped($request, $company, $branch, OwnerCatalogueType::ExpenseCategory);
    }

    public function storePackageTypes(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        return $this->storeTyped($request, $company, $branch, OwnerCatalogueType::PackageType);
    }

    public function update(Request $request, OwnerCatalogueItem $catalogueItem, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertItem($company, $catalogueItem);
        $this->assertItemCapability($company, $catalogueItem);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->catalogues->update($actor, $catalogueItem, $company, $validated, $validated['remark']);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', 'Catalogue item updated.');
    }

    public function destroy(Request $request, OwnerCatalogueItem $catalogueItem, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertItem($company, $catalogueItem);
        $this->assertItemCapability($company, $catalogueItem);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->catalogues->softDelete($actor, $catalogueItem, $company, $validated['remark']);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', 'Catalogue item deleted.');
    }

    private function renderTypeIndex(Request $request, Company $company, Branch $branch, OwnerCatalogueType $type): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        try {
            $page = $this->catalogues->search(
                actor: $actor,
                company: $company,
                branch: $branch,
                type: $type,
                query: $request->string('q')->toString() ?: null,
                perPage: min(100, max(1, (int) $request->input('per_page', 25))),
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        $indexRoute = 'branch.'.$type->routeSlug().'.index';
        $storeRoute = 'branch.'.$type->routeSlug().'.store';

        return Inertia::render('branch/masters/catalogue-type/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'canManageOwnerCatalogues' => $permissions['is_owner'],
            'catalogue' => [
                'type' => $type->value,
                'title' => $type->pageTitle(),
                'description' => $type->pageDescription(),
                'uses_code' => $type->usesCode(),
            ],
            'filters' => [
                'q' => $request->string('q')->toString(),
            ],
            'items' => [
                'data' => collect($page->items())->map(fn (OwnerCatalogueItem $item): array => $this->catalogueItemPayload($type, $item))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ],
            'links' => [
                'hub' => route('branch.masters.index'),
                'index' => route($indexRoute, absolute: false),
                'store' => route($storeRoute, absolute: false),
            ],
        ]);
    }

    private function storeTyped(
        Request $request,
        Company $company,
        Branch $branch,
        OwnerCatalogueType $boundType,
    ): RedirectResponse|JsonResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $rules = [
            'catalogue_type' => ['sometimes', Rule::enum(OwnerCatalogueType::class)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ];

        if ($boundType->usesCode()) {
            $rules['code'] = ['required', 'string', 'max:64'];
        }

        $validated = $request->validate($rules);

        if (array_key_exists('catalogue_type', $validated)
            && OwnerCatalogueType::from($validated['catalogue_type']) !== $boundType) {
            throw ValidationException::withMessages([
                'catalogue_type' => 'Catalogue type does not match this master screen.',
            ]);
        }

        if ($boundType->usesCode()) {
            $validated['code'] = strtoupper(trim((string) $validated['code']));
        }

        try {
            $item = $this->catalogues->create(
                $actor,
                $company,
                $boundType,
                $validated,
                $validated['remark'] ?? 'Create catalogue item',
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $this->catalogueItemPayload($boundType, $item, forJson: true),
            ], 201);
        }

        return redirect()
            ->route('branch.'.$boundType->routeSlug().'.index')
            ->with('success', 'Catalogue item created.');
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogueItemPayload(OwnerCatalogueType $type, OwnerCatalogueItem $item, bool $forJson = false): array
    {
        $payload = [
            'id' => $item->id,
            'name' => $item->name,
            'catalogue_type' => $item->catalogue_type,
            'is_active' => (bool) $item->is_active,
            'sort_order' => $item->sort_order,
        ];

        if ($type->usesCode()) {
            $payload['code'] = $item->code;
        } elseif ($forJson) {
            $payload['code'] = null;
        }

        return $payload;
    }

    private function assertItem(Company $company, OwnerCatalogueItem $item): void
    {
        if ((int) $item->owner_user_id !== (int) $company->owner_user_id) {
            abort(404);
        }
    }

    private function assertItemCapability(Company $company, OwnerCatalogueItem $item): void
    {
        $capability = match ($item->catalogue_type) {
            OwnerCatalogueType::TruckType->value => CompanyCapabilities::OWNFLEET_TRUCK_TYPES,
            OwnerCatalogueType::TyreLayout->value => CompanyCapabilities::OWNFLEET_TYRE_LAYOUTS,
            OwnerCatalogueType::ExpenseCategory->value => CompanyCapabilities::OWNFLEET_EXPENSE_CATEGORIES,
            OwnerCatalogueType::DocumentType->value => CompanyCapabilities::MASTERS_DOCUMENT_TYPES,
            OwnerCatalogueType::PackageType->value => CompanyCapabilities::MASTERS_PACKAGE_TYPES,
            default => CompanyCapabilities::MASTERS_GOODS_TYPES,
        };

        $resolver = app(CompanyCapabilityResolver::class);
        if (! $resolver->allowsMutate($company, $capability)) {
            abort(403, $resolver->denyMessage($company, $capability));
        }
    }
}
