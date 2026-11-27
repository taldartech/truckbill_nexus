<?php

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Identity\Exceptions\LastSuperAdminProtectedException;
use App\Domains\Identity\IdentityLifecycleService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCacheInvalidator;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\PermissionMasterService;
use App\Domains\Rbac\PermissionResolver;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\InternalRoleAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditEntry;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function rbacMaster(): PermissionMasterService
{
    return app(PermissionMasterService::class);
}

function rbacAssign(): RoleAssignmentService
{
    return app(RoleAssignmentService::class);
}

function rbacAuth(): PermissionAuthorizer
{
    return app(PermissionAuthorizer::class);
}

function superAdminActor(): User
{
    return User::factory()->superAdmin()->create();
}

function seedCustomerWorkspace(): array
{
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'RBAC Co');
    $branch = $company->branches()->firstOrFail();

    return compact('owner', 'company', 'branch');
}

function customerRole(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

function internalRole(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Internal->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

test('rbac seeder is idempotent and seeds customer default matrix without delete', function () {
    expect(Role::query()->where('domain', PermissionDomain::Customer->value)->where('is_default', true)->count())->toBe(5)
        ->and(Role::query()->where('domain', PermissionDomain::Internal->value)->where('is_default', true)->count())->toBe(3)
        ->and(Permission::query()->where('code', PermissionCodes::PLATFORM_DELETE)->exists())->toBeTrue();

    (new RbacSeeder)->run();

    expect(Role::query()->where('slug', 'customer.admin')->count())->toBe(1)
        ->and(Permission::query()->where('code', PermissionCodes::PLATFORM_VIEW)->count())->toBe(1);

    $admin = customerRole('customer.admin');
    $adminCodes = RolePermission::query()
        ->where('role_id', $admin->id)
        ->whereNull('deactivated_at')
        ->with('permission')
        ->get()
        ->pluck('permission.code')
        ->all();

    expect($adminCodes)->toContain(PermissionCodes::PLATFORM_VIEW)
        ->and($adminCodes)->toContain(PermissionCodes::PLATFORM_CANCEL)
        ->and($adminCodes)->not->toContain(PermissionCodes::PLATFORM_DELETE);

    $noFreight = customerRole('customer.operator_no_freight');
    $noFreightCodes = RolePermission::query()
        ->where('role_id', $noFreight->id)
        ->whereNull('deactivated_at')
        ->with('permission')
        ->get()
        ->pluck('permission.code')
        ->all();

    expect($noFreightCodes)->not->toContain(PermissionCodes::PLATFORM_VIEW_FREIGHT)
        ->and($noFreightCodes)->toContain(PermissionCodes::PLATFORM_VIEW);
});

test('super admin is independent of permission master role rows', function () {
    $sa = superAdminActor();

    expect($sa->isSuperAdmin())->toBeTrue()
        ->and(InternalRoleAssignment::query()->where('user_id', $sa->id)->exists())->toBeFalse()
        ->and(Role::query()->where('slug', 'like', '%super%')->exists())->toBeFalse()
        ->and(rbacAuth()->allowsInternal($sa, PermissionCodes::PLATFORM_VIEW))->toBeTrue()
        ->and(Gate::forUser($sa)->check('administerInternal', $sa))->toBeTrue()
        ->and(Gate::forUser($sa)->allows('actAsCompanyOwner', $sa))->toBeFalse();
});

test('internal and customer domains cannot cross-assign roles', function () {
    $sa = superAdminActor();
    $internalUser = User::factory()->internal()->create();
    $customer = User::factory()->customer()->create();

    expect(fn () => rbacAssign()->assignInternalRole($sa, $customer, internalRole('internal.admin'), 'Cross domain'))
        ->toThrow(RbacDomainException::class);

    expect(fn () => rbacAssign()->assignInternalRole($sa, $internalUser, customerRole('customer.admin'), 'Cross domain'))
        ->toThrow(RbacDomainException::class);
});

test('only super admin manages permission master and internal users', function () {
    $ordinaryInternal = User::factory()->internal()->create();
    $sa = superAdminActor();

    expect(fn () => rbacMaster()->createPermission($ordinaryInternal, [
        'domain' => PermissionDomain::Internal,
        'module' => 'platform',
        'name' => 'X',
        'code' => 'internal.test.x',
    ]))->toThrow(RbacAuthorizationException::class);

    expect(fn () => rbacAssign()->createInternalUser($ordinaryInternal, [
        'name' => 'Blocked',
        'email' => 'blocked-internal@example.com',
        'password' => 'password',
    ]))->toThrow(RbacAuthorizationException::class);

    $created = rbacAssign()->createInternalUser($sa, [
        'name' => 'Support',
        'email' => 'support@example.com',
        'password' => 'password',
    ], internalRole('internal.support_executive'));

    expect($created->isInternal())->toBeTrue()
        ->and($created->isSuperAdmin())->toBeFalse()
        ->and(InternalRoleAssignment::query()->where('user_id', $created->id)->whereNull('deactivated_at')->exists())->toBeTrue();
});

test('ordinary internal admin cannot grant super admin via role assignment', function () {
    $sa = superAdminActor();
    $adminUser = rbacAssign()->createInternalUser($sa, [
        'name' => 'Admin',
        'email' => 'admin-int@example.com',
        'password' => 'password',
    ], internalRole('internal.admin'));

    expect(fn () => rbacAssign()->createInternalUser($adminUser, [
        'name' => 'Escalation',
        'email' => 'escalate@example.com',
        'password' => 'password',
        'is_super_admin' => true,
    ]))->toThrow(RbacAuthorizationException::class);

    // Mass-assignment cannot set is_super_admin (not fillable).
    $adminUser->fill(['is_super_admin' => true, 'name' => 'Still Admin']);
    expect($adminUser->is_super_admin)->toBeFalse()
        ->and($adminUser->isSuperAdmin())->toBeFalse();
});

test('duplicate permission codes and active assignments are rejected', function () {
    $sa = superAdminActor();

    rbacMaster()->createPermission($sa, [
        'domain' => PermissionDomain::Customer,
        'module' => 'platform',
        'name' => 'Dup',
        'code' => 'platform.operations.dup',
    ]);

    expect(fn () => rbacMaster()->createPermission($sa, [
        'domain' => PermissionDomain::Customer,
        'module' => 'platform',
        'name' => 'Dup2',
        'code' => 'platform.operations.dup',
    ]))->toThrow(QueryException::class);

    $role = customerRole('customer.viewer');
    $permission = Permission::query()->where('code', PermissionCodes::PLATFORM_VIEW)->firstOrFail();

    expect(fn () => rbacMaster()->assignPermissionToRole($sa, $role, $permission, 'Already present'))
        ->toThrow(RbacDomainException::class);
});

test('roles cannot be deleted and deactivated roles cannot be newly assigned', function () {
    $sa = superAdminActor();
    $role = customerRole('customer.viewer');
    $user = User::factory()->internal()->create();

    expect(fn () => $role->delete())->toThrow(RbacDomainException::class);

    rbacMaster()->deactivateRole($sa, $role, 'Retiring viewer');

    expect(fn () => rbacAssign()->assignInternalRole($sa, $user, $role, 'Should fail domain anyway'))
        ->toThrow(RbacDomainException::class);

    $internalAdmin = internalRole('internal.admin');
    rbacMaster()->deactivateRole($sa, $internalAdmin, 'Pause admin role');

    expect(fn () => rbacAssign()->assignInternalRole($sa, $user, $internalAdmin, 'Assign deactivated'))
        ->toThrow(RbacDomainException::class);
});

test('deactivated permissions cannot be newly assigned and lose effect immediately', function () {
    $sa = superAdminActor();
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = seedCustomerWorkspace();

    $member = User::factory()->customer()->create();
    $membership = app(CompanyWorkspaceService::class)->addMembership($company, $member, 'Join');
    $branchAssignment = app(CompanyWorkspaceService::class)->assignBranch($membership, $branch, 'HO');
    rbacAssign()->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $branchAssignment,
        customerRole('customer.admin'),
        'Grant admin',
    );

    expect(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_EXPORT, $company, $branch))->toBeTrue();

    $export = Permission::query()->where('code', PermissionCodes::PLATFORM_EXPORT)->firstOrFail();
    rbacMaster()->deactivatePermission($sa, $export, 'Disable export globally');

    expect(fn () => rbacMaster()->assignPermissionToRole($sa, customerRole('customer.operator'), $export, 'Reassign inactive'))
        ->toThrow(RbacDomainException::class);

    expect(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_EXPORT, $company, $branch))->toBeFalse();
});

