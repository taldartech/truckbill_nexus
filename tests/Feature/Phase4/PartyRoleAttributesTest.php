<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Party;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function partyRoleAttrsWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'Party Attr Co');
    $branch = $company->branches()->firstOrFail();

    $member = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membership = $workspace->addMembership($company, $member, 'Join');
    $memberAssignment = $workspace->assignBranch($membership, $branch, 'Assign');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $memberAssignment,
        Role::query()
            ->where('domain', PermissionDomain::Customer->value)
            ->where('slug', 'customer.admin')
            ->firstOrFail(),
        'Grant admin',
    );

    return compact('owner', 'company', 'branch', 'member');
}

test('creates transporter with full attributes and persists on reload', function () {
    ['owner' => $owner, 'company' => $company] = partyRoleAttrsWorkspace();
    $this->actingAs($owner);

    $party = app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Konkan Haulage',
            'contact_number' => '9876543210',
            'pan' => 'ABCDE1234F',
            'state' => 'Maharashtra',
            'city' => 'Thane',
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'ABCDE1234F',
                'pan_status' => 'active',
                'declaration' => true,
                'declaration_type' => 'owner',
                'tds_percentage' => '1.00',
                'goods_vehicles_count' => 8,
                'short_name' => 'Konkan',
                'beneficiary_id' => 'BEN-001',
                'bank_account' => '1234567890',
            ],
        ]],
    );

    $reloaded = Party::query()->with('roles')->findOrFail($party->id);
    $attrs = $reloaded->roles->firstWhere('role', PartyRoleType::Transporter->value)?->role_attributes;

    expect($reloaded->pan)->toBe('ABCDE1234F')
        ->and($attrs)->toMatchArray([
            'pan' => 'ABCDE1234F',
            'pan_status' => 'active',
            'declaration' => true,
            'declaration_type' => 'owner',
            'tds_percentage' => '1.00',
            'goods_vehicles_count' => 8,
            'short_name' => 'Konkan',
            'beneficiary_id' => 'BEN-001',
            'bank_account' => '1234567890',
        ]);
});

test('permanent driver requires license while market does not', function () {
    ['owner' => $owner, 'company' => $company] = partyRoleAttrsWorkspace();
    $this->actingAs($owner);
    $service = app(PartyService::class);

    expect(fn () => $service->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Ravi Driver',
            'contact_number' => '9123456789',
            'state' => 'MH',
            'city' => 'Pune',
        ],
        roles: [[
            'role' => PartyRoleType::Driver->value,
            'attributes' => ['driver_type' => 'permanent'],
        ]],
    ))->toThrow(ValidationException::class);

    $market = $service->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Market Driver',
            'contact_number' => '9123456780',
        ],
        roles: [[
            'role' => PartyRoleType::Driver->value,
            'attributes' => ['driver_type' => 'market'],
        ]],
    );

    expect($market->roles->first()->role_attributes['driver_type'])->toBe('market');

    $permanent = $service->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Permanent Driver',
            'contact_number' => '9123456781',
        ],
        roles: [[
            'role' => PartyRoleType::Driver->value,
            'attributes' => [
                'driver_type' => 'permanent',
                'license_number' => 'MH14-2020-001',
                'joining_date' => '2024-01-15',
                'aadhaar' => '123412341234',
            ],
        ]],
    );

    expect($permanent->roles->first()->role_attributes)->toMatchArray([
        'driver_type' => 'permanent',
        'license_number' => 'MH14-2020-001',
        'joining_date' => '2024-01-15',
        'aadhaar' => '123412341234',
    ]);
});

test('customer and consignor multi-role create shares common party fields', function () {
    ['owner' => $owner, 'company' => $company] = partyRoleAttrsWorkspace();
    $this->actingAs($owner);

    $party = app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Acme Traders',
            'contact_number' => '9000011111',
            'state' => 'Gujarat',
            'city' => 'Surat',
            'email' => 'acme@example.test',
        ],
        roles: [
            ['role' => PartyRoleType::Customer->value, 'attributes' => []],
            ['role' => PartyRoleType::Consignor->value, 'attributes' => []],
        ],
    );

    expect($party->roles)->toHaveCount(2)
        ->and($party->roles->pluck('role')->sort()->values()->all())
        ->toBe(['consignor', 'customer']);
});

