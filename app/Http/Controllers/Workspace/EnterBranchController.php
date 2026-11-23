<?php

namespace App\Http\Controllers\Workspace;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Enter branch context after server-side validation within the selected company.
 */
class EnterBranchController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
    ) {}

    public function __invoke(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        if ($branch->company_id !== $company->id) {
            abort(404);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $this->access->activateContext($user, $company, $branch);
        } catch (CompanyAccessException) {
            abort(403, 'Branch access denied.');
        }

        WorkspaceSession::put($request, $company->id, $branch->id);

        return redirect()->route('branch.dashboard');
    }
}
