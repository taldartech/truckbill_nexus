<?php

/**
 * Internal ordinary roles: explicit grant vs absent permission (no role-title authority).
 */

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionMasterService;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('sales executive has no CRM permissions until Super Admin grants them', function () {
    $sa = User::factory()->superAdmin()->create();
    $rbac = app(RoleAssignmentService::class);
    $master = app(PermissionMasterService::class);
    $auth = app(PermissionAuthorizer::class);

    $sales = $rbac->createInternalUser($sa, [
        'name' => 'Sales Exec',
        'email' => 'sales-boundary@example.com',
        'password' => 'password',
    ], Role::query()->where('slug', 'internal.sales_executive')->where('domain', PermissionDomain::Internal->value)->firstOrFail());

    expect($auth->allowsInternal($sales, 'internal.crm.leads.view'))->toBeFalse()
        ->and($auth->allowsInternal($sales, 'internal.crm.tickets.view'))->toBeFalse();

    $permission = $master->createPermission($sa, [
        'domain' => PermissionDomain::Internal,
        'module' => 'crm',
        'submodule' => 'leads',
        'name' => 'View leads',
        'code' => 'internal.crm.leads.view',
    ]);
    $role = Role::query()->where('slug', 'internal.sales_executive')->firstOrFail();
    $master->assignPermissionToRole($sa, $role, $permission, 'Grant leads view');

    expect($auth->allowsInternal($sales, 'internal.crm.leads.view'))->toBeTrue()
        ->and($auth->allowsInternal($sales, 'internal.crm.tickets.view'))->toBeFalse();
});

test('support executive cannot administer internal users even with a support permission', function () {
    $sa = User::factory()->superAdmin()->create();
    $rbac = app(RoleAssignmentService::class);
    $master = app(PermissionMasterService::class);

    $support = $rbac->createInternalUser($sa, [
        'name' => 'Support Exec',
        'email' => 'support-boundary@example.com',
        'password' => 'password',
    ], Role::query()->where('slug', 'internal.support_executive')->where('domain', PermissionDomain::Internal->value)->firstOrFail());

    $permission = $master->createPermission($sa, [
        'domain' => PermissionDomain::Internal,
        'module' => 'crm',
        'submodule' => 'tickets',
        'name' => 'View tickets',
        'code' => 'internal.crm.tickets.view',
    ]);
    $role = Role::query()->where('slug', 'internal.support_executive')->firstOrFail();
    $master->assignPermissionToRole($sa, $role, $permission, 'Grant tickets view');

    expect(app(PermissionAuthorizer::class)->allowsInternal($support, 'internal.crm.tickets.view'))->toBeTrue();

    expect(fn () => $rbac->createInternalUser($support, [
        'name' => 'Blocked',
        'email' => 'blocked-by-support@example.com',
        'password' => 'password',
    ]))->toThrow(RbacAuthorizationException::class);
});

test('internal admin cannot escalate to super admin via createInternalUser', function () {
    $sa = User::factory()->superAdmin()->create();
    $rbac = app(RoleAssignmentService::class);
    $admin = $rbac->createInternalUser($sa, [
        'name' => 'Int Admin',
        'email' => 'int-admin-boundary@example.com',
        'password' => 'password',
    ], Role::query()->where('slug', 'internal.admin')->where('domain', PermissionDomain::Internal->value)->firstOrFail());

    expect(fn () => $rbac->createInternalUser($admin, [
        'name' => 'Escalation',
        'email' => 'escalation-boundary@example.com',
        'password' => 'password',
        'is_super_admin' => true,
    ]))->toThrow(RbacAuthorizationException::class);
});