test('customer branch role assignment is branch specific and requires membership assignment', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $headOffice] = seedCustomerWorkspace();
    $second = app(CompanyWorkspaceService::class)->createBranch($company, 'Warehouse', 'Opened WH');

    $member = User::factory()->customer()->create();
    $membership = app(CompanyWorkspaceService::class)->addMembership($company, $member, 'Join');
    $hoAssignment = app(CompanyWorkspaceService::class)->assignBranch($membership, $headOffice, 'HO only');

    rbacAssign()->assignCustomerBranchRole(
        $owner,
        $membership,
        $headOffice,
        $hoAssignment,
        customerRole('customer.operator'),
        'Operator at HO',
    );

    expect(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_CREATE, $company, $headOffice))->toBeTrue()
        ->and(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_CREATE, $company, $second))->toBeFalse()
        ->and(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_VIEW_FREIGHT, $company, $headOffice))->toBeTrue();

    expect(fn () => rbacAssign()->assignCustomerBranchRole(
        $owner,
        $membership,
        $second,
        $hoAssignment,
        customerRole('customer.viewer'),
        'Wrong branch assignment',
    ))->toThrow(RbacDomainException::class);
});

test('cross-company branch role assignment is rejected', function () {
    ['owner' => $ownerA, 'company' => $companyA, 'branch' => $branchA] = seedCustomerWorkspace();
    $ownerB = User::factory()->customer()->create();
    $companyB = app(CompanyWorkspaceService::class)->createCompany($ownerB, 'Other Co');

    $member = User::factory()->customer()->create();
    $membershipB = app(CompanyWorkspaceService::class)->addMembership($companyB, $member, 'Join B');
    $assignmentB = app(CompanyWorkspaceService::class)->assignBranch(
        $membershipB,
        $companyB->branches()->firstOrFail(),
        'B HO',
    );

    expect(fn () => rbacAssign()->assignCustomerBranchRole(
        $ownerA,
        $membershipB,
        $branchA,
        $assignmentB,
        customerRole('customer.viewer'),
        'Cross company',
    ))->toThrow(RbacAuthorizationException::class);
});

