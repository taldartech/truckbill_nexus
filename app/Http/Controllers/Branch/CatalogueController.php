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
use App\Models\PaymentMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        $typeValue = $request->string('type')->toString() ?: OwnerCatalogueType::GoodsType->value;
        $type = OwnerCatalogueType::tryFrom($typeValue) ?? OwnerCatalogueType::GoodsType;

        try {
            $page = $this->catalogues->search(
                actor: $actor,
                company: $company,
                branch: $branch,
                type: $type,
                query: $request->string('q')->toString() ?: null,
                perPage: min(100, max(1, (int) $request->input('per_page', 25))),
            );
            $paymentModes = $this->catalogues->listPaymentModes($actor, $company, $branch);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return Inertia::render('branch/masters/catalogues/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'canManageOwnerCatalogues' => $permissions['is_owner'],
            'filters' => [
                'q' => $request->string('q')->toString(),
                'type' => $type->value,
            ],
            'typeOptions' => collect(OwnerCatalogueType::cases())->map(fn (OwnerCatalogueType $t): array => [
                'value' => $t->value,
                'label' => str_replace('_', ' ', ucfirst($t->value)),
            ])->values()->all(),
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
            'paymentModes' => $paymentModes->map(fn (PaymentMode $mode): array => [
                'id' => $mode->id,
                'code' => $mode->code,
                'name' => $mode->name,
                'is_active' => (bool) $mode->is_active,
            ])->values()->all(),
            'links' => [
                'hub' => route('branch.masters.index'),
                'index' => route('branch.catalogues.index'),
                'store' => route('branch.catalogues.store'),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'catalogue_type' => ['required', Rule::enum(OwnerCatalogueType::class)],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->catalogues->create(
                $actor,
                $company,
                OwnerCatalogueType::from($validated['catalogue_type']),
                $validated,
                $validated['remark'] ?? 'Create catalogue item',
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('branch.catalogues.index', [
                'company' => $company,
                'branch' => $branch,
                'type' => $validated['catalogue_type'],
            ])
            ->with('success', 'Catalogue item created.');
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

    private function assertItem(Company $company, OwnerCatalogueItem $item): void
    {
        if ((int) $item->owner_user_id !== (int) $company->owner_user_id) {
            abort(404);
        }
    }
}
