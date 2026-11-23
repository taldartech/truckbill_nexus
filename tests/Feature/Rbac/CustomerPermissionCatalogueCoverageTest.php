<?php

/**
 * Exhaustive Customer permission catalogue + default role matrix coverage.
 *
 * Complements PermissionMasterTest. Asserts every PermissionCodes Customer
 * constant is registered and that default role grants match PRD §6 / RbacSeeder.
 * Module codes (enquiry/lr/trip/invoice/payment) remain unassigned by default.
 */

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\PermissionMasterService;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

/**
 * @return list<string>
 */
function customerCatalogueCodes(): array
{
    return array_values(array_unique(array_merge(
        PermissionCodes::customerPlatformCatalogue(),
        PermissionCodes::customerPhase4Catalogue(),
        PermissionCodes::customerPhase5Catalogue(),
    )));
}

/**
 * @return list<string>
 */
function permissionCodesClassCustomerConstants(): array
{
    $constants = (new ReflectionClass(PermissionCodes::class))->getConstants();
    $codes = [];

    foreach ($constants as $value) {
        if (is_string($value) && str_contains($value, '.')) {
            $codes[] = $value;
        }
    }

    return array_values(array_unique($codes));
}

function customerRoleBySlug(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

/**
 * @return list<string>
 */
function activeCodesForRole(Role $role): array
{
    return RolePermission::query()
        ->where('role_id', $role->id)
        ->whereNull('deactivated_at')
        ->with('permission')
        ->get()
        ->pluck('permission.code')
        ->filter()
        ->values()
        ->all();
}

test('every PermissionCodes customer constant is registered in Permission Master', function () {
    $fromClass = permissionCodesClassCustomerConstants();
    $fromCatalogues = customerCatalogueCodes();

    expect($fromClass)->toHaveCount(41)
        ->and($fromCatalogues)->toHaveCount(41)
        ->and($fromClass)->toEqualCanonicalizing($fromCatalogues);

    foreach ($fromCatalogues as $code) {
        $permission = Permission::query()->where('code', $code)->first();
        expect($permission)->not->toBeNull("Missing permission master row for {$code}")
            ->and($permission->domain)->toBe(PermissionDomain::Customer)
            ->and($permission->deactivated_at)->toBeNull();
    }
});

test('default customer role matrix matches governing platform grants without module blanket grants', function () {
    $expected = [
        'customer.admin' => [
            PermissionCodes::PLATFORM_VIEW,
            PermissionCodes::PLATFORM_CREATE,
            PermissionCodes::PLATFORM_EDIT,
            PermissionCodes::PLATFORM_CANCEL,
            PermissionCodes::PLATFORM_PRINT,
            PermissionCodes::PLATFORM_EXPORT,
            PermissionCodes::PLATFORM_MANAGE_SETTINGS,
            PermissionCodes::PLATFORM_VIEW_FREIGHT,
        ],
        'customer.operator' => [
            PermissionCodes::PLATFORM_VIEW,
            PermissionCodes::PLATFORM_CREATE,
            PermissionCodes::PLATFORM_PRINT,
            PermissionCodes::PLATFORM_VIEW_FREIGHT,
        ],
        'customer.viewer' => [
            PermissionCodes::PLATFORM_VIEW,
            PermissionCodes::PLATFORM_PRINT,
            PermissionCodes::PLATFORM_VIEW_FREIGHT,
        ],
        'customer.operator_no_freight' => [
            PermissionCodes::PLATFORM_VIEW,
            PermissionCodes::PLATFORM_CREATE,
            PermissionCodes::PLATFORM_PRINT,
        ],
        'customer.viewer_no_freight' => [
            PermissionCodes::PLATFORM_VIEW,
            PermissionCodes::PLATFORM_PRINT,
        ],
    ];

    $moduleCodes = array_merge(
        PermissionCodes::customerPhase4Catalogue(),
        PermissionCodes::customerPhase5Catalogue(),
    );

    foreach ($expected as $slug => $codes) {
        $actual = activeCodesForRole(customerRoleBySlug($slug));
        expect($actual)->toEqualCanonicalizing($codes);

        foreach ($moduleCodes as $moduleCode) {
            expect($actual)->not->toContain($moduleCode);
        }

        expect($actual)->not->toContain(PermissionCodes::PLATFORM_DELETE);
    }
});

test('each customer permission code can be granted and denied via authorizer for an isolated role', function () {
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Catalogue Co');
    $branch = $company->branches()->firstOrFail();
    $sa = User::factory()->superAdmin()->create();
    $master = app(PermissionMasterService::class);
    $rbac = app(RoleAssignmentService::class);
    $auth = app(PermissionAuthorizer::class);

    $customRole = $master->createRole($sa, [
        'domain' => PermissionDomain::Customer,
        'name' => 'Catalogue Probe',
        'slug' => 'customer.catalogue_probe',
        'description' => 'Isolated permission probe role',
    ]);

    $member = User::factory()->customer()->create();
    $membership = app(CompanyWorkspaceService::class)->addMembership($company, $member, 'Join');
    $assignment = app(CompanyWorkspaceService::class)->assignBranch($membership, $branch, 'HO');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $assignment,
        $customRole,
        'Assign probe role',
    );

    foreach (customerCatalogueCodes() as $code) {
        expect($auth->allowsCustomer($member, $code, $company, $branch))->toBeFalse();

        $permission = Permission::query()->where('code', $code)->firstOrFail();
        $pivot = $master->assignPermissionToRole($sa, $customRole, $permission, "Grant {$code}");
        expect($auth->allowsCustomer($member, $code, $company, $branch))->toBeTrue();

        $master->revokePermissionFromRole($sa, $pivot, "Revoke {$code}");
        expect($auth->allowsCustomer($member, $code, $company, $branch))->toBeFalse();
    }
});

test('deactivated permission code loses effect for every customer role that held it', function () {
    $sa = User::factory()->superAdmin()->create();
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Deactivate Co');
    $branch = $company->branches()->firstOrFail();
    $rbac = app(RoleAssignmentService::class);
    $auth = app(PermissionAuthorizer::class);

    $member = User::factory()->customer()->create();
    $membership = app(CompanyWorkspaceService::class)->addMembership($company, $member, 'Join');
    $assignment = app(CompanyWorkspaceService::class)->assignBranch($membership, $branch, 'HO');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $assignment,
        customerRoleBySlug('customer.admin'),
        'Admin',
    );

    expect($auth->allowsCustomer($member, PermissionCodes::PLATFORM_EXPORT, $company, $branch))->toBeTrue();

    $export = Permission::query()->where('code', PermissionCodes::PLATFORM_EXPORT)->firstOrFail();
    app(PermissionMasterService::class)->deactivatePermission($sa, $export, 'Disable export');

    expect($auth->allowsCustomer($member, PermissionCodes::PLATFORM_EXPORT, $company, $branch))->toBeFalse();
});
