<?php

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use App\Support\Workspace\WorkspaceSession;
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

function panelCustomerRole(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

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

test('customer with one owned company lands on global owner dashboard', function () {
    ['owner' => $owner, 'company' => $company] = createOwnerWorkspace();

    loginCustomer($owner)->assertRedirect(route('owner.home'));

    $this->get(route('workspace.landing'))
        ->assertRedirect(route('owner.home'));

    $this->get(route('owner.home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/home')
            ->where('companies.0.id', $company->id));

    enterManagementCompany($company)->assertRedirect();

    $this->get(route('owner.settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/settings')
            ->where('canEditProfile', true)
            ->where('ownerPanelScope', CompanyAccessService::OWNER_PANEL_FULL));
});

test('customer with multiple owned companies lands on global owner dashboard', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $companyA = panelWorkspace()->createCompany($owner, 'Company A');
    $companyB = panelWorkspace()->createCompany($owner, 'Company B');

    loginCustomer($owner);

    $this->get(route('workspace.landing'))
        ->assertRedirect(route('owner.home'));

    $this->post(route('workspace.companies.enter', ['company' => $companyB]))
        ->assertRedirect(route('owner.home'));

    expect($owner->fresh()->mru_owned_company_id)->toBe($companyB->id);

    $this->get(route('workspace.landing'))
        ->assertRedirect(route('owner.home'));

    expect($companyA->isOwnedBy($owner))->toBeTrue();
});

test('member-only customer lands on branch panel for sole assignment', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membership = panelWorkspace()->addMembership($company, $member, 'Join');
    $assignment = panelWorkspace()->assignBranch($membership, $headOffice, 'Assign');
    app(RoleAssignmentService::class)->assignCustomerBranchRole(
        $owner,
        $membership,
        $headOffice,
        $assignment,
        panelCustomerRole('customer.viewer'),
        'Grant viewer for branch panel',
    );

    loginCustomer($member, 'member-secret');

    $landing = $this->get(route('workspace.landing'));
    $landing->assertRedirect();
    expect(parse_url($landing->headers->get('Location'), PHP_URL_PATH))->toBe('/operations');

    enterBranchWorkspace($company, $headOffice)->assertRedirect();

    $this->get(route('branch.dashboard'))->assertOk();

    $this->get(route('owner.home'))
        ->assertForbidden();
});

test('branch assignment without role cannot view branch operations dashboard', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membership = panelWorkspace()->addMembership($company, $member, 'Join');
    panelWorkspace()->assignBranch($membership, $headOffice, 'Assign');

    loginCustomer($member, 'member-secret');

    $this->get(route('workspace.landing'))
        ->assertRedirect(route('workspace.no-access'));

    enterBranchWorkspace($company, $headOffice)->assertRedirect();

    $this->get(route('branch.dashboard'))->assertForbidden();
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
        ->assertRedirect(route('owner.home'));

    $this->post(route('workspace.companies.enter', ['company' => $memberCompany]))
        ->assertRedirect(route('branch.dashboard'));
});

test('invalid company context is forbidden', function () {
    ['owner' => $owner] = createOwnerWorkspace();
    $strangerCompany = panelWorkspace()->createCompany(User::factory()->customer()->create(), 'Other');

    loginCustomer($owner);

    $this->get(route('owner.home'))->assertOk();

    $this->post(route('workspace.companies.enter', ['company' => $strangerCompany]))
        ->assertForbidden();

    $this->post(route('workspace.context.company', ['company' => $strangerCompany]))
        ->assertForbidden();
});

test('invalid branch context is forbidden', function () {
    ['owner' => $owner, 'company' => $company] = createOwnerWorkspace();
    $other = panelWorkspace()->createCompany(User::factory()->customer()->create(), 'Other');
    $otherBranch = $other->branches()->firstOrFail();

    loginCustomer($owner);

    $this->post(route('workspace.context.branch', [
        'company' => $other,
        'branch' => $otherBranch,
    ]))->assertForbidden();
});

