<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\TyreLayout\TyreLayoutService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OwnerTyreLayout;
use App\Models\OwnerTyreLayoutAxle;
use App\Models\OwnerTyreLayoutPosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TyreLayoutController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly TyreLayoutService $layouts,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        try {
            $page = $this->layouts->search(
                actor: $actor,
                company: $company,
                branch: $branch,
                query: $request->string('q')->toString() ?: null,
                perPage: min(100, max(1, (int) $request->input('per_page', 25))),
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return Inertia::render('branch/masters/tyre-layouts/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'canManageOwnerCatalogues' => $permissions['is_owner'],
            'filters' => [
                'q' => $request->string('q')->toString(),
            ],
            'items' => [
                'data' => collect($page->items())->map(fn (OwnerTyreLayout $item): array => $this->layoutPayload($item))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ],
            'links' => [
                'hub' => route('branch.masters.index'),
                'index' => route('branch.tyre-layouts.index', absolute: false),
                'store' => route('branch.tyre-layouts.store', absolute: false),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'spare_tyre_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'axles' => ['nullable', 'array', 'max:16'],
            'axles.*.sequence' => ['required_with:axles', 'integer', 'min:1', 'max:16'],
            'axles.*.configuration' => ['required_with:axles', 'string', 'in:single,dual'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $layout = $this->layouts->create(
                $actor,
                $company,
                $validated,
                $validated['remark'] ?? 'Create tyre layout',
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => $layout->id,
                    'name' => $layout->name,
                ],
            ], 201);
        }

        return redirect()
            ->route('branch.tyre-layouts.index')
            ->with('success', 'Tyre layout created.');
    }

    public function update(Request $request, OwnerTyreLayout $tyreLayout, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertLayout($company, $tyreLayout);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'spare_tyre_count' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'axles' => ['sometimes', 'array', 'max:16'],
            'axles.*.sequence' => ['required_with:axles', 'integer', 'min:1', 'max:16'],
            'axles.*.configuration' => ['required_with:axles', 'string', 'in:single,dual'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->layouts->update($actor, $tyreLayout, $company, $validated, $validated['remark']);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', 'Tyre layout updated.');
    }

    public function destroy(Request $request, OwnerTyreLayout $tyreLayout, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertLayout($company, $tyreLayout);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->layouts->softDelete($actor, $tyreLayout, $company, $validated['remark']);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', 'Tyre layout deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutPayload(OwnerTyreLayout $layout): array
    {
        return [
            'id' => $layout->id,
            'name' => $layout->name,
            'spare_tyre_count' => $layout->spare_tyre_count,
            'drive_tyre_count' => $layout->drive_tyre_count,
            'total_tyre_count' => $layout->drive_tyre_count + $layout->spare_tyre_count,
            'needs_axle_completion' => $layout->needs_axle_completion,
            'is_active' => (bool) $layout->is_active,
            'sort_order' => $layout->sort_order,
            'axles' => $layout->axles->map(function (OwnerTyreLayoutAxle $axle): array {
                $configuration = $axle->configuration;

                return [
                    'sequence' => $axle->sequence,
                    'configuration' => $configuration->value,
                    'label' => $configuration->label(),
                ];
            })->values()->all(),
            'positions' => $layout->positions
                ->where('is_active', true)
                ->values()
                ->map(fn (OwnerTyreLayoutPosition $position): array => [
                    'id' => $position->id,
                    'position_key' => $position->position_key,
                    'label' => $position->label,
                    'axle_sequence' => $position->axle_sequence,
                    'side' => $position->side,
                    'ring' => $position->ring,
                ])->all(),
        ];
    }

    private function assertLayout(Company $company, OwnerTyreLayout $layout): void
    {
        if ((int) $layout->owner_user_id !== (int) $company->owner_user_id) {
            abort(404);
        }
    }
}