test('inactive membership branch or role denies effective permissions', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = seedCustomerWorkspace();
    $member = User::factory()->customer()->create();
    $membership = app(CompanyWorkspaceService::class)->addMembership($company, $member, 'Join');
    $branchAssignment = app(CompanyWorkspaceService::class)->assignBranch($membership, $branch, 'HO');
    $roleAssignment = rbacAssign()->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $branchAssignment,
        customerRole('customer.viewer'),
        'Viewer',
    );

    expect(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_VIEW, $company, $branch))->toBeTrue();

    app(CompanyWorkspaceService::class)->deactivateMembership($membership, 'Left');
    expect(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_VIEW, $company, $branch))->toBeFalse();

    // Fresh member for role deactivation path
    $member2 = User::factory()->customer()->create();
    $membership2 = app(CompanyWorkspaceService::class)->addMembership($company, $member2, 'Join2');
    $ba2 = app(CompanyWorkspaceService::class)->assignBranch($membership2, $branch, 'HO2');
    rbacAssign()->assignCustomerBranchRole(
        $owner,
        $membership2,
        $branch,
        $ba2,
        customerRole('customer.operator'),
        'Op',
    );

    rbacMaster()->deactivateRole(superAdminActor(), customerRole('customer.operator'), 'Retire operator');
    expect(rbacAuth()->allowsCustomer($member2, PermissionCodes::PLATFORM_CREATE, $company, $branch))->toBeFalse();

    rbacAssign()->revokeCustomerBranchRole($owner, $roleAssignment, 'Cleanup historical');
});

