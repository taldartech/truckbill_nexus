<?php

namespace App\Http\Middleware;

use App\Domains\Company\WorkspaceNavigationService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'identity_type' => $user->identity_type?->value,
                    'is_super_admin' => $user->isSuperAdmin(),
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'workspace' => $this->workspaceShare($request, $user),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function workspaceShare(Request $request, ?User $user): ?array
    {
        if ($user === null || ! $user->isCustomer() || ! $user->isActive()) {
            return null;
        }

        $navigation = app(WorkspaceNavigationService::class);

        $routeCompany = $request->route('company');
        $routeBranch = $request->route('branch');

        $activeCompanyId = $routeCompany instanceof Company
            ? $routeCompany->id
            : WorkspaceSession::companyId($request);

        // Owner Panel is company-scoped. Clear any prior Branch Panel session branch
        // before sharing props so Owner chrome is not overridden by a stale branch.
        if ($request->routeIs('owner.*')) {
            $request->session()->forget(WorkspaceSession::BRANCH_KEY);
            $activeBranchId = null;
        } else {
            $activeBranchId = $routeBranch instanceof Branch
                ? $routeBranch->id
                : WorkspaceSession::branchId($request);
        }

        return [
            'companies' => $navigation->accessibleCompanies($user)->values(),
            'active_company_id' => $activeCompanyId,
            'active_branch_id' => $activeBranchId,
        ];
    }
}
