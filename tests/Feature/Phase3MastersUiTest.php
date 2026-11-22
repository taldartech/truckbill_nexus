<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\BankCashAccount;
use App\Models\Party;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;

function uiActingAsCustomer(User $user, string $password = 'owner-secret'): void
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }

    test()->flushSession();
    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertRedirect();
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

function uiCustomerRole(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

function uiMastersWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'UI Masters Co');
    $branch = $company->branches()->firstOrFail();

    $member = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membership = $workspace->addMembership($company, $member, 'Join');
    $memberAssignment = $workspace->assignBranch($membership, $branch, 'Assign');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $memberAssignment,
        uiCustomerRole('customer.admin'),
        'Grant admin',
    );

    $viewer = User::factory()->customer()->create(['password' => Hash::make('viewer-secret')]);
    $viewerMembership = $workspace->addMembership($company, $viewer, 'Join viewer');
    $viewerAssignment = $workspace->assignBranch($viewerMembership, $branch, 'Assign viewer');
    $rbac->assignCustomerBranchRole(
        $owner,
        $viewerMembership,
        $branch,
        $viewerAssignment,
        uiCustomerRole('customer.viewer'),
        'Grant viewer',
    );

    $otherOwner = User::factory()->customer()->create(['password' => Hash::make('password')]);
    $otherCompany = $workspace->createCompany($otherOwner, 'Other UI Co');
    $otherBranch = $otherCompany->branches()->firstOrFail();

    return compact('owner', 'company', 'branch', 'member', 'viewer', 'otherOwner', 'otherCompany', 'otherBranch');
}

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('masters hub and party inertia screens render for authorized branch users', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);

    $this->get(route('branch.masters.index', [$company, $branch]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/index')
            ->has('categories')
            ->where('permissions.can_view', true));

    $this->get(route('branch.parties.index', [$company, $branch]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('branch/masters/parties/index'));

    $this->get(route('branch.parties.create', [$company, $branch]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('branch/masters/parties/form')->where('mode', 'create'));
});

test('party create edit detail soft-delete workflow through inertia', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);

    $this->post(route('branch.parties.store', [$company, $branch]), [
        'scope' => 'branch',
        'name' => 'UI Party',
        'contact_number' => '9000011111',
        'state' => 'MH',
        'city' => 'Pune',
        'roles' => [
            ['role' => PartyRoleType::Customer->value, 'attributes' => []],
            ['role' => PartyRoleType::Consignor->value, 'attributes' => []],
        ],
        'remark' => 'Create via UI',
    ])->assertRedirect();

    $party = Party::query()->where('company_id', $company->id)->where('name', 'UI Party')->firstOrFail();

    $this->get(route('branch.parties.show', [$company, $branch, $party]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/show')
            ->where('party.name', 'UI Party')
            ->has('party.roles', 2));

    $this->patch(route('branch.parties.update', [$company, $branch, $party]), [
        'name' => 'UI Party Updated',
        'remark' => 'Rename party',
    ])->assertRedirect(route('branch.parties.show', [$company, $branch, $party]));

    expect($party->fresh()->name)->toBe('UI Party Updated');

    $this->delete(route('branch.parties.destroy', [$company, $branch, $party]), [
        'remark' => 'Soft delete party',
    ])->assertRedirect(route('branch.parties.index', [$company, $branch]));

    expect($party->fresh()->trashed())->toBeTrue();
});

test('viewer cannot create parties and cross-company party urls are isolated', function () {
    [
        'company' => $company,
        'branch' => $branch,
        'viewer' => $viewer,
        'otherOwner' => $otherOwner,
        'otherCompany' => $otherCompany,
        'otherBranch' => $otherBranch,
        'owner' => $owner,
    ] = uiMastersWorkspace();

    uiActingAsCustomer($viewer, 'viewer-secret');
    $this->get(route('branch.parties.create', [$company, $branch]))->assertForbidden();
    $this->post(route('branch.parties.store', [$company, $branch]), [
        'scope' => 'branch',
        'name' => 'Denied',
        'roles' => [['role' => 'customer', 'attributes' => []]],
    ])->assertForbidden();

    uiActingAsCustomer($owner);
    $this->post(route('branch.parties.store', [$company, $branch]), [
        'scope' => 'branch',
        'name' => 'Owned Party',
        'roles' => [['role' => 'customer', 'attributes' => []]],
        'remark' => 'Create',
    ])->assertRedirect();

    $party = Party::query()->where('name', 'Owned Party')->firstOrFail();

    uiActingAsCustomer($otherOwner, 'password');
    $this->get(route('branch.parties.show', [$otherCompany, $otherBranch, $party]))->assertNotFound();
});

test('vehicle and bank cash inertia crud masks account numbers', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);

    $this->post(route('branch.vehicles.store', [$company, $branch]), [
        'scope' => 'shared',
        'registration_number' => 'MH12AB1234',
        'make' => 'Tata',
        'model' => '407',
        'remark' => 'Create vehicle',
    ])->assertRedirect();

    $vehicle = Vehicle::query()->where('registration_number', 'MH12AB1234')->firstOrFail();
    $this->get(route('branch.vehicles.show', [$company, $branch, $vehicle]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('branch/masters/vehicles/show')->where('vehicle.registration_number', 'MH12AB1234'));

    $this->post(route('branch.bank-cash.store', [$company, $branch]), [
        'scope' => 'branch',
        'account_kind' => 'bank',
        'name' => 'Ops Bank',
        'bank_name' => 'HDFC',
        'account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
        'account_holder_name' => 'Ops',
        'remark' => 'Create bank',
    ])->assertRedirect();

    $account = BankCashAccount::query()->where('name', 'Ops Bank')->firstOrFail();

    $this->get(route('branch.bank-cash.show', [$company, $branch, $account]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/bank-cash/show')
            ->where('account.account_number_masked', fn ($value) => is_string($value) && ! str_contains($value, '123456789012'))
            ->missing('account.account_number'));

    $this->get(route('branch.bank-cash.edit', [$company, $branch, $account]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('account.account_number'));
});

test('station create is owner-only while members can view directory', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch, 'member' => $member] = uiMastersWorkspace();

    uiActingAsCustomer($member, 'member-secret');
    $this->get(route('branch.stations.index', [$company, $branch]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('branch/masters/stations/index')->where('canManageOwnerStations', false));
    $this->get(route('branch.stations.create', [$company, $branch]))->assertForbidden();

    uiActingAsCustomer($owner);
    $this->post(route('branch.stations.store', [$company, $branch]), [
        'code' => 'PUN',
        'name' => 'Pune',
        'city' => 'Pune',
        'remark' => 'Create station',
    ])->assertRedirect();
});

test('supporting catalogues page lists payment modes read-only', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);

    $this->get(route('branch.catalogues.index', [$company, $branch]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/catalogues/index')
            ->has('paymentModes')
            ->where('canManageOwnerCatalogues', true));
});