test('deactivated company limits owner panel and blocks branch panel', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();
    panelWorkspace()->deactivateCompany(User::factory()->superAdmin()->create(), $company, 'Suspend ops');

    loginCustomer($owner);

    enterManagementCompany($company)->assertRedirect();

    $this->get(route('owner.settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ownerPanelScope', CompanyAccessService::OWNER_PANEL_ADMIN_BILLING_ONLY));

    $this->post(route('workspace.context.branch', [
        'company' => $company,
        'branch' => $headOffice,
    ]))->assertForbidden();

    $this->get(route('branch.dashboard'))->assertRedirect(route('workspace.landing'));
});

test('client supplied tenant identifiers are rejected on workspace routes', function () {
    ['owner' => $owner, 'company' => $company] = createOwnerWorkspace();

    loginCustomer($owner);

    $this->get(route('owner.home').'?company_id='.$company->id)
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

    $this->get(route('branch.dashboard'))->assertRedirect(route('workspace.landing'));
});

test('subscription presentation does not invent entitlement actions', function () {
    ['owner' => $owner, 'company' => $company] = createOwnerWorkspace();

    loginCustomer($owner);
    enterManagementCompany($company)->assertRedirect();

    $this->get(route('owner.subscription'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/overview')
            ->has('entitlement_note')
            ->where('subscription', null));
});

test('owner dashboard exposes Region B management entry points', function () {
    ['owner' => $owner, 'company' => $company] = createOwnerWorkspace();

    loginCustomer($owner);
    enterManagementCompany($company)->assertRedirect();

    $this->get(route('owner.users'))
        ->assertRedirect(route('owner.users.all', ['company' => $company->id]));

    $this->get(route('owner.settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/settings')
            ->where('canEditProfile', true));

    $this->get(route('owner.audit'))
        ->assertRedirect(route('owner.audit.all', ['company' => $company->id]));
});

test('member cannot open owner management entry points', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membership = panelWorkspace()->addMembership($company, $member, 'Join');
    panelWorkspace()->assignBranch($membership, $headOffice, 'Assign');

    loginCustomer($member, 'member-secret');

    $this->get(route('owner.users'))->assertForbidden();
    $this->get(route('owner.settings'))->assertForbidden();
    $this->get(route('owner.audit'))->assertForbidden();
});

test('branch settings remain branch-scoped', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();

    loginCustomer($owner);
    enterBranchWorkspace($company, $headOffice)->assertRedirect();

    $this->get(route('branch.settings'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/settings')
            ->where('preferences.note', fn ($note) => str_contains($note, 'Branch-scoped preferences')));
});

test('unavailable modules are not exposed on branch dashboard', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();

    loginCustomer($owner);
    enterBranchWorkspace($company, $headOffice)->assertRedirect();

    $this->get(route('branch.dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/dashboard')
            ->where('modules', []));
});

test('returning to owner panel clears active branch chrome after branch visit', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnerWorkspace();

    loginCustomer($owner);
    enterBranchWorkspace($company, $headOffice)->assertRedirect();

    $this->get(route('branch.dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.active_branch_id', $headOffice->id));

    // Explicit leave clears company + branch; Owner Panel is session-independent.
    $this->post(route('workspace.context.leave-branch'))
        ->assertRedirect(route('owner.home'));

    expect(session(WorkspaceSession::BRANCH_KEY))->toBeNull()
        ->and(session(WorkspaceSession::COMPANY_KEY))->toBeNull();

    $this->get(route('owner.home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/home')
            ->where('workspace.active_branch_id', null)
            ->where('workspace.active_company_id', null));
});

test('login redirects customers to workspace landing', function () {
    ['owner' => $owner] = createOwnerWorkspace();

    $this->post(route('login.store'), [
        'email' => $owner->email,
        'password' => 'owner-secret',
    ])->assertRedirect(route('owner.home'));
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

    $this->get(route('owner.subscription'))
        ->assertForbidden();
});
