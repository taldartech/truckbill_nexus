<?php

require_once __DIR__.'/../Support/VehicleMasterFixtures.php';

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Company\MasterDataVisibilityResolver;
use App\Domains\Master\BankCash\BankCashAccountService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\VehicleService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GstRate;
use App\Models\Party;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

/**
 * @return array{owner: User, company: Company, branchA: Branch, branchB: Branch}
 */
function visibilityWorkspace(DataVisibility $visibility): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);

    $rate = GstRate::query()->firstOrCreate(
        ['code' => 'GST18', 'version' => 1],
        [
            'name' => 'GST 18%',
            'rate_percent' => '18.000',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
        ],
    );

    $company = app(CompanyWorkspaceService::class)->createCompany(
        $owner,
        'Visibility '.$visibility->value,
        'Branch A',
        [
            'pan' => 'ABCDE1234F',
            'registered_address' => 'Addr',
            'state' => 'Maharashtra',
            'city' => 'Mumbai',
            'pin' => '400001',
            'contact_person' => $owner->name,
            'mobile' => '9876543210',
            'email' => $owner->email,
            'gst_registration_type' => GstRegistrationType::Regular->value,
            'tax_liability' => TaxLiability::Fcm->value,
            'gstin' => '27ABCDE1234F1Z5',
            'gst_rate_id' => $rate->id,
            'data_visibility' => $visibility->value,
            'timezone' => 'Asia/Kolkata',
        ],
        true,
    );

    $branchA = $company->branches()->where('name', 'Branch A')->firstOrFail();
    $branchB = app(CompanyWorkspaceService::class)->createBranch($company, 'Branch B', 'Second branch');

    return compact('owner', 'company', 'branchA', 'branchB');
}

function visibilityLogin(User $user): TestResponse
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }

    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'owner-secret',
    ]);
    $response->assertRedirect();
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    return $response;
}

test('shared data party vehicle and bank persist company-wide', function () {
    ['owner' => $owner, 'company' => $company, 'branchA' => $branchA, 'branchB' => $branchB] = visibilityWorkspace(DataVisibility::SharedData);

    $party = app(PartyService::class)->create(
        $owner,
        $company,
        app(MasterDataVisibilityResolver::class)->scopeBranchForCreate($company, $branchA),
        ['name' => 'Shared Party', 'contact_number' => '9000010001', 'state' => 'MH', 'city' => 'Pune'],
        [['role' => PartyRoleType::Customer->value]],
        'Create',
    );
    $vehicle = app(VehicleService::class)->create(
        $owner,
        $company,
        app(MasterDataVisibilityResolver::class)->scopeBranchForCreate($company, $branchA),
        ownVehicleMasterPayload($owner, ['registration_number' => 'MH12AB1234']),
        'Create',
    );
    $account = app(BankCashAccountService::class)->create(
        $owner,
        $company,
        app(MasterDataVisibilityResolver::class)->scopeBranchForCreate($company, $branchA),
        ['account_kind' => 'cash', 'name' => 'Petty Cash'],
        'Create',
    );

    expect($party->branch_id)->toBeNull()
        ->and($vehicle->branch_id)->toBeNull()
        ->and($account->branch_id)->toBeNull();

    $visibleOnB = app(PartyService::class)->search($owner, $company, $branchB, '');
    expect(collect($visibleOnB->items())->pluck('id')->all())->toContain($party->id);
});

test('restricted party vehicle and bank persist to authorized branch', function () {
    ['owner' => $owner, 'company' => $company, 'branchA' => $branchA, 'branchB' => $branchB] = visibilityWorkspace(DataVisibility::BranchWiseRestricted);

    $resolver = app(MasterDataVisibilityResolver::class);
    $party = app(PartyService::class)->create(
        $owner,
        $company,
        $resolver->scopeBranchForCreate($company, $branchA),
        ['name' => 'Restricted Party', 'contact_number' => '9000010002', 'state' => 'MH', 'city' => 'Pune'],
        [['role' => PartyRoleType::Customer->value]],
        'Create',
    );
    $vehicle = app(VehicleService::class)->create(
        $owner,
        $company,
        $resolver->scopeBranchForCreate($company, $branchA),
        ownVehicleMasterPayload($owner, ['registration_number' => 'MH12XY9999']),
        'Create',
    );
    $account = app(BankCashAccountService::class)->create(
        $owner,
        $company,
        $resolver->scopeBranchForCreate($company, $branchA),
        ['account_kind' => 'cash', 'name' => 'Branch Cash'],
        'Create',
    );

    expect($party->branch_id)->toBe($branchA->id)
        ->and($vehicle->branch_id)->toBe($branchA->id)
        ->and($account->branch_id)->toBe($branchA->id);

    $visibleOnB = app(PartyService::class)->search($owner, $company, $branchB, '');
    expect(collect($visibleOnB->items())->pluck('id')->all())->not->toContain($party->id);
});

