<?php

namespace App\Http\Middleware;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve Company + Branch from the validated session and inject route parameters
 * so Branch Panel controllers keep type-hinted models without UUIDs in the URL.
 */
final class ResolveBranchWorkspace
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
            abort(403, 'Branch Panel requires an active Customer identity.');
        }

        $companyId = WorkspaceSession::companyId($request);
        $branchId = WorkspaceSession::branchId($request);

        if ($companyId === null || $branchId === null) {
            return $this->missingContext($request);
        }

        $company = Company::query()->whereKey($companyId)->whereNull('deleted_at')->first();
        $branch = Branch::query()->whereKey($branchId)->whereNull('deleted_at')->first();

        if ($company === null || $branch === null || $branch->company_id !== $company->id) {
            WorkspaceSession::clear($request);

            return $this->missingContext($request);
        }

        try {
            $this->access->activateContext($user, $company, $branch);
        } catch (CompanyAccessException) {
            WorkspaceSession::clear($request);
            abort(403, 'Branch Panel access denied.');
        }

        if (! $request->isMethodSafe() && ! WorkspaceSession::matchesSubmittedVersion($request)) {
            abort(409, 'Workspace context changed in another tab. Reload and try again.');
        }

        $route = $request->route();
        if ($route !== null) {
            $route->setParameter('company', $company);
            $route->setParameter('branch', $branch);
        }

        return $next($request);
    }

    private function missingContext(Request $request): Response
    {
        if ($request->isMethodSafe() && ! $request->expectsJson()) {
            return redirect()
                ->route('workspace.landing')
                ->with('error', 'Select a company and branch to continue.');
        }

        abort(409, 'Branch workspace context is required.');
    }
}
