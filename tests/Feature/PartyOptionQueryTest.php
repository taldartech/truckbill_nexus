<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Master\Party\PartyService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GstRate;
use App\Models\PartyRole;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/../Support/VehicleMasterFixtures.php';

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

/**
 * Reuse isolation workspace helper from BranchMasterIsolationTest patterns.
 *
 * @return array{owner: User, company: Company, branchA: Branch, branchB: Branch}
 */
function partyOptionWorkspace(): array
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
        'Party Option Co',
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
            'data_visibility' => DataVisibility::BranchWiseRestricted->value,
            'timezone' => 'Asia/Kolkata',
        ],
        true,
    );

    $branchA = $company->branches()->where('name', 'Branch A')->firstOrFail();
    $branchB = app(CompanyWorkspaceService::class)->createBranch($company, 'Branch B', 'Second');

    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: $branchA->id,
        ownerId: (string) $company->owner_user_id,
    ));

    return compact('owner', 'company', 'branchA', 'branchB');
}

test('party option query includes only active matching roles visible in branch', function () {
    ['owner' => $owner, 'company' => $company, 'branchA' => $branchA, 'branchB' => $branchB] = partyOptionWorkspace();
    $parties = app(PartyService::class);

    $customerOnly = $parties->create($owner, $company, $branchA, [
        'name' => 'Customer Only',
        'contact_number' => '9800000001',
        'state' => 'MH',
        'city' => 'Pune',
    ], [['role' => PartyRoleType::Customer->value]], 'c');

    $customerConsignor = $parties->create($owner, $company, $branchA, [
        'name' => 'Customer Consignor',
        'contact_number' => '9800000002',
        'state' => 'MH',
        'city' => 'Pune',
    ], [
        ['role' => PartyRoleType::Customer->value],
        ['role' => PartyRoleType::Consignor->value],
    ], 'cc');

    $transporterOnly = $parties->create($owner, $company, $branchA, [
        'name' => 'Transporter Only',
        'contact_number' => '9800000003',
        'state' => 'MH',
        'city' => 'Pune',
        'pan' => 'AABCT1111A',
        'is_gst_applicable' => false,
    ], [[
        'role' => PartyRoleType::Transporter->value,
        'attributes' => [
            'pan' => 'AABCT1111A',
            'pan_status' => 'active',
            'declaration' => true,
            'declaration_type' => 'owner',
            'goods_vehicles_count' => 1,
        ],
    ]], 't');

    $driverOnly = $parties->create($owner, $company, $branchA, [
        'name' => 'Driver Only',
        'contact_number' => '9800000004',
        'state' => 'MH',
        'city' => 'Pune',
    ], [[
        'role' => PartyRoleType::Driver->value,
        'attributes' => [
            'driver_type' => 'market',
        ],
    ]], 'd');

    $otherBranchCustomer = $parties->create($owner, $company, $branchB, [
        'name' => 'Other Branch Customer',
        'contact_number' => '9800000005',
        'state' => 'MH',
        'city' => 'Nashik',
    ], [['role' => PartyRoleType::Customer->value]], 'ob');

    $inactiveCustomer = $parties->create($owner, $company, $branchA, [
        'name' => 'Inactive Customer',
        'contact_number' => '9800000006',
        'state' => 'MH',
        'city' => 'Pune',
    ], [['role' => PartyRoleType::Customer->value]], 'ic');

    PartyRole::query()
        ->where('party_id', $inactiveCustomer->id)
        ->where('role', PartyRoleType::Customer->value)
        ->update(['deactivated_at' => now()]);

    $options = app(PartyOptionQuery::class)
        ->forRoles($company, $branchA, PartyRoleType::Customer);

    $ids = collect($options)->pluck('id')->all();

    expect($ids)->toContain($customerOnly->id)
        ->and($ids)->toContain($customerConsignor->id)
        ->and($ids)->not->toContain($transporterOnly->id)
        ->and($ids)->not->toContain($driverOnly->id)
        ->and($ids)->not->toContain($otherBranchCustomer->id)
        ->and($ids)->not->toContain($inactiveCustomer->id);
});