test('forged scope cannot override company data visibility on http create', function () {
    ['owner' => $owner, 'company' => $company, 'branchA' => $branchA] = visibilityWorkspace(DataVisibility::BranchWiseRestricted);
    visibilityLogin($owner);
    enterBranchWorkspace($company, $branchA)->assertRedirect();

    $this->post(route('branch.parties.store'), [
        'scope' => 'shared',
        'name' => 'Forged Shared Party',
        'contact_number' => '9000044444',
        'state' => 'MH',
        'city' => 'Nagpur',
        'roles' => [['role' => PartyRoleType::Customer->value]],
        'remark' => 'Forge',
    ])->assertRedirect();

    $party = Party::query()->where('name', 'Forged Shared Party')->firstOrFail();
    expect($party->branch_id)->toBe($branchA->id);

    ['owner' => $owner2, 'company' => $company2, 'branchA' => $branch2] = visibilityWorkspace(DataVisibility::SharedData);
    visibilityLogin($owner2);
    enterBranchWorkspace($company2, $branch2)->assertRedirect();

    $this->post(route('branch.vehicles.store'), array_merge(
        ownVehicleMasterPayload($owner2),
        [
            'scope' => 'branch',
            'registration_number' => 'MH01ZZ0001',
            'remark' => 'Forge branch',
        ],
    ))->assertRedirect();

    $vehicle = Vehicle::query()->where('registration_number', 'MH01ZZ0001')->firstOrFail();
    expect($vehicle->branch_id)->toBeNull();
});

test('cross-company master access remains denied', function () {
    ['owner' => $ownerA, 'company' => $companyA, 'branchA' => $branchA] = visibilityWorkspace(DataVisibility::SharedData);
    ['owner' => $ownerB, 'company' => $companyB, 'branchA' => $branchB] = visibilityWorkspace(DataVisibility::SharedData);

    $party = app(PartyService::class)->create(
        $ownerA,
        $companyA,
        null,
        ['name' => 'Owned Party', 'contact_number' => '9000010003', 'state' => 'MH', 'city' => 'Pune'],
        [['role' => PartyRoleType::Customer->value]],
        'Create',
    );

    visibilityLogin($ownerB);
    enterBranchWorkspace($companyB, $branchB)->assertRedirect();

    $this->get(route('branch.parties.show', $party))->assertNotFound();
});

test('visibility change is blocked when operational masters exist', function () {
    ['owner' => $owner, 'company' => $company, 'branchA' => $branchA] = visibilityWorkspace(DataVisibility::SharedData);

    app(PartyService::class)->create(
        $owner,
        $company,
        null,
        ['name' => 'Lock Visibility', 'contact_number' => '9000010004', 'state' => 'MH', 'city' => 'Pune'],
        [['role' => PartyRoleType::Customer->value]],
        'Create',
    );

    expect(fn () => app(CompanyWorkspaceService::class)->updateCompany(
        $company,
        $company->name,
        'Attempt visibility change',
        [
            'pan' => $company->pan,
            'registered_address' => $company->registered_address,
            'state' => $company->state,
            'city' => $company->city,
            'pin' => $company->pin,
            'contact_person' => $company->contact_person,
            'mobile' => $company->mobile,
            'email' => $company->email,
            'gst_registration_type' => $company->gst_registration_type->value,
            'tax_liability' => $company->tax_liability->value,
            'gstin' => $company->gstin,
            'gst_rate_id' => $company->gst_rate_id,
            'data_visibility' => DataVisibility::BranchWiseRestricted->value,
            'timezone' => $company->timezone,
        ],
        true,
    ))->toThrow(CompanyDomainException::class, 'Data Visibility cannot change');
});

test('create forms expose company visibility without scope control', function () {
    ['owner' => $owner, 'company' => $company, 'branchA' => $branchA] = visibilityWorkspace(DataVisibility::SharedData);
    visibilityLogin($owner);
    enterBranchWorkspace($company, $branchA)->assertRedirect();

    $this->get(route('branch.parties.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/form')
            ->where('dataVisibility.mode', 'shared')
            ->missing('party.scope'));
});
