<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionMasterService;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerWebSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

if (! function_exists('rbacHttpAs')) {
    /**
     * @param  array{company: Company, branch: Branch}  $ctx
     */
    function rbacHttpAs(User $user, array $ctx): void
    {
        test()->flushSession();
        CustomerWebSession::query()->where('user_id', $user->id)->delete();
        test()->actingAs($user);
        session()->forget(WebSessionService::SESSION_VERSION_KEY);
        seedBranchSession($ctx['company'], $ctx['branch']);
    }
}

if (! function_exists('rbacExpectHttpDenied')) {
    function rbacExpectHttpDenied(TestResponse $response): void
    {
        expect(in_array($response->status(), [403, 302], true))->toBeTrue();
    }
}

if (! function_exists('rbacGrantRolePermissions')) {
    /**
     * @param  list<string>  $permissionCodes
     */
    function rbacGrantRolePermissions(string $roleSlug, array $permissionCodes): void
    {
        foreach ($permissionCodes as $code) {
            tripGrant($roleSlug, $code);
        }
    }
}

if (! function_exists('rbacBranchPersona')) {
    /**
     * Branch member with a built-in customer role slug.
     *
     * @param  array{owner: User, company: Company, branch: Branch}  $ctx
     * @param  list<string>  $extraPermissionCodes  Granted on the role slug (shared catalogue role).
     */
    function rbacBranchPersona(array $ctx, string $roleSlug, array $extraPermissionCodes = []): User
    {
        rbacGrantRolePermissions($roleSlug, $extraPermissionCodes);

        $workspace = app(CompanyWorkspaceService::class);
        $rbac = app(RoleAssignmentService::class);

        $user = User::factory()->customer()->create([
            'password' => Hash::make('rbac-persona-secret'),
        ]);

        $membership = $workspace->addMembership($ctx['company'], $user, 'RBAC persona join');
        $assignment = $workspace->assignBranch($membership, $ctx['branch'], 'RBAC persona branch');
        $rbac->assignCustomerBranchRole(
            $ctx['owner'],
            $membership,
            $ctx['branch'],
            $assignment,
            Role::query()
                ->where('slug', $roleSlug)
                ->where('domain', PermissionDomain::Customer->value)
                ->firstOrFail(),
            "Assign {$roleSlug}",
        );

        return $user;
    }
}

if (! function_exists('rbacCustomBranchPersona')) {
    /**
     * Isolated custom branch role with an explicit permission list.
     *
     * @param  array{owner: User, company: Company, branch: Branch}  $ctx
     * @param  list<string>  $permissionCodes
     */
    function rbacCustomBranchPersona(array $ctx, string $roleSlug, string $roleName, array $permissionCodes): User
    {
        $sa = User::factory()->superAdmin()->create();
        $master = app(PermissionMasterService::class);

        $customRole = $master->createRole($sa, [
            'domain' => PermissionDomain::Customer,
            'name' => $roleName,
            'slug' => $roleSlug,
            'description' => 'Phase 5/6 RBAC certification custom role',
        ]);

        foreach ($permissionCodes as $code) {
            $permission = Permission::query()->where('code', $code)->firstOrFail();
            $master->assignPermissionToRole($sa, $customRole, $permission, "Grant {$code}");
        }

        $workspace = app(CompanyWorkspaceService::class);
        $rbac = app(RoleAssignmentService::class);

        $user = User::factory()->customer()->create([
            'password' => Hash::make('rbac-custom-secret'),
        ]);

        $membership = $workspace->addMembership($ctx['company'], $user, 'RBAC custom join');
        $assignment = $workspace->assignBranch($membership, $ctx['branch'], 'RBAC custom branch');
        $rbac->assignCustomerBranchRole(
            $ctx['owner'],
            $membership,
            $ctx['branch'],
            $assignment,
            $customRole,
            'Assign custom RBAC role',
        );

        return $user;
    }
}
