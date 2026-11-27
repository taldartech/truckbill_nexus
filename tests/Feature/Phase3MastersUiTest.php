<?php

require_once __DIR__.'/../Support/VehicleMasterFixtures.php';

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\BankCashAccount;
use App\Models\Party;
use App\Models\Role;
use App\Models\State;
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

test('masters hub redirects to party master and party screens render', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->get(route('branch.masters.index'))
        ->assertRedirect(route('branch.parties.index'));

    $this->get(route('branch.parties.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/index')
            ->has('dataVisibility.label')
            ->has('dataVisibility.message'));

    $this->get(route('branch.parties.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('branch/masters/parties/form')->where('mode', 'create'));
});

test('party create edit detail soft-delete workflow through inertia', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->post(route('branch.parties.store'), [
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

    $this->get(route('branch.parties.show', $party))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/show')
            ->where('party.name', 'UI Party')
            ->has('party.roles', 2));

    $this->patch(route('branch.parties.update', $party), [
        'name' => 'UI Party Updated',
        'remark' => 'Rename party',
    ])->assertRedirect(route('branch.parties.show', $party));

    expect($party->fresh()->name)->toBe('UI Party Updated');

    $this->delete(route('branch.parties.destroy', $party), [
        'remark' => 'Soft delete party',
    ])->assertRedirect(route('branch.parties.index'));

    expect($party->fresh()->trashed())->toBeTrue();
});

test('party create treats unspecified gst applicability as the role default', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->post(route('branch.parties.store'), [
        'name' => 'Unspecified GST Party',
        'contact_number' => '9000055555',
        'state' => 'MH',
        'city' => 'Pune',
        'is_gst_applicable' => '',
        'roles' => [['role' => PartyRoleType::Customer->value, 'attributes' => []]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $party = Party::query()->where('company_id', $company->id)->where('name', 'Unspecified GST Party')->firstOrFail();
    expect($party->is_gst_applicable)->toBeTrue();
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
    enterBranchWorkspace($company, $branch)->assertRedirect();
    $this->get(route('branch.parties.create'))->assertForbidden();
    $this->post(route('branch.parties.store'), [
        'name' => 'Denied',
        'roles' => [['role' => 'customer', 'attributes' => []]],
    ])->assertForbidden();

    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();
    $this->post(route('branch.parties.store'), [
        'name' => 'Owned Party',
        'contact_number' => '9000022222',
        'state' => 'MH',
        'city' => 'Mumbai',
        'roles' => [['role' => 'customer', 'attributes' => []]],
        'remark' => 'Create',
    ])->assertRedirect();

    $party = Party::query()->where('name', 'Owned Party')->firstOrFail();

    uiActingAsCustomer($otherOwner, 'password');
    enterBranchWorkspace($otherCompany, $otherBranch)->assertRedirect();
    $this->get(route('branch.parties.show', $party))->assertNotFound();
});

test('vehicle and bank cash inertia crud masks account numbers', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->post(route('branch.vehicles.store'), array_merge(
        ownVehicleMasterPayload($owner),
        [
            'registration_number' => 'MH12AB1234',
            'make' => 'Tata',
            'model' => '407',
            'remark' => 'Create vehicle',
        ],
    ))->assertRedirect();

    $vehicle = Vehicle::query()->where('registration_number', 'MH12AB1234')->firstOrFail();
    $this->get(route('branch.vehicles.show', $vehicle))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('branch/masters/vehicles/show')->where('vehicle.registration_number', 'MH12AB1234'));

    $this->post(route('branch.bank-cash.store'), [
        'account_kind' => 'bank',
        'name' => 'Ops Bank',
        'bank_name' => 'HDFC',
        'account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
        'account_holder_name' => 'Ops',
        'remark' => 'Create bank',
    ])->assertRedirect();

    $account = BankCashAccount::query()->where('name', 'Ops Bank')->firstOrFail();

    $this->get(route('branch.bank-cash.show', $account))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/bank-cash/show')
            ->where('account.account_number_masked', fn ($value) => is_string($value) && ! str_contains($value, '123456789012'))
            ->missing('account.account_number'));

    $this->get(route('branch.bank-cash.edit', $account))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('account.account_number'));
});