test('owner access remains independent of ordinary role assignments and grants customer domain operational codes', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = seedCustomerWorkspace();

    expect(app(CompanyAccessService::class)->canAccessBranch($owner, $company, $branch))->toBeTrue()
        ->and(rbacAuth()->allowsCustomer($owner, PermissionCodes::PLATFORM_VIEW, $company, $branch))->toBeTrue()
        ->and(rbacAuth()->allowsCustomer($owner, PermissionCodes::BROKER_LORRY_HIRE_VIEW, $company, $branch))->toBeTrue()
        ->and(rbacAuth()->allowsInternal($owner, PermissionCodes::INTERNAL_CRM_VIEW))->toBeFalse()
        ->and(app(CompanyAccessService::class)->canAccessOwnerPanel($owner, $company))->toBeTrue();
});

test('role names alone do not grant delete or other permissions', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = seedCustomerWorkspace();
    $member = User::factory()->customer()->create();
    $membership = app(CompanyWorkspaceService::class)->addMembership($company, $member, 'Join');
    $ba = app(CompanyWorkspaceService::class)->assignBranch($membership, $branch, 'HO');
    rbacAssign()->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $ba,
        customerRole('customer.admin'),
        'Named Admin',
    );

    expect(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_DELETE, $company, $branch))->toBeFalse()
        ->and(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_EDIT, $company, $branch))->toBeTrue();
});

test('permission revocation and role reassignment take effect immediately via cache invalidation', function () {
    $sa = superAdminActor();
    $user = rbacAssign()->createInternalUser($sa, [
        'name' => 'Sales',
        'email' => 'sales@example.com',
        'password' => 'password',
    ], internalRole('internal.sales_executive'));

    $permission = rbacMaster()->createPermission($sa, [
        'domain' => PermissionDomain::Internal,
        'module' => 'crm',
        'submodule' => 'leads',
        'name' => 'View leads',
        'code' => 'internal.crm.leads.view',
    ]);

    $role = internalRole('internal.sales_executive');
    $pivot = rbacMaster()->assignPermissionToRole($sa, $role, $permission, 'Grant leads view');

    expect(rbacAuth()->allowsInternal($user, 'internal.crm.leads.view'))->toBeTrue();

    rbacMaster()->revokePermissionFromRole($sa, $pivot, 'Revoke leads view');
    expect(rbacAuth()->allowsInternal($user, 'internal.crm.leads.view'))->toBeFalse();

    rbacMaster()->assignPermissionToRole($sa, $role, $permission, 'Re-grant');
    expect(rbacAuth()->allowsInternal($user, 'internal.crm.leads.view'))->toBeTrue();

    rbacAssign()->assignInternalRole($sa, $user, internalRole('internal.support_executive'), 'Switch to support');
    expect(rbacAuth()->allowsInternal($user, 'internal.crm.leads.view'))->toBeFalse();
});

