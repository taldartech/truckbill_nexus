<?php

use App\Domains\Company\CompanyAccessService;
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

function panelWorkspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

function createOwnerWorkspace(string $name = 'Acme Logistics'): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = panelWorkspace()->createCompany($owner, $name);
    $headOffice = $company->branches()->where('name', 'Head Office')->firstOrFail();

    return compact('owner', 'company', 'headOffice');
}

/**
 * Fortify login so Customer single-session registry matches the test session.
 */
function loginCustomer(User $user, string $password = 'owner-secret'): TestResponse
{
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

test('customer with one owned company lands on global companies list', function () {
    ['owner' => $owner, 'company' => $company] = createOwnerWorkspace();

    loginCustomer($owner)->assertRedirect('/workspace');

    $this->get(route('workspace.landing'))
        ->assertRedirect(route('owner.companies.index'));

    $this->get(route('owner.companies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/companies/index')
            ->where('companies.data.0.id', $company->id));

    $this->get(route('owner.dashboard', ['company' => $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/dashboard')
            ->where('company.id', $company->id)
            ->where('ownerPanelScope', CompanyAccessService::OWNER_PANEL_FULL));
});

test('customer with multiple owned companies lands on global companies list', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $companyA = panelWorkspace()->createCompany($owner, 'Company A');
    $companyB = panelWorkspace()->createCompany($owner, 'Company B');

    loginCustomer($owner);

    $this->get(route('workspace.landing'))
        ->assertRedirect(route('owner.companies.index'));

    $this->post(route('workspace.companies.enter', ['company' => $companyB]))
        ->assertRedirect(route('owner.dashboard', ['company' => $companyB]));

    expect($owner->fresh()->mru_owned_company_id)->toBe($companyB->id);

    $this->get(route('workspace.landing'))
        ->assertRedirect(route('owner.companies.index'));

    expect($companyA->isOwnedBy($owner))->toBeTrue();
});

test('member-only customer lands on branch panel for sole assignment', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membership = panelWorkspace()->addMembership($company, $member, 'Join');
    panelWorkspace()->assignBranch($membership, $headOffice, 'Assign');

    loginCustomer($member, 'member-secret');

    $this->get(route('workspace.landing'))
        ->assertRedirect(route('branch.dashboard', [
            'company' => $company,
            'branch' => $headOffice,
        ]));

    $this->get(route('owner.dashboard', ['company' => $company]))
        ->assertForbidden();
});

test('customer with no accessible companies sees dedicated no-access screen', function () {
    $customer = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);

    loginCustomer($customer);

    $this->get(route('workspace.landing'))
        ->assertRedirect(route('workspace.no-access'));

    $this->get(route('workspace.no-access'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspace/no-access')
            ->where('canCreateCompany', true)
            ->where('links.createCompany', route('owner.companies.create')));
});

test('mixed owner and member access preserves owner landing and member branch path', function () {
    $user = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $owned = panelWorkspace()->createCompany($user, 'Owned Co');

    ['company' => $memberCompany, 'headOffice' => $memberBranch] = createOwnerWorkspace('Member Co');
    $membership = panelWorkspace()->addMembership($memberCompany, $user, 'Join');
    panelWorkspace()->assignBranch($membership, $memberBranch, 'Assign');

    loginCustomer($user);

    $this->get(route('workspace.landing'))
        ->assertRedirect(route('owner.companies.index'));

    $this->post(route('workspace.companies.enter', ['company' => $memberCompany]))
        ->assertRedirect(route('branch.dashboard', [
            'company' => $memberCompany,
            'branch' => $memberBranch,
        ]));
});

test('invalid company context is forbidden', function () {
    ['owner' => $owner] = createOwnerWorkspace();
    $strangerCompany = panelWorkspace()->createCompany(User::factory()->customer()->create(), 'Other');

    loginCustomer($owner);

    $this->get(route('owner.dashboard', ['company' => $strangerCompany]))
        ->assertForbidden();

    $this->post(route('workspace.companies.enter', ['company' => $strangerCompany]))
        ->assertForbidden();
});

test('invalid branch context is forbidden', function () {
    ['owner' => $owner, 'company' => $company] = createOwnerWorkspace();
    $other = panelWorkspace()->createCompany(User::factory()->customer()->create(), 'Other');
    $otherBranch = $other->branches()->firstOrFail();

    loginCustomer($owner);

    $this->get(route('branch.dashboard', [
        'company' => $company,
        'branch' => $otherBranch,
    ]))->assertNotFound();
});

test('deactivated company limits owner panel and blocks branch panel', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();
    panelWorkspace()->deactivateCompany(User::factory()->superAdmin()->create(), $company, 'Suspend ops');

    loginCustomer($owner);

    $this->get(route('owner.dashboard', ['company' => $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ownerPanelScope', CompanyAccessService::OWNER_PANEL_ADMIN_BILLING_ONLY)
            ->where('isDeactivated', true));

    $this->get(route('branch.dashboard', [
        'company' => $company,
        'branch' => $headOffice,
    ]))->assertForbidden();
});

test('client supplied tenant identifiers are rejected on workspace routes', function () {
    ['owner' => $owner, 'company' => $company] = createOwnerWorkspace();

    loginCustomer($owner);

    $this->get(route('owner.dashboard', ['company' => $company]).'?company_id='.$company->id)
        ->assertForbidden();
});

test('revoked membership denies branch panel', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membership = panelWorkspace()->addMembership($company, $member, 'Join');
    panelWorkspace()->assignBranch($membership, $headOffice, 'Assign');
    panelWorkspace()->deactivateMembership($membership, 'Revoked');

    loginCustomer($member, 'member-secret');

    $this->get(route('branch.dashboard', [
        'company' => $company,
        'branch' => $headOffice,
    ]))->assertForbidden();
});

test('subscription presentation does not invent entitlement actions', function () {
    ['owner' => $owner, 'company' => $company] = createOwnerWorkspace();

    loginCustomer($owner);

    $this->get(route('owner.subscription', ['company' => $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/overview')
            ->has('entitlement_note')
            ->where('subscription', null));
});

test('owner dashboard exposes Region B management entry points', function () {
    ['owner' => $owner, 'company' => $company] = createOwnerWorkspace();

    loginCustomer($owner);

    $this->get(route('owner.dashboard', ['company' => $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/dashboard')
            ->has('links.users')
            ->has('links.settings')
            ->has('links.audit'));

    $this->get(route('owner.users', ['company' => $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/users/index')
            ->where('canManage', true));

    $this->get(route('owner.settings', ['company' => $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/settings')
            ->where('canEditProfile', true));

    $this->get(route('owner.audit', ['company' => $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('owner/audit'));
});

test('member cannot open owner management entry points', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membership = panelWorkspace()->addMembership($company, $member, 'Join');
    panelWorkspace()->assignBranch($membership, $headOffice, 'Assign');

    loginCustomer($member, 'member-secret');

    $this->get(route('owner.users', ['company' => $company]))->assertForbidden();
    $this->get(route('owner.settings', ['company' => $company]))->assertForbidden();
    $this->get(route('owner.audit', ['company' => $company]))->assertForbidden();
});

test('branch settings remain branch-scoped', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();

    loginCustomer($owner);

    $this->get(route('branch.settings', [
        'company' => $company,
        'branch' => $headOffice,
    ]))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/settings')
            ->where('preferences.note', fn ($note) => str_contains($note, 'Branch-scoped preferences')));
});

test('unavailable modules are not exposed on branch dashboard', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();

    loginCustomer($owner);

    $this->get(route('branch.dashboard', [
        'company' => $company,
        'branch' => $headOffice,
    ]))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/dashboard')
            ->where('modules', []));
});

test('returning to owner panel clears active branch chrome after branch visit', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();

    loginCustomer($owner);

    $this->get(route('branch.dashboard', [
        'company' => $company,
        'branch' => $headOffice,
    ]))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.active_branch_id', $headOffice->id));

    $this->get(route('owner.dashboard', ['company' => $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/dashboard')
            ->where('workspace.active_company_id', $company->id)
            ->where('workspace.active_branch_id', null));
});

test('login redirects customers to workspace landing', function () {
    ['owner' => $owner] = createOwnerWorkspace();

    $this->post(route('login.store'), [
        'email' => $owner->email,
        'password' => 'owner-secret',
    ])->assertRedirect('/workspace');
});

test('internal users remain on technical dashboard from workspace landing', function () {
    $internal = User::factory()->internal()->create();

    $this->actingAs($internal)
        ->get(route('workspace.landing'))
        ->assertRedirect(route('dashboard'));
});

test('direct url to owner panel is forbidden for members', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membership = panelWorkspace()->addMembership($company, $member, 'Join');
    panelWorkspace()->assignBranch($membership, $headOffice, 'Assign');

    loginCustomer($member, 'member-secret');

    $this->get(route('owner.subscription', ['company' => $company]))
        ->assertForbidden();
});
