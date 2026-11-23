<?php

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalDemoWorkspaceSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * @return list<string>
 */
function expectedAdminPermissionCodes(): array
{
    return [
        PermissionCodes::PLATFORM_VIEW,
        PermissionCodes::PLATFORM_CREATE,
        PermissionCodes::PLATFORM_EDIT,
        PermissionCodes::PLATFORM_CANCEL,
        PermissionCodes::PLATFORM_PRINT,
        PermissionCodes::PLATFORM_EXPORT,
        PermissionCodes::PLATFORM_MANAGE_SETTINGS,
        PermissionCodes::PLATFORM_VIEW_FREIGHT,
    ];
}

/**
 * @return list<string>
 */
function rolePermissionCodes(string $slug): array
{
    $role = Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();

    return RolePermission::query()
        ->where('role_id', $role->id)
        ->whereNull('deactivated_at')
        ->whereNull('deleted_at')
        ->with('permission')
        ->get()
        ->pluck('permission.code')
        ->sort()
        ->values()
        ->all();
}

test('default database seeder seeds rbac with valid uuids and expected customer matrix', function () {
    $this->seed(DatabaseSeeder::class);

    $permissions = Permission::query()->get();
    expect($permissions)->not->toBeEmpty();
    foreach ($permissions as $permission) {
        expect(Str::isUuid((string) $permission->id))->toBeTrue();
    }

    $roles = Role::query()->get();
    expect($roles)->not->toBeEmpty();
    foreach ($roles as $role) {
        expect(Str::isUuid((string) $role->id))->toBeTrue();
    }

    $pivots = RolePermission::query()->get();
    expect($pivots)->not->toBeEmpty();
    foreach ($pivots as $pivot) {
        expect(Str::isUuid((string) $pivot->id))->toBeTrue();
    }

    expect(Role::query()->where('domain', PermissionDomain::Customer->value)->where('is_default', true)->count())->toBe(5)
        ->and(Role::query()->where('domain', PermissionDomain::Internal->value)->where('is_default', true)->count())->toBe(3)
        ->and(Permission::query()->where('code', PermissionCodes::PLATFORM_DELETE)->exists())->toBeTrue();

    foreach (PermissionCodes::customerPhase4Catalogue() as $code) {
        expect(Permission::query()->where('code', $code)->exists())->toBeTrue($code);
    }

    $adminCodes = rolePermissionCodes('customer.admin');
    expect($adminCodes)->toEqualCanonicalizing(expectedAdminPermissionCodes())
        ->and($adminCodes)->not->toContain(PermissionCodes::PLATFORM_DELETE);

    expect(rolePermissionCodes('customer.operator'))->toEqualCanonicalizing([
        PermissionCodes::PLATFORM_VIEW,
        PermissionCodes::PLATFORM_CREATE,
        PermissionCodes::PLATFORM_PRINT,
        PermissionCodes::PLATFORM_VIEW_FREIGHT,
    ]);

    expect(rolePermissionCodes('customer.viewer'))->toEqualCanonicalizing([
        PermissionCodes::PLATFORM_VIEW,
        PermissionCodes::PLATFORM_PRINT,
        PermissionCodes::PLATFORM_VIEW_FREIGHT,
    ]);

    expect(rolePermissionCodes('customer.operator_no_freight'))->toEqualCanonicalizing([
        PermissionCodes::PLATFORM_VIEW,
        PermissionCodes::PLATFORM_CREATE,
        PermissionCodes::PLATFORM_PRINT,
    ])->and(rolePermissionCodes('customer.viewer_no_freight'))->toEqualCanonicalizing([
        PermissionCodes::PLATFORM_VIEW,
        PermissionCodes::PLATFORM_PRINT,
    ]);

    foreach (Role::query()->where('domain', PermissionDomain::Customer->value)->pluck('id') as $roleId) {
        $codes = RolePermission::query()
            ->where('role_id', $roleId)
            ->whereNull('deactivated_at')
            ->with('permission')
            ->get()
            ->pluck('permission.code');
        expect($codes)->not->toContain(PermissionCodes::PLATFORM_DELETE);
        foreach (PermissionCodes::customerPhase4Catalogue() as $phase4Code) {
            expect($codes)->not->toContain($phase4Code);
        }
    }
});

test('default database seeder is idempotent and does not create unsafe demo logins', function () {
    $this->seed(DatabaseSeeder::class);

    $permissionCount = Permission::query()->count();
    $roleCount = Role::query()->count();
    $pivotCount = RolePermission::query()->count();

    $this->seed(DatabaseSeeder::class);
    (new RbacSeeder)->run();

    expect(Permission::query()->count())->toBe($permissionCount)
        ->and(Role::query()->count())->toBe($roleCount)
        ->and(RolePermission::query()->count())->toBe($pivotCount)
        ->and(User::query()->where('email', 'test@example.com')->exists())->toBeFalse()
        ->and(User::query()->where('email', LocalDemoWorkspaceSeeder::OWNER_A_EMAIL)->exists())->toBeFalse()
        ->and(User::query()->where('email', 'demo.local@example.com')->exists())->toBeFalse();
});

test('only one comprehensive demo workspace seeder class remains', function () {
    $seeders = collect(File::files(database_path('seeders')))
        ->map(fn ($file) => $file->getFilename())
        ->sort()
        ->values()
        ->all();

    expect($seeders)->toBe([
        'DatabaseSeeder.php',
        'LocalDemoWorkspaceSeeder.php',
        'RbacSeeder.php',
        'SubscriptionCatalogueSeeder.php',
    ])->and(File::exists(database_path('seeders/LocalDemoUserSeeder.php')))->toBeFalse();
});
