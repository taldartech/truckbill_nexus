<?php

namespace App\Http\Controllers\Branch\Concerns;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\Request;

trait AuthorizesBranchPanel
{
    protected function activateBranchPanel(Request $request, Company $company, Branch $branch, CompanyAccessService $access): User
    {
        if ($branch->company_id !== $company->id) {
            abort(404);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $access->activateContext($user, $company, $branch);
        } catch (CompanyAccessException) {
            abort(403, 'Branch Panel access denied.');
        }

        WorkspaceSession::put($request, $company->id, $branch->id);

        return $user;
    }

    /**
     * @return array{can_view: bool, can_create: bool, can_edit: bool, can_delete: bool, is_owner: bool}
     */
    protected function masterPermissions(User $actor, Company $company, Branch $branch, CompanyAccessService $access, PermissionAuthorizer $authorizer): array
    {
        $isOwner = $access->isCompanyOwner($actor, $company);

        return [
            'can_view' => $isOwner || $authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_VIEW, $company, $branch),
            'can_create' => $isOwner || $authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_CREATE, $company, $branch),
            'can_edit' => $isOwner || $authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_EDIT, $company, $branch),
            'can_delete' => $isOwner || $authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_DELETE, $company, $branch),
            'is_owner' => $isOwner,
        ];
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

    /**
     * @return array{id: string, name: string, status: string}
     */
    protected function branchPayload(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'status' => $branch->isActive() ? 'Active' : 'Deactivated',
        ];
    }
}
