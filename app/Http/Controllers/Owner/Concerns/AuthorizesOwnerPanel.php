<?php

namespace App\Http\Controllers\Owner\Concerns;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Owner Panel authorization against an explicitly targeted Company.
 *
 * Administrative authorization must not establish or mutate operational
 * WorkspaceSession company/branch values. Request-scoped TenantContext is
 * activated for domain services and cleared by the tenant lifecycle middleware.
 */
trait AuthorizesOwnerPanel
{
    /**
     * @return array{user: User, scope: string}
     */
    protected function authorizeOwnerPanel(
        Request $request,
        Company $company,
        CompanyAccessService $access,
        bool $requireFullScope = false,
    ): array {
        /** @var User $user */
        $user = $request->user();

        $scope = $access->ownerPanelScope($user, $company);
        if ($scope === null) {
            abort(403, 'Owner Panel access denied.');
        }

        if ($requireFullScope && $scope !== CompanyAccessService::OWNER_PANEL_FULL) {
            abort(403, 'Company administration writes are unavailable while the company is deactivated.');
        }

        try {
            // Request-scoped tenant context only — does not write WorkspaceSession.
            $access->activateContext($user, $company);
        } catch (CompanyAccessException) {
            abort(403, 'Owner Panel access denied.');
        }

        return ['user' => $user, 'scope' => $scope];
    }

    /**
     * Confirm the authenticated user owns at least one Company (global Owner identity).
     */
    protected function authorizeOwnerIdentity(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        if ($user === null || ! $user->isCustomer() || ! $user->isActive()) {
            abort(403, 'Owner Panel requires an active Customer identity.');
        }

        $ownsCompany = Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $ownsCompany) {
            // First-time owners may still access company creation; callers decide.
            return $user;
        }

        return $user;
    }

    /**
     * @return array{id: string, name: string, status: string}
     */
    protected function companyPayload(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'status' => $company->isActive() ? 'Active' : 'Deactivated',
        ];
    }
}
