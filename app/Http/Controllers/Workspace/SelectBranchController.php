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
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sequential branch selection after company (PO-DEC-P2-REVIEW-003).
 */
class SelectBranchController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly WorkspaceNavigationService $navigation,
    ) {}

    public function __invoke(Request $request, Company $company): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->access->activateContext($user, $company);
        } catch (CompanyAccessException) {
            abort(403, 'Company access denied.');
        }

        if (! $this->access->canAccessBranchPanel($user, $company)) {
            abort(403, 'Branch Panel is not available for this company.');
        }

        $branches = $this->navigation->accessibleBranches($user, $company);
        if ($branches->isEmpty()) {
            return redirect()->route('workspace.no-access');
        }

        if ($branches->count() === 1) {
            $branch = Branch::query()->findOrFail($branches->first()['id']);
            try {
                $this->access->activateContext($user, $company, $branch);
            } catch (CompanyAccessException) {
                abort(403, 'Branch access denied.');
            }
            WorkspaceSession::sync($request, $company->id, $branch->id);

            return redirect()->route('branch.dashboard');
        }

        return Inertia::render('workspace/select-branch', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->isActive() ? 'Active' : 'Deactivated',
                'is_owner' => $this->access->isCompanyOwner($user, $company),
            ],
            'branches' => $branches,
        ]);
    }
}
