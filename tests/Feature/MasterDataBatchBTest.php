<?php

require_once __DIR__.'/../Support/VehicleMasterFixtures.php';

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Master\BankCash\BankCashAccountService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Catalogue\OwnerCatalogueService;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Station\StationService;
use App\Domains\Master\TyreLayout\TyreLayoutService;
use App\Domains\Master\Vehicle\VehicleService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Party;
use App\Models\PaymentMode;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

function masterActingAsCustomer(User $user): void
{
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

function masterCustomerRole(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

function masterWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'Master Co');
    $branch = $company->branches()->firstOrFail();

    $companyB = $workspace->createCompany($owner, 'Master Co B');
    $branchB = $companyB->branches()->firstOrFail();

    $member = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membership = $workspace->addMembership($company, $member, 'Join');
    $memberAssignment = $workspace->assignBranch($membership, $branch, 'Assign');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $memberAssignment,
        masterCustomerRole('customer.admin'),
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
        masterCustomerRole('customer.viewer'),
        'Grant viewer',
    );

    $otherOwner = User::factory()->customer()->create();
    $otherCompany = $workspace->createCompany($otherOwner, 'Other Master Co');
    $otherBranch = $otherCompany->branches()->firstOrFail();

    return compact(
        'owner',
        'company',
        'branch',
        'companyB',
        'branchB',
        'member',
        'viewer',
        'otherOwner',
        'otherCompany',
        'otherBranch',
    );
}

test('vehicle create update search soft-delete and duplicate registration', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = masterWorkspace();
    masterActingAsCustomer($owner);

    $service = app(VehicleService::class);
    $vehicle = $service->create($owner, $company, null, ownVehicleMasterPayload($owner, [
        'registration_number' => 'mh12ab1234',
        'vehicle_type' => 'truck',
        'make' => 'Ashok',
        'capacity_mt' => '12.500',
    ]));

    expect($vehicle->registration_number)->toBe('MH12AB1234')
        ->and($vehicle->branch_id)->toBeNull();

    expect(fn () => $service->create($owner, $company, null, ownVehicleMasterPayload($owner, [
        'registration_number' => 'MH12AB1234',
        'vehicle_type' => 'truck',
    ])))->toThrow(ValidationException::class);

    $updated = $service->update($owner, $vehicle, ['is_active' => false], 'Deactivate vehicle');
    expect($updated->is_active)->toBeFalse();

    $page = $service->search($owner, $company, $branch, query: 'MH12');
    expect($page->total())->toBe(1);

    $service->softDelete($owner, $vehicle, 'Remove vehicle');
    expect(Vehicle::query()->find($vehicle->id))->toBeNull()
        ->and($vehicle->fresh()->trashed())->toBeTrue();
});

test('viewer cannot mutate vehicles', function () {
    ['viewer' => $viewer, 'company' => $company] = masterWorkspace();
    masterActingAsCustomer($viewer);

    expect(fn () => app(VehicleService::class)->create($viewer, $company, null, ownVehicleMasterPayload($viewer, [
        'registration_number' => 'MH01XX9999',
    ])))->toThrow(MasterDomainException::class);
});

test('bank account masks sensitive number and cash account works', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = masterWorkspace();
    masterActingAsCustomer($owner);

    $service = app(BankCashAccountService::class);
    $bank = $service->create($owner, $company, $branch, [
        'account_kind' => 'bank',
        'name' => 'Operating HDFC',
        'bank_name' => 'HDFC',
        'account_number' => '123456789012',
        'ifsc' => 'HDFC0001111',
    ]);

    $presented = $service->present($bank);
    expect($presented['account_number_masked'])->toBe('********9012')
        ->and($presented)->not->toHaveKey('account_number');

    $cash = $service->create($owner, $company, null, [
        'account_kind' => 'cash',
        'name' => 'Petty Cash',
    ]);
    expect($cash->account_kind)->toBe('cash')->and($cash->account_number)->toBeNull();
});

test('station is owner-shared across companies and isolated across owners', function () {
    [
        'owner' => $owner,
        'company' => $company,
        'branch' => $branch,
        'companyB' => $companyB,
        'branchB' => $branchB,
        'otherOwner' => $otherOwner,
        'otherCompany' => $otherCompany,
        'otherBranch' => $otherBranch,
    ] = masterWorkspace();

    masterActingAsCustomer($owner);
    $service = app(StationService::class);

    $station = $service->create($owner, $company, [
        'code' => 'pune',
        'name' => 'Pune Hub',
        'city' => 'Pune',
    ]);

    $pageA = $service->search($owner, $company, $branch);
    $pageB = $service->search($owner, $companyB, $branchB);
    expect($pageA->total())->toBe(1)->and($pageB->total())->toBe(1);

    $transporter = Party::factory()->create([
        'company_id' => $company->id,
        'branch_id' => null,
        'name' => 'Default Transporter',
    ]);

    $mapping = $service->setDefaultTransporter(
        $owner,
        $station,
        $company,
        $branch,
        $transporter,
        'Set default transporter',
    );
    expect($mapping->default_transporter_party_id)->toBe($transporter->id);

    // Changing default does not invent historical transaction mutation (no txn module).
    $service->setDefaultTransporter($owner, $station, $company, $branch, null, 'Clear default transporter');
    expect($mapping->fresh()->default_transporter_party_id)->toBeNull();

    masterActingAsCustomer($otherOwner);
    $otherPage = $service->search($otherOwner, $otherCompany, $otherBranch);
    expect($otherPage->total())->toBe(0);

    expect(fn () => $service->update($otherOwner, $station, $otherCompany, ['name' => 'Hijack'], 'x'))
        ->toThrow(MasterDomainException::class);
});

test('owner catalogue and seeded payment modes', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch, 'otherOwner' => $otherOwner, 'otherCompany' => $otherCompany] = masterWorkspace();
    masterActingAsCustomer($owner);

    $service = app(OwnerCatalogueService::class);
    $goods = $service->create($owner, $company, OwnerCatalogueType::GoodsType, [
        'name' => 'Steel Coils',
    ]);
    expect($goods->catalogue_type)->toBe(OwnerCatalogueType::GoodsType->value);

    foreach ([
        OwnerCatalogueType::TruckType,
        OwnerCatalogueType::DocumentType,
        OwnerCatalogueType::ExpenseCategory,
        OwnerCatalogueType::PackageType,
    ] as $type) {
        $payload = ['name' => $type->value.' item'];
        if ($type->usesCode()) {
            $payload['code'] = strtoupper($type->value).'_1';
        }
        $service->create($owner, $company, $type, $payload);
    }

    app(TyreLayoutService::class)->create($owner, $company, [
        'name' => 'Batch layout',
        'spare_tyre_count' => 1,
        'axles' => [
            ['sequence' => 1, 'configuration' => 'single'],
        ],
    ]);

    $modes = $service->listPaymentModes($owner, $company, $branch);
    expect($modes->pluck('code')->all())->toContain('cash', 'upi', 'neft', 'rtgs', 'imps', 'other')
        ->and(PaymentMode::query()->count())->toBeGreaterThanOrEqual(8);

    masterActingAsCustomer($otherOwner);
    expect(fn () => $service->update($otherOwner, $goods, $otherCompany, ['name' => 'x'], 'steal'))
        ->toThrow(MasterDomainException::class);
});