test('station create is owner-only while members can view directory', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch, 'member' => $member] = uiMastersWorkspace();

    uiActingAsCustomer($member, 'member-secret');
    enterBranchWorkspace($company, $branch)->assertRedirect();
    $this->get(route('branch.stations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('branch/masters/stations/index')->where('canManageOwnerStations', false));
    $this->get(route('branch.stations.create'))->assertForbidden();

    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();
    $this->get(route('branch.stations.create'))->assertRedirect(route('branch.stations.index'));
    $this->post(route('branch.stations.store'), [
        'name' => 'Pune',
        'city' => 'Pune',
        'remark' => 'Create station',
    ])->assertRedirect();
});

test('goods types store rejects forged catalogue type in payload', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->post(route('branch.goods-types.store'), [
        'catalogue_type' => 'truck_type',
        'code' => 'BAD',
        'name' => 'Bad Forge',
        'remark' => 'Test',
    ])->assertSessionHasErrors('catalogue_type');
});

test('goods types master renders without payment modes and legacy catalogues redirects', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->get(route('branch.catalogues.index'))
        ->assertRedirect(route('branch.goods-types.index'));

    $this->get(route('branch.goods-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/catalogue-type/index')
            ->missing('paymentModes')
            ->where('canManageOwnerCatalogues', true));
});

test('party register filters by contact person, state, city and deleted status', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();
    $maharashtra = State::query()->where('gst_code', '27')->firstOrFail();
    $service = app(PartyService::class);

    $pune = $service->create($owner, $company, null, [
        'name' => 'Pune Traders', 'contact_person' => 'Asha Kulkarni', 'contact_number' => '9000000001',
        'gstin' => '27ABCDE1234F1Z5', 'state' => $maharashtra->name, 'state_id' => $maharashtra->id, 'city' => 'Pune',
    ], [['role' => PartyRoleType::Customer->value, 'attributes' => []]]);
    $service->create($owner, $company, null, [
        'name' => 'Surat Mills', 'contact_number' => '9000000002', 'state' => 'Gujarat', 'city' => 'Surat',
    ], [['role' => PartyRoleType::Consignee->value, 'attributes' => []]]);
    $gone = $service->create($owner, $company, null, [
        'name' => 'Closed Co', 'contact_number' => '9000000003', 'state' => $maharashtra->name, 'city' => 'Nagpur',
    ], [['role' => PartyRoleType::Customer->value, 'attributes' => []]]);
    $service->softDelete($owner, $gone, 'Closed business');

    $this->get(route('branch.parties.index', ['q' => 'asha']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/index')
            ->has('parties.data', 1)
            ->where('parties.data.0.id', $pune->id)
            ->where('parties.data.0.contact_person', 'Asha Kulkarni')
            ->where('parties.data.0.gstin', '27ABCDE1234F1Z5')
            ->where('parties.data.0.status', 'active')
            ->has('stateOptions'));

    $this->get(route('branch.parties.index', ['state_id' => $maharashtra->id]))
        ->assertInertia(fn ($page) => $page->has('parties.data', 1)->where('parties.data.0.name', 'Pune Traders'));

    $this->get(route('branch.parties.index', ['city' => 'sur']))
        ->assertInertia(fn ($page) => $page->has('parties.data', 1)->where('parties.data.0.name', 'Surat Mills'));

    $this->get(route('branch.parties.index', ['status' => 'deleted']))
        ->assertInertia(fn ($page) => $page
            ->has('parties.data', 1)
            ->where('parties.data.0.name', 'Closed Co')
            ->where('parties.data.0.status', 'deleted'));

    $this->get(route('branch.parties.index', ['status' => 'archived']))->assertSessionHasErrors('status');
});

