<?php

namespace App\Domains\Auth;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Domains\Company\WorkspaceNavigationService;
use App\Domains\Rbac\InternalAuthorization;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single authoritative post-login and portal-landing resolver (PO auth routing fix).
 *
 * Enforces cross-portal isolation, clears incompatible Customer workspace context
 * on Internal Team login, preserves legitimate authorized intended URLs, and
 * removes duplicate role-based redirect logic across Fortify, /workspace, and /login.
 */
final class PostLoginLandingResolver
{
    /** @var list<string> */
    private const CUSTOMER_PATH_PREFIXES = [
        '/workspace',
        '/management',
        '/operations',
        '/parties',
        '/vehicles',
        '/lorry-receipts',
        '/enquiries',
        '/quotations',
        '/broker',
        '/ownfleet',
        '/partload',
        '/accounting',
        '/bank-cash',
        '/stations',
        '/goods-types',
        '/truck-types',
        '/reports',
    ];

    public function __construct(
        private readonly WorkspaceNavigationService $navigation,
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly InternalAuthorization $internalAuth,
    ) {}

    public function resolve(Request $request, User $user, bool $consumeIntended = true): string
    {
        $intended = $consumeIntended ? $request->session()->pull('url.intended') : null;

        if (! $user->isActive()) {
            WorkspaceSession::clear($request);

            return route('workspace.no-access');
        }

        if ($user->isInternal()) {
            // Clear any incompatible Customer company/branch context immediately.
            if (WorkspaceSession::companyId($request) !== null || WorkspaceSession::branchId($request) !== null) {
                WorkspaceSession::clear($request);
            }

            if (is_string($intended) && $this->isAuthorizedInternalIntended($request, $user, $intended)) {
                return $intended;
            }

            return route('dashboard');
        }

        if (! $user->isCustomer()) {
            WorkspaceSession::clear($request);

            return route('workspace.no-access');
        }

        $this->pruneIncompatibleCustomerSession($request, $user);

        $owned = $this->navigation->ownedCompanies($user);
        if ($owned->isNotEmpty()) {
            if (is_string($intended) && $this->isAuthorizedOwnerIntended($request, $user, $intended)) {
                return $intended;
            }

            return route('owner.home');
        }

        $defaultLanding = $this->resolveCustomerMemberLanding($request, $user);

        if (is_string($intended) && $this->isAuthorizedMemberIntended($request, $user, $intended)) {
            return $intended;
        }

        return $defaultLanding;
    }

    private function pruneIncompatibleCustomerSession(Request $request, User $user): void
    {
        $companyId = WorkspaceSession::companyId($request);
        if ($companyId === null) {
            return;
        }

        $company = Company::query()->whereKey($companyId)->whereNull('deleted_at')->first();
        if ($company === null) {
            WorkspaceSession::clear($request);

            return;
        }

        $branchId = WorkspaceSession::branchId($request);
        if ($branchId !== null) {
            $branch = Branch::query()->whereKey($branchId)->whereNull('deleted_at')->first();
            if ($branch === null || $branch->company_id !== $company->id) {
                WorkspaceSession::clear($request);

                return;
            }

            try {
                $this->access->activateContext($user, $company, $branch);

                return;
            } catch (CompanyAccessException) {
                WorkspaceSession::clear($request);

                return;
            }
        }

        if (! $this->access->canAccessOwnerPanel($user, $company)) {
            WorkspaceSession::clear($request);
        }
    }

    private function resolveCustomerMemberLanding(Request $request, User $user): string
    {
        $accessible = $this->navigation->accessibleCompanies($user);
        if ($accessible->isEmpty()) {
            WorkspaceSession::clear($request);

            return route('workspace.no-access');
        }

        if ($accessible->count() === 1) {
            $company = Company::query()->findOrFail($accessible->first()['id']);

            if (! $this->access->canAccessBranchPanel($user, $company)) {
                return route('workspace.no-access');
            }

            $branches = $this->navigation->accessibleBranches($user, $company);
            if ($branches->isEmpty()) {
                return route('workspace.no-access');
            }

            if ($branches->count() === 1) {
                $branch = Branch::query()->findOrFail($branches->first()['id']);

                if (! $this->access->canViewBranchOperationsPanel($user, $company, $branch, $this->authorizer)) {
                    return route('workspace.no-access');
                }

                try {
                    $this->access->activateContext($user, $company, $branch);
                } catch (CompanyAccessException) {
                    return route('workspace.no-access');
                }

                WorkspaceSession::sync($request, $company->id, $branch->id);

                return route('branch.dashboard');
            }

            return route('workspace.branches.select', ['company' => $company]);
        }

        return route('workspace.companies.select');
    }

