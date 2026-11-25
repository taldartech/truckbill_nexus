<?php

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Models\City;
use App\Models\Company;
use App\Models\GstRate;
use App\Models\PaymentMode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\State;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionPlan;
use App\Models\TdsRule;
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

beforeEach(function (): void {
    config([
        'internal_super_admin.name' => 'Test Super Admin',
        'internal_super_admin.email' => 'super.admin@testing.truckbill.local',
        'internal_super_admin.password' => 'testing-super-admin-secret',
    ]);
});

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

test('default database seeder seeds geography tax subscription and super admin without demo data', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(State::query()->where('country_code', 'IN')->count())->toBe(36)
        ->and(City::query()->count())->toBeGreaterThan(100)
        ->and(PaymentMode::query()->count())->toBeGreaterThanOrEqual(8)
        ->and(PaymentMode::query()->whereIn('code', ['cash', 'upi', 'neft', 'rtgs', 'imps', 'other'])->count())->toBe(6)
        ->and(GstRate::query()->where('is_active', true)->whereIn('rate_percent', ['0.000', '5.000', '12.000', '18.000', '28.000'])->count())->toBe(5)
        ->and(TdsRule::query()->where('is_active', true)->count())->toBeGreaterThanOrEqual(4)
        ->and(SubscriptionPlan::query()->count())->toBeGreaterThanOrEqual(11)
        ->and(SubscriptionAddon::query()->count())->toBeGreaterThanOrEqual(12)
        ->and(User::query()->where('is_super_admin', true)->count())->toBe(1)
        ->and(Company::query()->count())->toBe(0)
        ->and(User::query()->where('email', LocalDemoWorkspaceSeeder::OWNER_A_EMAIL)->exists())->toBeFalse();
});

test('default database seeder is idempotent and does not create unsafe demo logins', function () {
    $this->seed(DatabaseSeeder::class);

    $permissionCount = Permission::query()->count();
    $roleCount = Role::query()->count();
    $pivotCount = RolePermission::query()->count();
    $stateCount = State::query()->count();
    $cityCount = City::query()->count();
    $paymentModeCount = PaymentMode::query()->count();
    $gstRateCount = GstRate::query()->count();
    $tdsRuleCount = TdsRule::query()->count();
    $planCount = SubscriptionPlan::query()->count();
    $addonCount = SubscriptionAddon::query()->count();
    $superAdminCount = User::query()->where('is_super_admin', true)->count();
    $superAdminHash = User::query()->where('is_super_admin', true)->value('password');

    $this->seed(DatabaseSeeder::class);
    (new RbacSeeder)->run();

    expect(Permission::query()->count())->toBe($permissionCount)
        ->and(Role::query()->count())->toBe($roleCount)
        ->and(RolePermission::query()->count())->toBe($pivotCount)
        ->and(State::query()->count())->toBe($stateCount)
        ->and(City::query()->count())->toBe($cityCount)
        ->and(PaymentMode::query()->count())->toBe($paymentModeCount)
        ->and(GstRate::query()->count())->toBe($gstRateCount)
        ->and(TdsRule::query()->count())->toBe($tdsRuleCount)
        ->and(SubscriptionPlan::query()->count())->toBe($planCount)
        ->and(SubscriptionAddon::query()->count())->toBe($addonCount)
        ->and(User::query()->where('is_super_admin', true)->count())->toBe($superAdminCount)
        ->and(User::query()->where('is_super_admin', true)->value('password'))->toBe($superAdminHash)
        ->and(User::query()->where('email', 'test@example.com')->exists())->toBeFalse()
        ->and(User::query()->where('email', LocalDemoWorkspaceSeeder::OWNER_A_EMAIL)->exists())->toBeFalse()
        ->and(User::query()->where('email', 'demo.local@example.com')->exists())->toBeFalse()
        ->and(Company::query()->count())->toBe(0);
});

test('default database seeder execution order is documented by class list', function (): void {
    $source = (string) file_get_contents(database_path('seeders/DatabaseSeeder.php'));

    $expected = [
        'RbacSeeder::class',
        'IndiaGeographySeeder::class',
        'PaymentModeSeeder::class',
        'GstRateSeeder::class',
        'TdsReferenceRulesSeeder::class',
        'SubscriptionCatalogueSeeder::class',
        'InternalSuperAdminSeeder::class',
    ];

    $positions = [];
    foreach ($expected as $class) {
        $pos = strpos($source, $class);
        expect($pos)->not->toBeFalse("Missing {$class} in DatabaseSeeder");
        $positions[] = $pos;
    }

    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted);
});

test('production seeder inventory remains explicit and demo stays separate', function () {
    $seeders = collect(File::files(database_path('seeders')))
        ->map(fn ($file) => $file->getFilename())
        ->sort()
        ->values()
        ->all();

    expect($seeders)->toBe([
        'DatabaseSeeder.php',
        'GstRateSeeder.php',
        'IndiaGeographyData.php',
        'IndiaGeographySeeder.php',
        'InternalSuperAdminSeeder.php',
        'LocalDemoWorkspaceSeeder.php',
        'PaymentModeSeeder.php',
        'RbacSeeder.php',
        'SubscriptionCatalogueSeeder.php',
        'TdsReferenceRulesSeeder.php',
    ])->and(File::exists(database_path('seeders/LocalDemoUserSeeder.php')))->toBeFalse()
        ->and(File::exists(database_path('seeders/DemoDatabaseSeeder.php')))->toBeFalse();
});
