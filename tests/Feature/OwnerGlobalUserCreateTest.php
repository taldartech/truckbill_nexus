<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    TenantContextHolder::clear();
    $this->seed(RbacSeeder::class);
    $this->workspace = app(CompanyWorkspaceService::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

function loginOwnerForUserCreate(User $user, string $password = 'owner-secret'): TestResponse
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

test('owner can open global add user form with company preselect', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = $this->workspace->createCompany($owner, 'Create User Co', 'Main');

    loginOwnerForUserCreate($owner);

    $this->get(route('owner.users.create.global', ['company' => $company->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/users/create')
            ->where('preselectedCompanyId', $company->id)
            ->where('links.store', route('owner.users.store.global')));

    $this->get(route('owner.users.all'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('links.create')
            ->where('canManage', true));
});

test('owner can invite user with branch roles from global create', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = $this->workspace->createCompany($owner, 'Invite Co', 'North');
    $branch = $company->branches()->where('name', 'North')->firstOrFail();
    $role = Role::query()->where('slug', 'customer.viewer')->firstOrFail();

    loginOwnerForUserCreate($owner);

    $this->post(route('owner.users.store.global'), [
        'name' => 'Invited Member',
        'email' => 'invited-member@example.test',
        'companies' => [
            [
                'company_id' => $company->id,
                'assignments' => [
                    [
                        'branch_id' => $branch->id,
                        'role_id' => $role->id,
                    ],
                ],
            ],
        ],
    ])->assertRedirect(route('owner.users.all', ['company' => $company->id]));

    $invitation = Invitation::query()
        ->where('company_id', $company->id)
        ->where('email', 'invited-member@example.test')
        ->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->invitee_name)->toBe('Invited Member')
        ->and($invitation->branchAccessIntents)->toHaveCount(1);
});

test('company users listing redirects to global register with company filter', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = $this->workspace->createCompany($owner, 'Redirect Users Co', 'Main');

    loginOwnerForUserCreate($owner);
    enterManagementCompany($company)->assertRedirect();

    $this->get(route('owner.users'))
        ->assertRedirect(route('owner.users.all', ['company' => $company->id]));
});

test('company row action urls use filtered global registers without session', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = $this->workspace->createCompany($owner, 'Action URL Co', 'Main');

    loginOwnerForUserCreate($owner);

    $this->get(route('owner.companies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('companies.data.0.urls.users', internalRoute('owner.users.all', ['company' => $company->id]))
            ->where('companies.data.0.urls.branches', internalRoute('owner.branches.all', ['company' => $company->id]))
            ->where('companies.data.0.urls.audit', internalRoute('owner.audit.all', ['company' => $company->id]))
            ->where('companies.data.0.urls.subscription', internalRoute('owner.subscriptions.index', ['company' => $company->id])));
});
