<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\OwnerGlobalPanelService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Global Owner branch list across all owned companies (PO-OWNER-GLOBAL-MANAGEMENT).
 */
class OwnerGlobalBranchesController extends Controller
{
    public function __construct(
        private readonly OwnerGlobalPanelService $panel,
    ) {}

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->panel->assertOwnsAtLeastOneCompany($user);

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'active');
        $companyId = $request->query('company');
        $companyId = is_string($companyId) && $companyId !== '' ? $companyId : null;
        $sort = (string) $request->query('sort', 'name');
        $direction = (string) $request->query('direction', 'asc');

        $paginator = $this->panel->paginateOwnedBranches(
            $user,
            $companyId,
            $search,
            $status,
            $sort,
            $direction,
        );

        return Inertia::render('owner/branches/all', [
            'filters' => [
                'q' => $search,
                'status' => $status,
                'company' => $companyId,
                'sort' => $sort,
                'direction' => $direction,
            ],
            'companyOptions' => $this->panel->ownedCompanyFilterOptions($user),
            'branches' => [
                'data' => $paginator->getCollection()->map(
                    fn (Branch $branch): array => $this->panel->presentBranchRow($branch, $user),
                )->values(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'links' => [
                'index' => route('owner.branches.all'),
                'companies' => route('owner.companies.index'),
                'create_company' => route('owner.companies.create'),
            ],
        ]);
    }
}
