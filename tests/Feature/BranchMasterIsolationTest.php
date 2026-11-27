<?php

require_once __DIR__.'/../Support/VehicleMasterFixtures.php';

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Master\Vehicle\VehicleService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GstRate;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

/**
 * @return array{owner: User, company: Company, branchA: Branch, branchB: Branch}
 */
function isolationWorkspace(DataVisibility $visibility): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);

    $rate = GstRate::query()->firstOrCreate(
        ['rate_percent' => '18.000', 'version' => 1],
        [
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
        ],
    );

    $company = app(CompanyWorkspaceService::class)->createCompany(
        $owner,
        'Isolation '.$visibility->value,
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

function isolationLogin(User $user): void
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
}

test('branch-wise restricted party show from another branch is denied', function () {
    ['owner' => $owner, 'company' => $company, 'branchA' => $branchA, 'branchB' => $branchB] = isolationWorkspace(DataVisibility::BranchWiseRestricted);
    isolationLogin($owner);
    enterBranchWorkspace($company, $branchA);

    $party = app(PartyService::class)->create(
        $owner,
        $company,
        $branchA,
        [
            'name' => 'Mumbai Only Party',
            'contact_person' => 'Contact',
            'contact_number' => '9876500001',
            'state' => 'MH',
            'city' => 'Mumbai',
        ],
        [['role' => PartyRoleType::Customer->value]],
        'create',
    );

    enterBranchWorkspace($company, $branchB);

    $this->get(route('branch.parties.show', [$party]))->assertNotFound();
    $this->get(route('branch.parties.edit', [$party]))->assertNotFound();
});

test('branch-wise restricted vehicle show from another branch is denied', function () {
    ['owner' => $owner, 'company' => $company, 'branchA' => $branchA, 'branchB' => $branchB] = isolationWorkspace(DataVisibility::BranchWiseRestricted);
    isolationLogin($owner);
    enterBranchWorkspace($company, $branchA);

    $vehicle = app(VehicleService::class)->create(
        $owner,
        $company,
        $branchA,
        ownVehicleMasterPayload($owner, [
            'registration_number' => 'MH12ISO'.substr((string) abs(crc32((string) $company->id)), 0, 4),
        ]),
        'create',
        $branchA,
    );

    enterBranchWorkspace($company, $branchB);

    $this->get(route('branch.vehicles.show', [$vehicle]))->assertNotFound();
});

test('market vehicle cannot reference a transporter invisible in the panel branch', function () {
    ['owner' => $owner, 'company' => $company, 'branchA' => $branchA, 'branchB' => $branchB] = isolationWorkspace(DataVisibility::BranchWiseRestricted);

    $transporter = app(PartyService::class)->create(
        $owner,
        $company,
        $branchA,
        [
            'name' => 'Branch A Transporter',
            'contact_person' => 'Contact',
            'contact_number' => '9876500002',
            'pan' => 'ABCDE9999F',
            'state' => 'MH',
            'city' => 'Mumbai',
        ],
        [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'ABCDE9999F',
                'pan_status' => 'active',
                'declaration' => true,
                'goods_vehicles_count' => 1,
            ],
        ]],
        'create',
    );

    expect(fn () => app(VehicleService::class)->create(
        $owner,
        $company,
        $branchB,
        [
            'registration_number' => 'MH14ISO'.substr((string) abs(crc32((string) $company->id.'m')), 0, 4),
            'ownership' => VehicleOwnership::Market->value,
            'owner_party_id' => $transporter->id,
            'is_active' => true,
        ],
        'create',
        $branchB,
    ))->toThrow(ValidationException::class);
});

test('shared vehicle create rejects branch-restricted transporter when panel branch differs', function () {
    ['owner' => $owner, 'company' => $company, 'branchA' => $branchA, 'branchB' => $branchB] = isolationWorkspace(DataVisibility::SharedData);

    $transporter = app(PartyService::class)->create(
        $owner,
        $company,
        $branchA,
        [
            'name' => 'Restricted Despite Shared Company',
            'contact_person' => 'Contact',
            'contact_number' => '9876500003',
            'pan' => 'ABCDE8888F',
            'state' => 'MH',
            'city' => 'Pune',
        ],
        [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'ABCDE8888F',
                'pan_status' => 'active',
                'declaration' => true,
                'goods_vehicles_count' => 1,
            ],
        ]],
        'create',
    );

    // Force branch-scoped party even under Shared company (historical inconsistency path).
    $transporter->forceFill(['branch_id' => $branchA->id])->save();

    expect(fn () => app(VehicleService::class)->create(
        $owner,
        $company,
        null,
        [
            'registration_number' => 'MH15ISO'.substr((string) abs(crc32((string) $company->id.'s')), 0, 4),
            'ownership' => VehicleOwnership::Market->value,
            'owner_party_id' => $transporter->id,
            'is_active' => true,
        ],
        'create',
        $branchB,
    ))->toThrow(ValidationException::class);
});