test('edit common fields without losing transporter attributes', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch, 'member' => $member] = partyRoleAttrsWorkspace();
    $this->actingAs($owner);

    $party = app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Fleet Co',
            'contact_number' => '9888877777',
            'pan' => 'BBBBB1234B',
            'state' => 'Karnataka',
            'city' => 'Bengaluru',
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'BBBBB1234B',
                'pan_status' => 'active',
                'declaration' => true,
                'short_name' => 'Fleet',
                'goods_vehicles_count' => 3,
            ],
        ]],
    );

    test()->flushSession();
    $this->actingAs($member);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($company, $branch);

    $this->patchJson(route('branch.parties.update', [$party]), [
        'name' => 'Fleet Co Updated',
        'contact_number' => '9888877777',
        'pan' => 'BBBBB1234B',
        'state' => 'Karnataka',
        'city' => 'Mysuru',
        'roles' => [[
            'role' => 'transporter',
            'attributes' => [
                'pan' => 'BBBBB1234B',
                'pan_status' => 'active',
                'declaration' => true,
                'short_name' => 'Fleet',
                'goods_vehicles_count' => 3,
            ],
        ]],
        'remark' => 'Update city only',
    ])->assertOk();

    $fresh = $party->fresh(['roles']);
    $attrs = $fresh->roles->firstWhere('role', 'transporter')?->role_attributes;

    expect($fresh->name)->toBe('Fleet Co Updated')
        ->and($fresh->city)->toBe('Mysuru')
        ->and($attrs)->toMatchArray([
            'pan' => 'BBBBB1234B',
            'pan_status' => 'active',
            'declaration' => true,
            'short_name' => 'Fleet',
            'goods_vehicles_count' => 3,
        ]);
});

test('add consignee role to existing party via update sync', function () {
    ['owner' => $owner, 'company' => $company] = partyRoleAttrsWorkspace();
    $this->actingAs($owner);
    $service = app(PartyService::class);

    $party = $service->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Dual Role Party',
            'contact_number' => '9777766666',
            'state' => 'Delhi',
            'city' => 'New Delhi',
        ],
        roles: [
            ['role' => PartyRoleType::Customer->value, 'attributes' => []],
        ],
    );

    $updated = $service->update(
        $owner,
        $party,
        [
            'name' => 'Dual Role Party',
            'contact_number' => '9777766666',
            'state' => 'Delhi',
            'city' => 'New Delhi',
        ],
        'Add consignee role',
        [
            ['role' => PartyRoleType::Customer->value, 'attributes' => []],
            ['role' => PartyRoleType::Consignee->value, 'attributes' => []],
        ],
    );

    expect($updated->roles()->whereNull('deactivated_at')->pluck('role')->sort()->values()->all())
        ->toBe(['consignee', 'customer']);
});

test('rejects unknown role attributes', function () {
    ['owner' => $owner, 'company' => $company] = partyRoleAttrsWorkspace();
    $this->actingAs($owner);

    expect(fn () => app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Bad Attr Party',
            'contact_number' => '9666655555',
            'state' => 'MH',
            'city' => 'Pune',
        ],
        roles: [[
            'role' => PartyRoleType::Customer->value,
            'attributes' => ['credit_limit' => 1000],
        ]],
    ))->toThrow(ValidationException::class);
});

test('rejects transporter without declaration', function () {
    ['owner' => $owner, 'company' => $company] = partyRoleAttrsWorkspace();
    $this->actingAs($owner);

    expect(fn () => app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'No Declaration Co',
            'contact_number' => '9555544444',
            'pan' => 'CCCCC1234C',
            'state' => 'MH',
            'city' => 'Nagpur',
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'CCCCC1234C',
                'pan_status' => 'active',
                'declaration' => false,
            ],
        ]],
    ))->toThrow(ValidationException::class);
});

test('edit form payload includes active roles with attributes', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = partyRoleAttrsWorkspace();
    $this->actingAs($owner);

    $party = app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Prefill Party',
            'contact_number' => '9444433333',
            'pan' => 'DDDDD1234D',
            'state' => 'MH',
            'city' => 'Nashik',
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'DDDDD1234D',
                'pan_status' => 'inactive',
                'declaration' => true,
                'short_name' => 'Prefill',
            ],
        ]],
    );

    seedBranchSession($company, $branch);

    $this->get(route('branch.parties.edit', [$party]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/form')
            ->where('mode', 'edit')
            ->has('party.roles', 1)
            ->where('party.roles.0.role', 'transporter')
            ->where('party.roles.0.attributes.pan_status', 'inactive')
            ->where('party.roles.0.attributes.short_name', 'Prefill')
        );
});
