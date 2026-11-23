<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Domains\Company\WorkspaceNavigationService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchDashboardController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly WorkspaceNavigationService $navigation,
    ) {}

    public function __invoke(Request $request, Company $company, Branch $branch): Response
    {
        if ($branch->company_id !== $company->id) {
            abort(404);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $this->access->activateContext($user, $company, $branch);
        } catch (CompanyAccessException) {
            abort(403, 'Branch Panel access denied.');
        }

        // Context already established by ResolveBranchWorkspace / context POST.
        // Sync without bumping version on routine dashboard GET.
        WorkspaceSession::sync($request, $company->id, $branch->id);

        $isOwner = $this->access->isCompanyOwner($user, $company);
        $siblingBranches = $this->navigation->accessibleBranches($user, $company);

        // PO-P2-DESIGN-MODULES: hide unavailable modules — no Broker/OwnFleet/Partload product screens in Phase 2.
        $modules = [];

        return Inertia::render('branch/dashboard', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->isActive() ? 'Active' : 'Deactivated',
            ],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'status' => $branch->isActive() ? 'Active' : 'Deactivated',
            ],
            'isOwner' => $isOwner,
            'modules' => $modules,
            'siblingBranches' => $siblingBranches,
            'links' => [
                'dashboard' => route('branch.dashboard', absolute: false),
                'settings' => route('branch.settings', absolute: false),
                'owner_panel' => $isOwner
                    ? route('owner.home', absolute: false)
                    : null,
                'select_branch' => $siblingBranches->count() > 1
                    ? route('workspace.branches.select', ['company' => $company], absolute: false)
                    : null,
            ],
        ]);
    }
}
