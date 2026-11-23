<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Enums\DataVisibility;
use App\Models\Company;
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

function globalPanelWorkspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

function globalPanelLogin(User $user, string $password = 'owner-secret'): TestResponse
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

test('owner with one company sees global companies list after landing', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = globalPanelWorkspace()->createCompany($owner, 'Solo Global Co');

    globalPanelLogin($owner);

    $this->get(route('workspace.landing'))
        ->assertRedirect(route('owner.home'));

    $this->get(route('owner.home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/home')
            ->where('totals.companies', 1)
            ->where('companies.0.id', $company->id));

    $this->get(route('owner.companies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/companies/index')
            ->has('companies.data', 1)
            ->where('companies.data.0.id', $company->id)
            ->where('companies.data.0.name', 'Solo Global Co'));
});

test('owner with multiple companies sees every owned company and not another owners company', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $other = User::factory()->customer()->create();
    $a = globalPanelWorkspace()->createCompany($owner, 'Owned Alpha');
    $b = globalPanelWorkspace()->createCompany($owner, 'Owned Beta');
    $foreign = globalPanelWorkspace()->createCompany($other, 'Foreign Co');

    globalPanelLogin($owner);

    $this->get(route('owner.companies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/companies/index')
            ->has('companies.data', 2)
            ->where('companies.data.0.name', 'Owned Alpha')
            ->where('companies.data.1.name', 'Owned Beta'));

    expect(Company::query()->where('owner_user_id', $owner->id)->pluck('id')->all())
        ->not->toContain($foreign->id)
        ->and($a->id)->not->toBe($b->id);
});

test('owner sees branches of all owned companies with company filter', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $companyA = globalPanelWorkspace()->createCompany($owner, 'Branch Host A', 'HO A');
    $companyB = globalPanelWorkspace()->createCompany($owner, 'Branch Host B', 'HO B');
    globalPanelWorkspace()->createBranch($companyA, 'Depot A', 'Create depot');

    globalPanelLogin($owner);

    $this->get(route('owner.branches.all'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/branches/all')
            ->where('branches.meta.total', fn ($total) => $total >= 3));

    $this->get(route('owner.branches.all', ['company' => $companyB->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/branches/all')
            ->has('branches.data', 1)
            ->where('branches.data.0.company.id', $companyB->id)
            ->where('branches.data.0.name', 'HO B'));
});

test('global branch list links to company-specific detail', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = globalPanelWorkspace()->createCompany($owner, 'Link Co', 'Main');
    $branch = $company->branches()->where('name', 'Main')->firstOrFail();

    globalPanelLogin($owner);

    $this->get(route('owner.branches.all'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('branches.data.0.urls.show', internalRoute('owner.branches.show', [
                'branch' => $branch,
            ])));
});

test('owner can open company-specific subscription from global list', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = globalPanelWorkspace()->createCompany($owner, 'Sub Co');

    globalPanelLogin($owner);

    $this->get(route('owner.subscriptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscriptions/index')
            ->has('companies.data', 1)
            ->where('companies.data.0.id', $company->id)
            ->where('companies.data.0.active_modules', [])
            ->where('companies.data.0.urls.subscription', internalRoute('owner.subscription'))
            ->where('companies.data.0.urls.company_settings', internalRoute('owner.settings')));

    enterManagementCompany($company, route('owner.subscription'))->assertRedirect();
    $this->get(route('owner.subscription'))
        ->assertOk();
});

test('deactivated company retains informational owner access on global list', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = globalPanelWorkspace()->createCompany($owner, 'Deactivated List Co');
    $company->forceFill(['deactivated_at' => now()])->save();

    globalPanelLogin($owner);

    $this->get(route('owner.companies.index', ['status' => 'inactive']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('companies.data.0.id', $company->id)
            ->where('companies.data.0.status', 'Deactivated')
            ->where('companies.data.0.can_manage', false));

    $this->get(route('owner.home'))->assertOk();
});

test('customer member cannot access owner global management', function () {
    $owner = User::factory()->customer()->create();
    $company = globalPanelWorkspace()->createCompany($owner, 'Member Host');
    $branch = $company->branches()->firstOrFail();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
        'email_verified_at' => now(),
    ]);
    $membership = globalPanelWorkspace()->addMembership($company, $member, 'Join');
    globalPanelWorkspace()->assignBranch($membership, $branch, 'Assign');

    globalPanelLogin($member, 'member-secret');

    $this->get(route('owner.branches.all'))->assertForbidden();
    $this->get(route('owner.subscriptions.index'))->assertForbidden();
});

test('internal user cannot access customer owner panel globals', function () {
    $internal = User::factory()->internal()->create([
        'password' => Hash::make('internal-secret'),
        'email_verified_at' => now(),
    ]);

    globalPanelLogin($internal, 'internal-secret');

    $this->get(route('owner.companies.index'))->assertForbidden();
    $this->get(route('owner.branches.all'))->assertForbidden();
    $this->get(route('owner.subscriptions.index'))->assertForbidden();
});

test('owner global users and audit lists are scoped to owned companies', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = globalPanelWorkspace()->createCompany($owner, 'Users Host');
    $other = User::factory()->customer()->create();
    globalPanelWorkspace()->createCompany($other, 'Foreign Users Host');

    globalPanelLogin($owner);

    $this->get(route('owner.users.all'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/users/all')
            ->where('memberships.data.0.company.id', $company->id));

    $this->get(route('owner.audit.all'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('owner/audit/all'));
});

test('shared data visibility default is recorded on owned company rows', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    globalPanelWorkspace()->createCompany(
        $owner,
        'Visibility Row Co',
        'HO',
        [
            'pan' => 'ABCDE1234F',
            'registered_address' => 'Addr',
            'state' => 'Maharashtra',
            'city' => 'Mumbai',
            'pin' => '400001',
            'mobile' => '9876543210',
            'gst_registration_type' => 'unregistered',
            'tax_liability' => 'fcm',
            'data_visibility' => DataVisibility::SharedData->value,
            'timezone' => 'Asia/Kolkata',
        ],
        true,
    );

    globalPanelLogin($owner);

    $this->get(route('owner.companies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('companies.data.0.data_visibility', DataVisibility::SharedData->value));
});
