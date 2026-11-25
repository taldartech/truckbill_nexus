<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Company;
use App\Models\CompanySubscriptionTrial;
use App\Models\GstRate;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function accessCustomerRole(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

function accessWorkspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

function accessLoginCustomer(User $user, string $password = 'owner-secret'): TestResponse
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

/**
 * @return array<string, mixed>
 */
function accessPrd7Profile(array $overrides = []): array
{
    $rate = GstRate::query()->firstOrCreate(
        ['rate_percent' => '18.000', 'version' => 1],
        [
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
        ],
    );

    $geography = geographyIdsFor('Maharashtra', 'Mumbai');

    return array_merge([
        'pan' => 'ABCDE1234F',
        'registered_address' => '12 Logistics Park, Mumbai',
        'state_id' => $geography['state_id'],
        'city_id' => $geography['city_id'],
        'state' => $geography['state'],
        'city' => $geography['city'],
        'pin' => '400001',
        'contact_person' => 'Owner Name',
        'mobile' => '9876543210',
        'email' => 'owner@example.com',
        'gst_registration_type' => GstRegistrationType::Regular->value,
        'tax_liability' => TaxLiability::Fcm->value,
        'gstin' => '27ABCDE1234F1Z5',
        'gst_rate_id' => $rate->id,
        'data_visibility' => DataVisibility::SharedData->value,
        'timezone' => 'Asia/Kolkata',
    ], $overrides);
}

test('active customer can open company create', function () {
    $customer = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);

    accessLoginCustomer($customer);

    $this->get(route('owner.companies.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/companies/create')
            ->has('links.store')
            ->where('links.store', route('owner.companies.store')));
});

test('existing owner can create an additional company', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $first = accessWorkspace()->createCompany(
        $owner,
        'First Co',
        'HO',
        accessPrd7Profile(['email' => $owner->email, 'contact_person' => $owner->name]),
        true,
    );

    accessLoginCustomer($owner);

    $this->post(route('owner.companies.store'), array_merge(accessPrd7Profile([
        'email' => $owner->email,
        'contact_person' => $owner->name,
        'pan' => 'ZZZZZ9999Z',
        'gstin' => '27ZZZZZ9999Z1Z5',
    ]), [
        'name' => 'Second Owned Co',
        'create_initial_branch' => true,
        'initial_branch_name' => 'Main',
    ]))->assertRedirect(route('owner.home'));

    $second = Company::query()->where('name', 'Second Owned Co')->firstOrFail();
    expect($second->owner_user_id)->toBe($owner->id)
        ->and($second->id)->not->toBe($first->id)
        ->and($second->branches()->where('name', 'Main')->exists())->toBeTrue()
        ->and(CompanySubscriptionTrial::query()->where('owner_user_id', $owner->id)->count())->toBe(1)
        ->and(CompanySubscriptionTrial::query()->where('company_id', $first->id)->exists())->toBeTrue()
        ->and(CompanySubscriptionTrial::query()->where('company_id', $second->id)->exists())->toBeFalse();
});

test('member-only customer can access the approved creation entry', function () {
    $owner = User::factory()->customer()->create();
    $company = accessWorkspace()->createCompany($owner, 'Member Host');
    $branch = $company->branches()->firstOrFail();

    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
        'email_verified_at' => now(),
    ]);
    $membership = accessWorkspace()->addMembership($company, $member, 'Join');
    $assignment = accessWorkspace()->assignBranch($membership, $branch, 'Assign');
    app(RoleAssignmentService::class)->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $assignment,
        accessCustomerRole('customer.viewer'),
        'Grant viewer for branch panel',
    );

    accessLoginCustomer($member, 'member-secret');

    $this->get(route('owner.companies.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('owner/companies/create'));

    // Member cannot open Owner Panel for a company they do not own, but shared
    // workspace props still expose Create Company for eligible Customers.
    $this->get(route('owner.home'))->assertForbidden();

    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.can_create_company', true)
            ->where('workspace.create_company_url', route('owner.companies.create')));
});

test('customer with no accessible companies receives a create company action', function () {
    $customer = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);

    accessLoginCustomer($customer);

    $this->get(route('workspace.no-access'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspace/no-access')
            ->where('canCreateCompany', true)
            ->where('links.createCompany', route('owner.companies.create')));
});

