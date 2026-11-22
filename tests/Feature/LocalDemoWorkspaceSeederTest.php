<?php

use App\Models\Company;
use App\Models\Enquiry;
use App\Models\EnquiryExternalReference;
use App\Models\Party;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\LocalDemoWorkspaceSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    TenantContextHolder::clear();
    Mail::fake();
    Http::fake();
});

afterEach(function () {
    TenantContextHolder::clear();
    config([
        'local_demo.password' => null,
        'local_demo.profile' => 'standard',
    ]);
});

function demoPassword(): string
{
    return 'demo-test-password-not-for-production';
}

function runLocalDemoSeeder(string $profile = 'small'): void
{
    config([
        'local_demo.password' => demoPassword(),
        'local_demo.profile' => $profile,
    ]);

    (new LocalDemoWorkspaceSeeder)->run();
}

test('local demo seeder refuses production environment', function () {
    App::detectEnvironment(fn () => 'production');

    config([
        'local_demo.password' => demoPassword(),
        'local_demo.profile' => 'small',
    ]);

    expect(fn () => (new LocalDemoWorkspaceSeeder)->run())
        ->toThrow(RuntimeException::class, 'refused');
})->after(function () {
    App::detectEnvironment(fn () => 'testing');
});

test('local demo seeder requires password env', function () {
    config([
        'local_demo.password' => '',
        'local_demo.profile' => 'small',
    ]);

    expect(fn () => (new LocalDemoWorkspaceSeeder)->run())
        ->toThrow(RuntimeException::class, 'LOCAL_DEMO_PASSWORD');
});

test('local demo seeder creates isolated companies roles masters and enquiries idempotently', function () {
    runLocalDemoSeeder('small');

    $ownerA = User::query()->where('email', LocalDemoWorkspaceSeeder::OWNER_A_EMAIL)->firstOrFail();
    $ownerB = User::query()->where('email', LocalDemoWorkspaceSeeder::OWNER_B_EMAIL)->firstOrFail();
    $companyA = Company::query()->where('owner_user_id', $ownerA->id)->where('name', LocalDemoWorkspaceSeeder::COMPANY_A_NAME)->firstOrFail();
    $companyB = Company::query()->where('owner_user_id', $ownerB->id)->where('name', LocalDemoWorkspaceSeeder::COMPANY_B_NAME)->firstOrFail();

    $companyC = Company::query()->where('owner_user_id', $ownerA->id)->where('name', LocalDemoWorkspaceSeeder::COMPANY_C_NAME)->firstOrFail();
    $companyD = Company::query()->where('owner_user_id', $ownerA->id)->where('name', LocalDemoWorkspaceSeeder::COMPANY_D_NAME)->firstOrFail();

    expect($companyA->id)->not->toBe($companyB->id)
        ->and($companyA->branches()->whereNull('deactivated_at')->count())->toBeGreaterThanOrEqual(2)
        ->and($companyB->branches()->whereNull('deactivated_at')->count())->toBeGreaterThanOrEqual(2)
        ->and($companyC->branches()->whereNull('deactivated_at')->count())->toBe(1)
        ->and($companyD->deactivated_at)->not->toBeNull()
        ->and(Company::query()->where('owner_user_id', $ownerA->id)->count())->toBeGreaterThanOrEqual(3);

    expect(User::query()->where('email', LocalDemoWorkspaceSeeder::MANAGER_A_EMAIL)->exists())->toBeTrue()
        ->and(User::query()->where('email', LocalDemoWorkspaceSeeder::NO_ACCESS_EMAIL)->exists())->toBeTrue();

    expect(Hash::check(demoPassword(), $ownerA->password))->toBeTrue();

    $login = $this->post(route('login.store'), [
        'email' => LocalDemoWorkspaceSeeder::OWNER_A_EMAIL,
        'password' => demoPassword(),
    ]);
    $login->assertRedirect();

    $partyAIds = Party::query()->where('company_id', $companyA->id)->pluck('id');
    $partyBIds = Party::query()->where('company_id', $companyB->id)->pluck('id');
    expect($partyAIds->intersect($partyBIds)->isEmpty())->toBeTrue();

    $enquiriesA = Enquiry::query()->where('company_id', $companyA->id)->whereNull('deleted_at')->get();
    $enquiriesB = Enquiry::query()->where('company_id', $companyB->id)->whereNull('deleted_at')->get();

    expect($enquiriesA)->not->toBeEmpty()
        ->and($enquiriesB)->not->toBeEmpty()
        ->and($enquiriesA->pluck('enquiry_number')->unique()->count())->toBe($enquiriesA->count())
        ->and($enquiriesA->every(fn (Enquiry $e) => $e->goodsRows()->whereNull('deleted_at')->count() >= 1))->toBeTrue()
        ->and($enquiriesA->contains(fn (Enquiry $e) => $e->branch_id === null && $e->source->value === 'website'))->toBeTrue()
        ->and($enquiriesA->contains(fn (Enquiry $e) => $e->status->value === 'converted'))->toBeFalse();

    expect(EnquiryExternalReference::query()->where('company_id', $companyA->id)->count())->toBeGreaterThanOrEqual(1);

    $managerRole = Role::query()->where('slug', LocalDemoWorkspaceSeeder::ROLE_MANAGER)->firstOrFail();
    $codes = RolePermission::query()
        ->where('role_id', $managerRole->id)
        ->whereNull('deactivated_at')
        ->with('permission')
        ->get()
        ->pluck('permission.code');

    expect($codes)->toContain('enquiry.view', 'enquiry.create', 'enquiry.edit')
        ->and($codes)->not->toContain('enquiry.delete', 'lr.number.adjust');

    $defaultAdmin = Role::query()->where('slug', 'customer.admin')->firstOrFail();
    $adminEnquiryGrants = RolePermission::query()
        ->where('role_id', $defaultAdmin->id)
        ->whereNull('deactivated_at')
        ->whereHas('permission', fn ($q) => $q->where('code', 'enquiry.create'))
        ->count();
    expect($adminEnquiryGrants)->toBe(0);

    $companyACount = Company::query()->where('name', LocalDemoWorkspaceSeeder::COMPANY_A_NAME)->count();
    $enquiryACount = Enquiry::query()->where('company_id', $companyA->id)->whereNull('deleted_at')->count();
    $userACount = User::query()->where('email', LocalDemoWorkspaceSeeder::OWNER_A_EMAIL)->count();

    runLocalDemoSeeder('small');

    expect(Company::query()->where('name', LocalDemoWorkspaceSeeder::COMPANY_A_NAME)->count())->toBe($companyACount)
        ->and(Enquiry::query()->where('company_id', $companyA->id)->whereNull('deleted_at')->count())->toBe($enquiryACount)
        ->and(User::query()->where('email', LocalDemoWorkspaceSeeder::OWNER_A_EMAIL)->count())->toBe($userACount);

    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('database seeder does not invoke local demo workspace seeder', function () {
    $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

    expect($source)->toContain('RbacSeeder')
        ->and($source)->not->toContain('$this->call(LocalDemoWorkspaceSeeder')
        ->and($source)->not->toContain("call('LocalDemoWorkspaceSeeder");

    (new RbacSeeder)->run();

    expect(User::query()->where('email', LocalDemoWorkspaceSeeder::OWNER_A_EMAIL)->exists())->toBeFalse();
});
