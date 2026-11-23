<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\Exceptions\PartyDomainException;
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

function actingAsCustomer(User $user): void
{
    // Flush session so Customer single-session claim can rebind for the new actor.
    test()->flushSession();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
}

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function partyCustomerRole(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

function partyWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'Party Co');
    $branch = $company->branches()->firstOrFail();

    $member = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membership = $workspace->addMembership($company, $member, 'Join');
    $memberAssignment = $workspace->assignBranch($membership, $branch, 'Assign');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $memberAssignment,
        partyCustomerRole('customer.admin'),
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
        partyCustomerRole('customer.viewer'),
        'Grant viewer',
    );

    $otherOwner = User::factory()->customer()->create();
    $otherCompany = $workspace->createCompany($otherOwner, 'Other Co');
    $otherBranch = $otherCompany->branches()->firstOrFail();

    return compact('owner', 'company', 'branch', 'member', 'viewer', 'otherOwner', 'otherCompany', 'otherBranch');
}

test('owner can create multi-role party and retain role details on removal', function () {
    ['owner' => $owner, 'company' => $company] = partyWorkspace();
    $this->actingAs($owner);

    $party = app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Acme Traders',
            'contact_number' => '9876543210',
            'state' => 'Maharashtra',
            'city' => 'Pune',
        ],
        roles: [
            ['role' => PartyRoleType::Customer->value, 'attributes' => []],
            ['role' => PartyRoleType::Consignor->value, 'attributes' => []],
        ],
    );

    expect($party->roles)->toHaveCount(2)
        ->and($party->branch_id)->toBeNull();

    $consignor = $party->roles()->where('role', PartyRoleType::Consignor->value)->firstOrFail();
    app(PartyService::class)->removeRole($owner, $consignor, 'Remove consignor role');

    expect($consignor->fresh()->deactivated_at)->not->toBeNull()
        ->and($consignor->fresh()->role_attributes)->toBeArray()
        ->and($party->roles()->whereNull('deactivated_at')->count())->toBe(1);
});

test('party endpoints enforce permissions and hide cross-company records', function () {
    [
        'owner' => $owner,
        'company' => $company,
        'branch' => $branch,
        'member' => $member,
        'viewer' => $viewer,
        'otherCompany' => $otherCompany,
        'otherBranch' => $otherBranch,
    ] = partyWorkspace();

    actingAsCustomer($member);
    $this->postJson(route('branch.parties.store', [$company, $branch]), [
        'name' => 'Branch Party',
        'contact_number' => '9999999999',
        'state' => 'Gujarat',
        'city' => 'Surat',
        'roles' => [
            ['role' => 'customer', 'attributes' => []],
        ],
    ])->assertCreated();

    actingAsCustomer($viewer);
    $this->postJson(route('branch.parties.store', [$company, $branch]), [
        'name' => 'Denied',
        'roles' => [['role' => 'customer', 'attributes' => []]],
    ])->assertForbidden();

    $partyId = Party::query()->where('company_id', $company->id)->value('id');

    expect(fn () => app(PartyService::class)->search($member, $otherCompany, $otherBranch))
        ->toThrow(PartyDomainException::class);

    actingAsCustomer($owner);
    $this->getJson(route('branch.parties.index', [$company, $branch]).'?q=Branch')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Branch Party');

    expect(Party::query()->where('company_id', $otherCompany->id)->whereKey($partyId)->exists())->toBeFalse();
});

test('transporter role requires approved fields', function () {
    ['owner' => $owner, 'company' => $company] = partyWorkspace();
    $this->actingAs($owner);

    expect(fn () => app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: ['name' => 'Truck Co', 'contact_number' => '9000000000', 'state' => 'MH', 'city' => 'Nagpur'],
        roles: [['role' => PartyRoleType::Transporter->value, 'attributes' => ['pan' => 'ABCDE1234F']]],
    ))->toThrow(ValidationException::class);
});
