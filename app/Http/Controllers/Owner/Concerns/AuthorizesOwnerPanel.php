<?php

namespace App\Http\Controllers\Owner\Concerns;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\Request;

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
            $access->activateContext($user, $company);
        } catch (CompanyAccessException) {
            abort(403, 'Owner Panel access denied.');
        }

        WorkspaceSession::put($request, $company->id);

        return ['user' => $user, 'scope' => $scope];
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