test('party show presents role-driven sections without internal identifiers', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();
    $service = app(PartyService::class);

    $customer = $service->create($owner, $company, null, [
        'name' => 'Plain Customer', 'contact_number' => '9000000011', 'state' => 'Maharashtra', 'city' => 'Pune',
    ], [['role' => PartyRoleType::Customer->value, 'attributes' => []]]);

    $this->get(route('branch.parties.show', $customer))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/show')
            ->where('party.roles', ['Customer'])
            ->where('party.sections.0.key', 'general')
            ->where('party.sections.0.items.1', ['label' => 'Party Short Name', 'value' => 'Plain Customer'])
            ->where('party.sections.1.key', 'tax')
            ->has('party.sections', 2)
            ->missing('party.id')
            ->missing('roleOptions')
            ->missing('links.assign_role'));

    $driver = $service->create($owner, $company, null, [
        'name' => 'Ravi Driver',
        'contact_number' => '9000000012',
        'bank_name' => 'State Bank',
        'bank_account_number' => '000123456789',
        'bank_ifsc' => 'sbin0001234',
    ], [[
        'role' => PartyRoleType::Driver->value,
        'attributes' => [
            'driver_type' => 'permanent',
            'license_number' => 'MH1220200001234',
            'aadhaar' => '123412341234',
        ],
    ]]);

    $this->get(route('branch.parties.show', $driver))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('party.roles', ['Driver'])
            ->where('party.sections.0.key', 'general')
            ->where('party.sections.1.key', 'driver')
            ->where('party.sections.1.items.2.value', 'XXXX XXXX 1234')
            ->where('party.sections.2.key', 'bank')
            ->where('party.sections.2.items', [
                ['label' => 'Bank Name', 'value' => 'State Bank'],
                ['label' => 'Account Number', 'value' => '********6789'],
                ['label' => 'IFSC Code', 'value' => 'SBIN0001234'],
            ])
            ->has('party.sections', 3));
});

test('party bank account stays masked and is kept when edit leaves it blank', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $vendor = app(PartyService::class)->create($owner, $company, null, [
        'name' => 'Diesel Vendor',
        'contact_person' => 'Mahesh',
        'contact_number' => '9000000013',
        'bank_name' => 'HDFC',
        'bank_account_number' => '50100012345678',
    ], [['role' => PartyRoleType::GeneralVendor->value, 'attributes' => []]]);

    $this->get(route('branch.parties.edit', $vendor))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('party.bank_account_number_masked', '**********5678')
            ->missing('party.bank_account_number'));

    $this->patch(route('branch.parties.update', $vendor), [
        'name' => 'Diesel Vendor',
        'bank_name' => 'HDFC Bank',
        'bank_account_number' => '',
        'bank_ifsc' => 'BAD-IFSC',
        'remark' => 'Fix bank',
    ])->assertSessionHasErrors('bank_ifsc');

    $this->patch(route('branch.parties.update', $vendor), [
        'name' => 'Diesel Vendor',
        'bank_name' => 'HDFC Bank',
        'bank_account_number' => '',
        'remark' => 'Rename bank',
    ])->assertRedirect(route('branch.parties.show', $vendor));

    expect($vendor->fresh())
        ->bank_name->toBe('HDFC Bank')
        ->bank_account_number->toBe('50100012345678');
});

test('party short name follows the name until customized', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = uiMastersWorkspace();
    uiActingAsCustomer($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();
    $service = app(PartyService::class);

    $following = $service->create($owner, $company, null, [
        'name' => 'Konkan Roadways', 'contact_number' => '9000000014', 'state' => 'Maharashtra', 'city' => 'Pune',
    ], [['role' => PartyRoleType::Customer->value, 'attributes' => []]]);
    $custom = $service->create($owner, $company, null, [
        'name' => 'Saurashtra Carriers', 'short_name' => 'Saurashtra', 'contact_number' => '9000000015', 'state' => 'Gujarat', 'city' => 'Rajkot',
    ], [['role' => PartyRoleType::Customer->value, 'attributes' => []]]);

    expect($following->short_name)->toBe('Konkan Roadways');

    $this->patch(route('branch.parties.update', $following), ['name' => 'Konkan Logistics', 'remark' => 'Rename'])->assertRedirect();
    $this->patch(route('branch.parties.update', $custom), ['name' => 'Saurashtra Freight', 'remark' => 'Rename'])->assertRedirect();

    expect($following->fresh()->short_name)->toBe('Konkan Logistics')
        ->and($custom->fresh()->short_name)->toBe('Saurashtra');
});
