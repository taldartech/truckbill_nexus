<?php

use App\Domains\Identity\Enums\IdentityType;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InternalSuperAdminSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

beforeEach(function (): void {
    config([
        'internal_super_admin.name' => 'Test Super Admin',
        'internal_super_admin.email' => 'super.admin@testing.truckbill.local',
        'internal_super_admin.password' => 'testing-super-admin-secret',
    ]);
});

test('internal super admin seeder creates exactly one verified internal super admin', function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(InternalSuperAdminSeeder::class);

    $admins = User::query()
        ->where('identity_type', IdentityType::Internal->value)
        ->where('is_super_admin', true)
        ->whereNull('deactivated_at')
        ->get();

    expect($admins)->toHaveCount(1);

    $admin = $admins->first();
    expect($admin)->not->toBeNull()
        ->and($admin?->email)->toBe('super.admin@testing.truckbill.local')
        ->and($admin?->email_verified_at)->not->toBeNull()
        ->and($admin?->isInternal())->toBeTrue()
        ->and($admin?->isSuperAdmin())->toBeTrue()
        ->and($admin?->isActive())->toBeTrue()
        ->and(Hash::check('testing-super-admin-secret', (string) $admin?->password))->toBeTrue()
        ->and(Company::query()->count())->toBe(0);
});

test('internal super admin seeder is idempotent and preserves passwords', function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(InternalSuperAdminSeeder::class);

    $admin = User::query()->where('email', 'super.admin@testing.truckbill.local')->firstOrFail();
    $originalHash = $admin->password;

    config([
        'internal_super_admin.password' => 'different-password-should-not-apply',
    ]);

    $this->seed(InternalSuperAdminSeeder::class);
    $this->seed(InternalSuperAdminSeeder::class);

    expect(User::query()->where('is_super_admin', true)->count())->toBe(1)
        ->and($admin->fresh()?->password)->toBe($originalHash)
        ->and(Hash::check('testing-super-admin-secret', (string) $admin->fresh()?->password))->toBeTrue();
});

test('internal super admin seeder requires credentials when no super admin exists', function (): void {
    $this->seed(RbacSeeder::class);

    config([
        'internal_super_admin.email' => '',
        'internal_super_admin.password' => '',
    ]);

    expect(fn () => $this->seed(InternalSuperAdminSeeder::class))
        ->toThrow(RuntimeException::class);
});

test('internal super admin can authenticate and reaches internal dashboard', function (): void {
    $this->seed(DatabaseSeeder::class);

    $login = $this->post(route('login.store'), [
        'email' => 'super.admin@testing.truckbill.local',
        'password' => 'testing-super-admin-secret',
    ]);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    // Fortify home is /workspace; Internal identities land on the Internal dashboard.
    $this->get('/workspace')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk();
    $this->get(route('admin.crm.dashboard'))->assertOk();
});

test('default database seeder does not create demo companies or owners', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Company::query()->count())->toBe(0)
        ->and(User::query()->where('identity_type', IdentityType::Customer->value)->count())->toBe(0)
        ->and(User::query()->where('is_super_admin', true)->count())->toBe(1);
});
