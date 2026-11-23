<?php

namespace App\Http\Controllers\Workspace;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Domains\Company\WorkspaceNavigationService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Enter a company context after server-side access validation.
 * Route model binding supplies the company — never client query/body tenant IDs.
 */
class EnterCompanyController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly WorkspaceNavigationService $navigation,
    ) {}

    public function __invoke(Request $request, Company $company): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->access->activateContext($user, $company);
        } catch (CompanyAccessException) {
            abort(403, 'Company access denied.');
        }

        if ($this->access->isCompanyOwner($user, $company)) {
            $this->navigation->rememberOwnedCompany($user, $company);
            WorkspaceSession::put($request, $company->id, null);

            return redirect()->route('owner.home');
        }

        if (! $this->access->canAccessBranchPanel($user, $company)) {
            abort(403, 'Company access denied.');
        }

        $branches = $this->navigation->accessibleBranches($user, $company);
        if ($branches->isEmpty()) {
            return redirect()->route('workspace.no-access');
        }

        if ($branches->count() === 1) {
            $branchId = $branches->first()['id'];
            $branch = Branch::query()->findOrFail($branchId);
            try {
                $this->access->activateContext($user, $company, $branch);
            } catch (CompanyAccessException) {
                abort(403, 'Branch access denied.');
            }
            WorkspaceSession::put($request, $company->id, $branch->id);

            return redirect()->route('branch.dashboard');
        }

        WorkspaceSession::put($request, $company->id, null);

        return redirect()->route('workspace.branches.select', ['company' => $company]);
    }
}
