<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Domains\Company\WorkspaceNavigationService;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OwnerDashboardController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly WorkspaceNavigationService $navigation,
    ) {}

    public function __invoke(Request $request, Company $company): Response
    {
        /** @var User $user */
        $user = $request->user();

        $scope = $this->access->ownerPanelScope($user, $company);
        if ($scope === null) {
            abort(403, 'Owner Panel access denied.');
        }

        try {
            $this->access->activateContext($user, $company);
        } catch (CompanyAccessException) {
            abort(403, 'Owner Panel access denied.');
        }

        $this->navigation->rememberOwnedCompany($user, $company);
        WorkspaceSession::put($request, $company->id);

        $branches = $company->isActive()
            ? $this->navigation->accessibleBranches($user, $company)
            : collect();

        return Inertia::render('owner/dashboard', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->isActive() ? 'Active' : 'Deactivated',
            ],
            'ownerPanelScope' => $scope,
            'isDeactivated' => $company->isDeactivated(),
            'branches' => $branches,
            'subscription' => [
                'presentation' => 'unavailable',
                'message' => 'Subscription commercial data is not provisioned in Phase 2. Status labels only reflect company Active/Deactivated. Exact trial/grace/restricted entitlements remain Pending (AUD-CON-006).',
                'company_status' => $company->isActive() ? 'Active' : 'Deactivated',
            ],
            'links' => [
                'subscription' => route('owner.subscription', ['company' => $company]),
                'branches' => route('owner.branches', ['company' => $company]),
                'users' => route('owner.users', ['company' => $company]),
                'settings' => route('owner.settings', ['company' => $company]),
                'audit' => route('owner.audit', ['company' => $company]),
                'select_branch' => $company->isActive()
                    ? route('workspace.branches.select', ['company' => $company])
                    : null,
                'create_company' => ($user->isCustomer() && $user->isActive())
                    ? route('owner.companies.create')
                    : null,
            ],
        ]);
    }
}
