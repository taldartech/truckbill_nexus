<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function lifecycleLogin(User $user, string $password = 'password'): TestResponse
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }

    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertRedirect();
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    return $response;
}

test('super admin can browse company lifecycle and deactivate with remark', function () {
    $sa = User::factory()->superAdmin()->create([
        'password' => Hash::make('sa-secret'),
    ]);
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Lifecycle UI Co');

    lifecycleLogin($sa, 'sa-secret');

    $this->get(route('admin.companies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/companies/index')
            ->has('companies.data'));

    $this->get(route('admin.companies.show', $company))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/companies/show')
            ->where('company.name', 'Lifecycle UI Co'));

    $this->post(route('admin.companies.deactivate', $company), [
        'remark' => 'Pause trading for compliance review',
        'confirm' => '1',
    ])->assertRedirect(route('admin.companies.show', $company));

    expect($company->fresh()->isDeactivated())->toBeTrue();
});

test('customer and ordinary internal cannot access company lifecycle ui', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Forbidden Co');
    $internal = User::factory()->internal()->create([
        'password' => Hash::make('internal-secret'),
        'is_super_admin' => false,
    ]);

    lifecycleLogin($owner, 'owner-secret');
    $this->get(route('admin.companies.index'))->assertForbidden();
    $this->get(route('admin.companies.show', $company))->assertForbidden();

    lifecycleLogin($internal, 'internal-secret');
    $this->get(route('admin.companies.index'))->assertForbidden();
    $this->get(route('admin.identities.index'))->assertForbidden();
    $this->get(route('admin.internal-users.index'))->assertForbidden();
});

test('super admin can manage identity and internal user screens', function () {
    $sa = User::factory()->superAdmin()->create([
        'password' => Hash::make('sa-secret'),
    ]);
    $customer = User::factory()->customer()->create([
        'password' => Hash::make('customer-secret'),
    ]);

    lifecycleLogin($sa, 'sa-secret');

    $this->get(route('admin.identities.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/identities/index'));

    $this->get(route('admin.identities.show', $customer))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/identities/show')
            ->where('identity.email', $customer->email));

    $this->post(route('admin.identities.deactivate', $customer), [
        'remark' => 'Suspected account compromise',
        'confirm' => '1',
    ])->assertRedirect(route('admin.identities.show', $customer));

    expect($customer->fresh()->isDeactivated())->toBeTrue();

    $this->get(route('admin.internal-users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/internal-users/index'));

    $this->post(route('admin.internal-users.store'), [
        'name' => 'Ops Analyst',
        'email' => 'ops.analyst@example.com',
        'password' => 'Ops-secret-123!',
        'password_confirmation' => 'Ops-secret-123!',
    ])->assertRedirect(route('admin.internal-users.index'));

    expect(User::query()->where('email', 'ops.analyst@example.com')->exists())->toBeTrue();
});

test('super admin can transfer ownership from company lifecycle ui', function () {
    $sa = User::factory()->superAdmin()->create([
        'password' => Hash::make('sa-secret'),
    ]);
    $owner = User::factory()->customer()->create();
    $newOwner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Transfer UI Co');

    lifecycleLogin($sa, 'sa-secret');

    $this->post(route('admin.companies.ownership-transfers.store', $company), [
        'target_user_id' => $newOwner->id,
        'remark' => 'Owner requested transfer',
        'confirm' => '1',
    ])->assertRedirect(route('admin.companies.show', $company));

    expect($company->fresh()->owner_user_id)->toBe($newOwner->id);
});