test('internal identity cannot create companies via the customer endpoint', function () {
    $internal = User::factory()->internal()->create([
        'password' => Hash::make('internal-secret'),
        'email_verified_at' => now(),
    ]);

    accessLoginCustomer($internal, 'internal-secret');

    $this->get(route('owner.companies.create'))->assertForbidden();
    $this->post(route('owner.companies.store'), array_merge(accessPrd7Profile(), [
        'name' => 'Internal Should Fail',
    ]))->assertForbidden();

    expect(Company::query()->where('name', 'Internal Should Fail')->exists())->toBeFalse();
});

test('company selection exposes creation to eligible customers', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    accessWorkspace()->createCompany($owner, 'Select Co A');
    accessWorkspace()->createCompany($owner, 'Select Co B');

    accessLoginCustomer($owner);

    $this->get(route('workspace.companies.select'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspace/select-company')
            ->where('canCreateCompany', true)
            ->where('links.createCompany', route('owner.companies.create')));
});

test('owner dashboard exposes the correct creation url', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = accessWorkspace()->createCompany($owner, 'Dash Co');

    accessLoginCustomer($owner);

    $this->get(route('owner.home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/home')
            ->where('links.create_company', route('owner.companies.create')));
});

test('company switcher shared props expose creation to eligible customers', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = accessWorkspace()->createCompany($owner, 'Switcher Co');

    accessLoginCustomer($owner);

    $this->get(route('owner.home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.can_create_company', true)
            ->where('workspace.create_company_url', route('owner.companies.create'))
            ->has('workspace.companies', 1));
});

test('company is persisted with approved fields owner designation optional branch and trial', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'name' => 'Trial Owner',
        'email' => 'trial.owner@example.com',
        'email_verified_at' => now(),
    ]);

    accessLoginCustomer($owner);

    $profile = accessPrd7Profile([
        'email' => $owner->email,
        'contact_person' => $owner->name,
        'bank_name' => 'HDFC Bank',
        'account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
    ]);

    $this->post(route('owner.companies.store'), array_merge($profile, [
        'name' => 'Persisted Logistics',
        'create_initial_branch' => true,
        'initial_branch_name' => 'Head Office',
    ]))->assertRedirect();

    $company = Company::query()->where('name', 'Persisted Logistics')->firstOrFail();

    expect($company->owner_user_id)->toBe($owner->id)
        ->and($company->pan)->toBe('ABCDE1234F')
        ->and($company->gstin)->toBe('27ABCDE1234F1Z5')
        ->and($company->data_visibility)->toBe(DataVisibility::SharedData)
        ->and($company->timezone)->toBe('Asia/Kolkata')
        ->and($company->branches()->where('name', 'Head Office')->exists())->toBeTrue()
        ->and(CompanySubscriptionTrial::query()->where('company_id', $company->id)->where('owner_user_id', $owner->id)->exists())->toBeTrue();

    accessLoginCustomer($owner);

    $this->get(route('workspace.companies.select'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspace/select-company')
            ->has('companies')
            ->where('companies.0.id', $company->id)
            ->where('companies.0.name', 'Persisted Logistics'));
});

test('invalid submission cannot create partial records', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);

    accessLoginCustomer($owner);

    $before = Company::query()->count();

    $this->from(route('owner.companies.create'))
        ->post(route('owner.companies.store'), [
            'name' => 'Partial Fail Co',
            'pan' => 'BAD',
            'registered_address' => 'Somewhere',
            'state' => 'Maharashtra',
            'city' => 'Mumbai',
            'pin' => '400001',
            'mobile' => '9876543210',
            'gst_registration_type' => GstRegistrationType::Regular->value,
            'tax_liability' => TaxLiability::Fcm->value,
            'data_visibility' => DataVisibility::SharedData->value,
        ])
        ->assertRedirect(route('owner.companies.create'))
        ->assertSessionHasErrors();

    expect(Company::query()->count())->toBe($before)
        ->and(Company::query()->where('name', 'Partial Fail Co')->exists())->toBeFalse();
});

test('cross-company authorization remains secure for owner panel', function () {
    $ownerA = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $ownerB = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $companyA = accessWorkspace()->createCompany($ownerA, 'Alpha Secure');
    $companyB = accessWorkspace()->createCompany($ownerB, 'Beta Secure');

    accessLoginCustomer($ownerA);

    $this->get(route('owner.home'))->assertOk();

    $this->post(route('workspace.context.company', ['company' => $companyB]))->assertForbidden();

    $this->get(route('owner.settings'))->assertRedirect(route('owner.companies.index'));

    enterManagementCompany($companyA)->assertRedirect();

    $this->get(route('owner.settings'))->assertOk();

    expect($companyA->isOwnedBy($ownerA))->toBeTrue()
        ->and($companyB->isOwnedBy($ownerA))->toBeFalse();
});
