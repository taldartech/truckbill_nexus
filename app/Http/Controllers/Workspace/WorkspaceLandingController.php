<?php

namespace App\Http\Controllers\Workspace;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\WorkspaceNavigationService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Post-login / workspace entry resolver (PO-P2-DESIGN-LANDING / SWITCHER).
 */
class WorkspaceLandingController extends Controller
{
    public function __construct(
        private readonly WorkspaceNavigationService $navigation,
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isInternal()) {
            return redirect()->route('dashboard');
        }

        if (! $user->isCustomer() || ! $user->isActive()) {
            return redirect()->route('workspace.no-access');
        }

        $owned = $this->navigation->ownedCompanies($user);

        // PO-OWNER-GLOBAL-MANAGEMENT: owners enter the global Owner dashboard,
        // not a forced single-company dashboard or MRU selection.
        if ($owned->isNotEmpty()) {
            return redirect()->route('owner.home');
        }

        $accessible = $this->navigation->accessibleCompanies($user);
        if ($accessible->isEmpty()) {
            return redirect()->route('workspace.no-access');
        }

        if ($accessible->count() === 1) {
            $companyId = $accessible->first()['id'];
            $company = Company::query()->findOrFail($companyId);

            if (! $this->access->canAccessBranchPanel($user, $company)) {
                return redirect()->route('workspace.no-access');
            }

            $branches = $this->navigation->accessibleBranches($user, $company);
            if ($branches->isEmpty()) {
                return redirect()->route('workspace.no-access');
            }

            if ($branches->count() === 1) {
                $branch = Branch::query()->findOrFail($branches->first()['id']);

                // AUD-013: assignment alone is not enough — require Owner or PLATFORM_VIEW.
                if (! $this->access->canViewBranchOperationsPanel($user, $company, $branch, $this->authorizer)) {
                    return redirect()->route('workspace.no-access');
                }

                return redirect()->route('branch.dashboard', [
                    'company' => $company,
                    'branch' => $branch,
                ]);
            }

            return redirect()->route('workspace.branches.select', ['company' => $company]);
        }

        return redirect()->route('workspace.companies.select');
    }
}
