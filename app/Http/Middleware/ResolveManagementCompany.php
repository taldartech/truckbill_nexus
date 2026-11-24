<?php

namespace App\Http\Middleware;

use App\Domains\Company\CompanyAccessService;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the session company for Owner management company-scoped pages.
 * Global management lists must NOT use this middleware.
 */
final class ResolveManagementCompany
{
    public function __construct(
        private readonly CompanyAccessService $access,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || ! $user->isCustomer() || ! $user->isActive()) {
            abort(403, 'Management requires an active Customer identity.');
        }

        // Company-scoped management is Owner-only — deny members before soft redirects.
        if (! Company::query()->where('owner_user_id', $user->id)->whereNull('deleted_at')->exists()) {
            abort(403, 'Owner Panel management requires company ownership.');
        }

        // Do not clear BRANCH_KEY here — safe GETs / Inertia prefetch must not
        // destroy an active Branch Panel. Explicit leave/switch clears branch.

        $companyId = WorkspaceSession::companyId($request);
        if ($companyId === null) {
            return $this->missingContext($request);
        }

        $company = Company::query()->whereKey($companyId)->whereNull('deleted_at')->first();
        if ($company === null || ! $this->access->isCompanyOwner($user, $company)) {
            WorkspaceSession::clear($request);

            return $this->missingContext($request);
        }

        if (! $request->isMethodSafe() && ! WorkspaceSession::matchesSubmittedVersion($request)) {
            abort(409, 'Workspace context changed in another tab. Reload and try again.');
        }

        $route = $request->route();
        if ($route !== null) {
            $route->setParameter('company', $company);
        }

        return $next($request);
    }

    private function missingContext(Request $request): Response
    {
        if ($request->isMethodSafe() && ! $request->expectsJson()) {
            return redirect()
                ->route('owner.companies.index')
                ->with('error', 'Select a company from the list to continue.');
        }

        abort(409, 'Management company context is required.');
    }
}
