<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\OwnerGlobalPanelService;
use App\Http\Controllers\Controller;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cross-company membership listing for Customer Owners.
 */
class OwnerGlobalUsersController extends Controller
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
        $companyId = is_string($companyId) ? $companyId : null;

        $paginator = $this->panel->paginateOwnedMemberships(
            $user,
            $companyId,
            $search,
            $status,
        );

        return Inertia::render('owner/users/all', [
            'filters' => [
                'q' => $search,
                'status' => $status,
                'company' => $companyId ?? '',
            ],
            'companyOptions' => $this->panel->ownedCompanyFilterOptions($user),
            'memberships' => [
                'data' => $paginator->getCollection()->map(
                    fn (CompanyMembership $membership): array => $this->panel->presentMembershipRow($membership),
                )->values(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'links' => [
                'index' => route('owner.users.all'),
                'companies' => route('owner.companies.index'),
            ],
        ]);
    }
}
