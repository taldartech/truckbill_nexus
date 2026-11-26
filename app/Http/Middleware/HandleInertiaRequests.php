<?php

namespace App\Http\Middleware;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\WorkspaceNavigationService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
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
            // Product branding is fixed — do not let APP_NAME="Laravel" leak into UI.
            'name' => 'TruckBill',
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
                'can_manage_geography' => $user !== null && $user->isInternal() && $user->isActive(),
                // Quick-add on forms (Owners + Internal). Distinct from master administration.
                'can_quick_create_geography' => $user !== null && $user->isActive(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'toast' => $request->session()->get('toast'),
                'tds_preview' => $request->session()->get('tds_preview'),
                'onboarding_accept_url' => $request->session()->get('onboarding_accept_url'),
            ],
            'tds_preview' => $request->session()->get('tds_preview'),
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

        // Owner chrome hides branch in shared props only — never mutate session here.
        // Prefetch / background GETs to owner.* must not destroy an active Branch Panel.
        if ($request->routeIs('owner.*')) {
            $activeBranchId = null;
        } else {
            $activeBranchId = $routeBranch instanceof Branch
                ? $routeBranch->id
                : WorkspaceSession::branchId($request);
        }

        $activeCompany = $activeCompanyId === null
            ? null
            : Company::query()->whereKey($activeCompanyId)->whereNull('deleted_at')->first();
        $activeBranch = $activeBranchId === null
            ? null
            : Branch::query()->whereKey($activeBranchId)->whereNull('deleted_at')->first();

        $capabilityPayload = null;
        $branchNavigation = null;

        if ($activeCompany !== null) {
            $capabilityResolver = app(CompanyCapabilityResolver::class);
            $resolved = $capabilityResolver->resolve($activeCompany);

            $capabilityPayload = [
                'lifecycle_status' => $resolved['lifecycle_status'],
                'mutations_allowed' => $resolved['mutations_allowed'],
                'is_read_only_grace_or_restricted' => $resolved['is_read_only_grace_or_restricted'],
                'active_modules' => $resolved['active_modules'],
                'historical_modules' => $resolved['historical_modules'],
                'allowed_vehicle_ownerships' => array_map(
                    fn (VehicleOwnership $ownership): string => $ownership->value,
                    $capabilityResolver->allowedVehicleOwnerships($activeCompany),
                ),
                'allowed_party_roles' => array_map(
                    fn (PartyRoleType $role): string => $role->value,
                    $capabilityResolver->allowedPartyRoles($activeCompany),
                ),
                'ownfleet_drivers_enabled' => $capabilityResolver->allowsRead(
                    $activeCompany,
                    CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS,
                ),
            ];

            if ($activeBranch !== null && $activeBranch->company_id === $activeCompany->id) {
                $branchNavigation = $capabilityResolver->navigationForUser(
                    $user,
                    $activeCompany,
                    $activeBranch,
                    app(PermissionAuthorizer::class),
                    app(CompanyAccessService::class),
                );
            }
        }

        return [
            'companies' => $navigation->accessibleCompanies($user)->values(),
            'active_company_id' => $activeCompanyId,
            'active_branch_id' => $activeBranchId,
            'active_company_name' => $activeCompany?->name,
            'active_company_status' => $activeCompany === null
                ? null
                : ($activeCompany->isActive() ? 'Active' : 'Deactivated'),
            'active_branch_name' => $activeBranch?->name,
            'active_branch_status' => $activeBranch === null
                ? null
                : ($activeBranch->isActive() ? 'Active' : 'Deactivated'),
            'context_version' => WorkspaceSession::version($request),
            'can_create_company' => true,
            'create_company_url' => route('owner.companies.create'),
            'capabilities' => $capabilityPayload,
            'navigation' => $branchNavigation,
        ];
    }
}
