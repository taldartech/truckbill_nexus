<?php

namespace App\Http\Controllers\Workspace;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\SafeInternalRedirect;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Explicit, CSRF-protected workspace context switches (PO session routing).
 * Company/branch come from route model binding — never request body tenant IDs
 * (RejectClientTenantIdentifiers).
 */
class WorkspaceContextController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
    ) {}

    public function company(Request $request, Company $company): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'redirect' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $this->access->isCompanyOwner($user, $company)) {
            abort(403, 'Only the designated company owner may open management company context.');
        }

        WorkspaceSession::put($request, $company->id, null);

        return redirect()->to(SafeInternalRedirect::normalize(
            $validated['redirect'] ?? null,
            SafeInternalRedirect::route('owner.companies.index'),
        ));
    }

    public function branch(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'redirect' => ['nullable', 'string', 'max:500'],
        ]);

        if ($branch->company_id !== $company->id) {
            abort(404);
        }

        try {
            $this->access->activateContext($user, $company, $branch);
        } catch (CompanyAccessException) {
            abort(403, 'Branch access denied.');
        }

        WorkspaceSession::put($request, $company->id, $branch->id);

        return redirect()->to(SafeInternalRedirect::normalize(
            $validated['redirect'] ?? null,
            SafeInternalRedirect::route('branch.dashboard'),
        ));
    }

    public function clear(Request $request): RedirectResponse
    {
        WorkspaceSession::clear($request);

        return redirect()->route('workspace.landing');
    }

    /**
     * Explicit Return to Owner Panel — clears branch context only.
     */
    public function leaveBranch(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $companyId = WorkspaceSession::companyId($request);
        WorkspaceSession::clearBranch($request);

        if ($companyId !== null) {
            $company = Company::query()->whereKey($companyId)->whereNull('deleted_at')->first();
            if ($company !== null && $this->access->isCompanyOwner($user, $company)) {
                return redirect()->route('owner.home');
            }
        }

        return redirect()->route('workspace.landing');
    }
}