    private function isAuthorizedInternalIntended(Request $request, User $user, string $intended): bool
    {
        $path = $this->extractSafeSameOriginPath($request, $intended);
        if ($path === null || $this->isNeutralEntryPath($path)) {
            return false;
        }

        foreach (self::CUSTOMER_PATH_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        $route = $this->matchGetRoute($path);
        if ($route === null) {
            return false;
        }

        $middlewares = $route->gatherMiddleware();
        if (in_array('branch.workspace', $middlewares, true)
            || in_array('management.company', $middlewares, true)) {
            return false;
        }

        $name = (string) ($route->getName() ?? '');
        if ($name === '' || Str::startsWith($name, ['branch.', 'owner.', 'workspace.'])) {
            return false;
        }

        if (Str::startsWith($name, ['admin.billing-profile', 'admin.billing-profiles', 'admin.subscription-invoices'])) {
            return $this->internalAuth->allowsAny($user, [
                'internal.subscription_billing.view',
                'internal.subscription_billing.manage',
            ]);
        }

        if (Str::startsWith($name, 'admin.roles.')) {
            return $this->internalAuth->allowsAny($user, ['internal.settings.view', 'internal.settings.manage']);
        }

        if (Str::startsWith($name, 'admin.internal-users.')) {
            return $this->internalAuth->allowsAny($user, ['internal.users.view', 'internal.users.manage']);
        }

        if (Str::startsWith($name, 'admin.companies.')) {
            return $this->internalAuth->allowsAny($user, ['internal.onboarding.view', 'internal.onboarding.manage']);
        }

        if (Str::startsWith($name, 'admin.gst-rates')) {
            return $this->internalAuth->allowsAny($user, ['internal.gst.view', 'internal.gst.manage']);
        }

        if (Str::startsWith($name, 'admin.tds-rules')) {
            return $this->internalAuth->allowsAny($user, ['internal.tds.view', 'internal.tds.manage']);
        }

        return $name === 'dashboard'
            || Str::startsWith($name, ['admin.', 'profile.', 'user-password.', 'two-factor.', 'appearance.']);
    }

    private function isAuthorizedOwnerIntended(Request $request, User $user, string $intended): bool
    {
        $path = $this->extractSafeSameOriginPath($request, $intended);
        if ($path === null || $this->isNeutralEntryPath($path)) {
            return false;
        }

        $route = $this->matchGetRoute($path);
        if ($route === null) {
            return false;
        }

        $name = (string) ($route->getName() ?? '');
        if ($name === '' || $name === 'dashboard' || Str::startsWith($name, 'admin.')) {
            return false;
        }

        if (in_array('branch.workspace', $route->gatherMiddleware(), true) || Str::startsWith($name, 'branch.')) {
            return WorkspaceSession::companyId($request) !== null && WorkspaceSession::branchId($request) !== null;
        }

        return Str::startsWith($name, ['owner.', 'workspace.', 'profile.', 'user-password.', 'two-factor.', 'appearance.']);
    }

    private function isAuthorizedMemberIntended(Request $request, User $user, string $intended): bool
    {
        $path = $this->extractSafeSameOriginPath($request, $intended);
        if ($path === null || $this->isNeutralEntryPath($path)) {
            return false;
        }

        $route = $this->matchGetRoute($path);
        if ($route === null) {
            return false;
        }

        $name = (string) ($route->getName() ?? '');
        if ($name === '' || $name === 'dashboard' || Str::startsWith($name, ['admin.', 'owner.'])) {
            return false;
        }

        if (in_array('branch.workspace', $route->gatherMiddleware(), true) || Str::startsWith($name, 'branch.')) {
            return WorkspaceSession::companyId($request) !== null && WorkspaceSession::branchId($request) !== null;
        }

        return Str::startsWith($name, ['workspace.', 'profile.', 'user-password.', 'two-factor.', 'appearance.']);
    }

    private function extractSafeSameOriginPath(Request $request, string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '/')) {
            if (str_starts_with($trimmed, '//')) {
                return null;
            }

            return parse_url($trimmed, PHP_URL_PATH) ?: '/';
        }

        $parsed = parse_url($trimmed);
        if (! is_array($parsed) || ! isset($parsed['host'])) {
            return null;
        }

        if (strcasecmp((string) $parsed['host'], $request->getHost()) !== 0) {
            return null;
        }

        return (string) ($parsed['path'] ?? '/');
    }

    private function isNeutralEntryPath(string $path): bool
    {
        return in_array($path, ['/', '/login', '/logout', '/workspace', '/up'], true);
    }

    private function matchGetRoute(string $path): ?RoutingRoute
    {
        try {
            return app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return null;
        }
    }
}
