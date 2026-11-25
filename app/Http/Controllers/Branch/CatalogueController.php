<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Catalogue\OwnerCatalogueService;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OwnerCatalogueItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

    public function tyreLayouts(Request $request, Company $company, Branch $branch): Response
    {
        return $this->renderTypeIndex($request, $company, $branch, OwnerCatalogueType::TyreLayout);
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

    public function storeTyreLayouts(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        return $this->storeTyped($request, $company, $branch, OwnerCatalogueType::TyreLayout);
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
            ],
            'filters' => [
                'q' => $request->string('q')->toString(),
            ],
            'items' => [
                'data' => collect($page->items())->map(fn (OwnerCatalogueItem $item): array => [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'catalogue_type' => $item->catalogue_type,
                    'is_active' => (bool) $item->is_active,
                    'sort_order' => $item->sort_order,
                ])->values()->all(),
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

        $validated = $request->validate([
            'catalogue_type' => ['sometimes', Rule::enum(OwnerCatalogueType::class)],
            'code' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('catalogue_type', $validated)
            && OwnerCatalogueType::from($validated['catalogue_type']) !== $boundType) {
            throw ValidationException::withMessages([
                'catalogue_type' => 'Catalogue type does not match this master screen.',
            ]);
        }

        $code = strtoupper(trim((string) ($validated['code'] ?? '')));
        if ($code === '') {
            $code = $this->deriveCatalogueCode((string) $validated['name']);
        }
        $validated['code'] = $code;

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
                'data' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->code,
                ],
            ], 201);
        }

        return redirect()
            ->route('branch.'.$boundType->routeSlug().'.index')
            ->with('success', 'Catalogue item created.');
    }

    private function deriveCatalogueCode(string $name): string
    {
        $slug = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii(trim($name))));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'ITEM';
        }

        return Str::limit($slug, 64, '');
    }

    private function assertItem(Company $company, OwnerCatalogueItem $item): void
    {
        if ((int) $item->owner_user_id !== (int) $company->owner_user_id) {
            abort(404);
        }
    }
}