test('branch permission caches do not leak across branches', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branchA] = seedCustomerWorkspace();
    $branchB = app(CompanyWorkspaceService::class)->createBranch($company, 'B', 'Branch B');

    $member = User::factory()->customer()->create();
    $membership = app(CompanyWorkspaceService::class)->addMembership($company, $member, 'Join');
    $assignA = app(CompanyWorkspaceService::class)->assignBranch($membership, $branchA, 'A');
    $assignB = app(CompanyWorkspaceService::class)->assignBranch($membership, $branchB, 'B');

    rbacAssign()->assignCustomerBranchRole(
        $owner,
        $membership,
        $branchA,
        $assignA,
        customerRole('customer.operator'),
        'Op A',
    );
    rbacAssign()->assignCustomerBranchRole(
        $owner,
        $membership,
        $branchB,
        $assignB,
        customerRole('customer.viewer_no_freight'),
        'Viewer B',
    );

    expect(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_CREATE, $company, $branchA))->toBeTrue()
        ->and(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_CREATE, $company, $branchB))->toBeFalse()
        ->and(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_VIEW_FREIGHT, $company, $branchA))->toBeTrue()
        ->and(rbacAuth()->allowsCustomer($member, PermissionCodes::PLATFORM_VIEW_FREIGHT, $company, $branchB))->toBeFalse();

    $cache = app(PermissionCacheInvalidator::class);
    $keyA = $cache->cacheKey(PermissionDomain::Customer->value, $member->id, $branchA->id);
    $keyB = $cache->cacheKey(PermissionDomain::Customer->value, $member->id, $branchB->id);
    expect($keyA)->not->toBe($keyB);
});

test('audit records are written atomically with remarks for mutations', function () {
    $sa = superAdminActor();
    $before = AuditEntry::query()->count();

    $permission = rbacMaster()->createPermission($sa, [
        'domain' => PermissionDomain::Internal,
        'module' => 'system',
        'name' => 'Ping',
        'code' => 'internal.system.ping',
    ]);

    expect(AuditEntry::query()->where('subject_type', Permission::class)->where('action', AuditAction::Created->value)->count())->toBe(1);

    expect(fn () => rbacMaster()->deactivatePermission($sa, $permission, ''))
        ->toThrow(InvalidArgumentException::class);

    expect($permission->fresh()->isActive())->toBeTrue();

    rbacMaster()->deactivatePermission($sa, $permission, 'Disable ping');
    expect(AuditEntry::query()->where('subject_type', Permission::class)->where('remark', 'Disable ping')->exists())->toBeTrue()
        ->and(AuditEntry::query()->count())->toBeGreaterThan($before);
});

test('customer permissions cannot authorize internal operations and vice versa', function () {
    $sa = superAdminActor();
    $internal = rbacAssign()->createInternalUser($sa, [
        'name' => 'Int',
        'email' => 'int-op@example.com',
        'password' => 'password',
    ], internalRole('internal.admin'));

    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = seedCustomerWorkspace();
    $member = User::factory()->customer()->create();
    $membership = app(CompanyWorkspaceService::class)->addMembership($company, $member, 'Join');
    $ba = app(CompanyWorkspaceService::class)->assignBranch($membership, $branch, 'HO');
    rbacAssign()->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $ba,
        customerRole('customer.admin'),
        'Admin',
    );

    expect(rbacAuth()->allowsInternal($member, PermissionCodes::PLATFORM_VIEW))->toBeFalse()
        ->and(rbacAuth()->allowsCustomer($internal, PermissionCodes::PLATFORM_VIEW, $company, $branch))->toBeFalse();
});

test('last active super admin protection remains intact with rbac services', function () {
    $only = superAdminActor();

    expect($only->isLastActiveSuperAdmin())->toBeTrue();

    expect(fn () => app(IdentityLifecycleService::class)->revokeSuperAdminPrivilege($only, ' demote'))
        ->toThrow(LastSuperAdminProtectedException::class);
});

test('permission resolution fails closed when resolver cannot compute', function () {
    $resolver = new class extends PermissionResolver
    {
        public function codesForInternal(User $user): array
        {
            throw new RbacDomainException('Permission resolution failed closed: simulated');
        }
    };

    $authorizer = new PermissionAuthorizer($resolver);
    $user = User::factory()->internal()->create();

    expect(fn () => $authorizer->allowsInternal($user, 'anything'))
        ->toThrow(RbacDomainException::class);
});
