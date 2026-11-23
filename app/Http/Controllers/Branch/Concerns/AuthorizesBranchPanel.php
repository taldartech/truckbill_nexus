<?php

namespace App\Http\Controllers\Branch\Concerns;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Domains\Company\MasterDataVisibilityResolver;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
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

        // Confirmed Rules §15: paid grace / restricted block Branch mutations (not navigation/views).
        if (! $request->isMethodSafe()) {
            $this->assertBranchMutationEntitlement($company);
        }

        WorkspaceSession::sync($request, $company->id, $branch->id);

        return $user;
    }

    /**
     * @return array{
     *     can_view: bool,
     *     can_create: bool,
     *     can_edit: bool,
     *     can_delete: bool,
     *     is_owner: bool,
     *     subscription_lifecycle_status: string,
     *     subscription_mutations_allowed: bool,
     *     subscription_restriction_reason: string|null
     * }
     */
    protected function masterPermissions(User $actor, Company $company, Branch $branch, CompanyAccessService $access, PermissionAuthorizer $authorizer): array
    {
        $isOwner = $access->isCompanyOwner($actor, $company);
        $entitlement = app(SubscriptionEntitlementGate::class)->evaluate($company);
        $mutationsAllowed = $entitlement['branch_mutations_allowed'];

        $canView = ($isOwner || $authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_VIEW, $company, $branch))
            && $entitlement['branch_view_allowed'];
        $canCreate = $mutationsAllowed && ($isOwner || $authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_CREATE, $company, $branch));
        $canEdit = $mutationsAllowed && ($isOwner || $authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_EDIT, $company, $branch));
        $canDelete = $mutationsAllowed && ($isOwner || $authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_DELETE, $company, $branch));

        return [
            'can_view' => $canView,
            'can_create' => $canCreate,
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
            'is_owner' => $isOwner,
            'subscription_lifecycle_status' => $entitlement['status']->value,
            'subscription_mutations_allowed' => $mutationsAllowed,
            'subscription_restriction_reason' => $entitlement['restriction_reason'],
        ];
    }

    protected function assertBranchMutationEntitlement(Company $company): void
    {
        app(SubscriptionEntitlementGate::class)->assertBranchMutationAllowed($company);
    }

    /**
     * @return array{id: string, name: string, status: string}
     */
    /**
     * @return array{id: string, name: string, status: string, data_visibility: string|null}
     */
    protected function companyPayload(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'status' => $company->isActive() ? 'Active' : 'Deactivated',
            'data_visibility' => $company->data_visibility?->value,
        ];
    }

    /**
     * @return array{mode: string, label: string, message: string}
     */
    protected function dataVisibilityPresentation(Company $company): array
    {
        return app(MasterDataVisibilityResolver::class)->presentation($company);
    }

    /**
     * @return array{hub: string, dashboard: string}
     */
    protected function branchMasterNavLinks(): array
    {
        return [
            'hub' => route('branch.masters.index', absolute: false),
            'dashboard' => route('branch.dashboard', absolute: false),
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
