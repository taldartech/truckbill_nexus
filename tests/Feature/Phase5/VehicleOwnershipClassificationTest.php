<?php

require_once __DIR__.'/../../Support/TyreLayoutFixtures.php';

use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LrVehicleClassificationService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Master\Vehicle\VehicleService;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\OwnerCatalogueItem;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('own vehicle resolves to own_fleet context', function () {
    $ws = tripWorkspace([SubscriptionModule::OwnFleet->value]);
    $service = new LrVehicleClassificationService;

    $result = $service->resolveFromVehicleId($ws['company'], $ws['branch'], $ws['vehicle']->id);

    expect($result['context'])->toBe(LrWorkflowContext::OwnFleet)
        ->and($result['vehicle']->id)->toBe($ws['vehicle']->id);
});

test('market vehicle resolves to broker context', function () {
    $ws = tripWorkspace([SubscriptionModule::Broker->value, SubscriptionModule::OwnFleet->value]);

    TenantContextHolder::inject(new TenantContext(
        companyId: $ws['company']->id,
        branchId: $ws['branch']->id,
        ownerId: (string) $ws['company']->owner_user_id,
    ));

    $ownerParty = app(PartyService::class)->create(
        actor: $ws['owner'],
        company: $ws['company'],
        branch: null,
        attributes: [
            'name' => 'Market Owner',
            'contact_number' => '9800099001',
            'pan' => 'AAAAA1234A',
            'state' => 'MH',
            'city' => 'Pune',
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'AAAAA1234A',
                'pan_status' => 'active',
                'declaration' => true,
                'goods_vehicles_count' => 2,
            ],
        ]],
        remark: 'Create market owner',
    );

    $market = Vehicle::query()->create([
        'company_id' => $ws['company']->id,
        'branch_id' => null,
        'registration_number' => 'MH12MKT0001',
        'is_active' => true,
        'ownership' => VehicleOwnership::Market->value,
        'owner_party_id' => $ownerParty->id,
    ]);

    $result = (new LrVehicleClassificationService)->resolveFromVehicleId(
        $ws['company'],
        $ws['branch'],
        $market->id,
    );

    expect($result['context'])->toBe(LrWorkflowContext::Broker);
});

test('unclassified vehicle is rejected safely', function () {
    $ws = tripWorkspace([SubscriptionModule::OwnFleet->value]);

    $unclassified = Vehicle::query()->create([
        'company_id' => $ws['company']->id,
        'branch_id' => null,
        'registration_number' => 'MH12UNC0001',
        'is_active' => true,
        'ownership' => null,
    ]);

    expect(fn () => (new LrVehicleClassificationService)->resolveFromVehicleId(
        $ws['company'],
        $ws['branch'],
        $unclassified->id,
    ))->toThrow(ValidationException::class);
});

test('ownfleet-only company rejects market vehicle classification', function () {
    $ws = tripWorkspace([SubscriptionModule::OwnFleet->value]);

    TenantContextHolder::inject(new TenantContext(
        companyId: $ws['company']->id,
        branchId: $ws['branch']->id,
        ownerId: (string) $ws['company']->owner_user_id,
    ));

    $ownerParty = app(PartyService::class)->create(
        actor: $ws['owner'],
        company: $ws['company'],
        branch: null,
        attributes: [
            'name' => 'Blocked Market Owner',
            'contact_number' => '9800099002',
            'pan' => 'BBBBB1234B',
            'state' => 'MH',
            'city' => 'Pune',
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'BBBBB1234B',
                'pan_status' => 'active',
                'declaration' => true,
                'goods_vehicles_count' => 1,
            ],
        ]],
        remark: 'Create blocked market owner',
    );

    $market = Vehicle::query()->create([
        'company_id' => $ws['company']->id,
        'registration_number' => 'MH12BLK0001',
        'is_active' => true,
        'ownership' => VehicleOwnership::Market->value,
        'owner_party_id' => $ownerParty->id,
    ]);

    expect(fn () => (new LrVehicleClassificationService)->resolveFromVehicleId(
        $ws['company'],
        $ws['branch'],
        $market->id,
    ))->toThrow(ValidationException::class);
});

test('broker-only company rejects own vehicle classification', function () {
    $ws = tripWorkspace([SubscriptionModule::Broker->value]);

    expect(fn () => (new LrVehicleClassificationService)->resolveFromVehicleId(
        $ws['company'],
        $ws['branch'],
        $ws['vehicle']->id,
    ))->toThrow(ValidationException::class);
});

test('vehicle master requires truck type for own but keeps market master fields optional', function () {
    $ws = tripWorkspace([SubscriptionModule::OwnFleet->value, SubscriptionModule::Broker->value]);
    $vehicles = app(VehicleService::class);

    $truckType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $ws['owner']->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'name' => 'Own Truck',
        'code' => null,
        'is_active' => true,
    ]);
    $tyreLayout = createOwnerTyreLayout($ws['owner'], 'Layout A');

    expect(fn () => $vehicles->create(
        $ws['owner'],
        $ws['company'],
        null,
        [
            'registration_number' => 'MH12OWNFAIL1',
            'ownership' => VehicleOwnership::Own->value,
        ],
    ))->toThrow(ValidationException::class);

    $own = $vehicles->create(
        $ws['owner'],
        $ws['company'],
        null,
        [
            'registration_number' => 'MH12OWNOK01',
            'ownership' => VehicleOwnership::Own->value,
            'truck_type_id' => $truckType->id,
            'tyre_layout_id' => $tyreLayout->id,
        ],
    );

    expect($own->ownership)->toBe(VehicleOwnership::Own)
        ->and($own->truck_type_id)->toBe($truckType->id);

    TenantContextHolder::inject(new TenantContext(
        companyId: $ws['company']->id,
        branchId: $ws['branch']->id,
        ownerId: (string) $ws['company']->owner_user_id,
    ));

    $ownerParty = app(PartyService::class)->create(
        actor: $ws['owner'],
        company: $ws['company'],
        branch: null,
        attributes: [
            'name' => 'Valid Market Owner',
            'contact_number' => '9800099003',
            'pan' => 'CCCCC1234C',
            'state' => 'MH',
            'city' => 'Pune',
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'CCCCC1234C',
                'pan_status' => 'active',
                'declaration' => true,
                'goods_vehicles_count' => 3,
            ],
        ]],
        remark: 'Create valid market owner',
    );

    $market = $vehicles->create(
        $ws['owner'],
        $ws['company'],
        null,
        [
            'registration_number' => 'MH12MKTOK01',
            'registration_date' => '2024-04-01',
            'ownership' => VehicleOwnership::Market->value,
            'owner_party_id' => $ownerParty->id,
            'truck_type_id' => $truckType->id,
            'tyre_layout_id' => $tyreLayout->id,
            'make' => 'Tata',
            'chassis_number' => 'CHASSIS-1',
        ],
    );

    expect($market->ownership)->toBe(VehicleOwnership::Market)
        ->and($market->owner_party_id)->toBe($ownerParty->id)
        ->and($market->truck_type_id)->toBe($truckType->id)
        ->and($market->tyre_layout_id)->toBeNull()
        ->and($market->make)->toBeNull()
        ->and($market->chassis_number)->toBeNull()
        ->and($market->registration_date?->toDateString())->toBe('2024-04-01');

    $ownerless = $vehicles->create(
        $ws['owner'],
        $ws['company'],
        null,
        ['registration_number' => 'MH12MKTNOOWN', 'ownership' => VehicleOwnership::Market->value],
    );

    expect($ownerless->owner_party_id)->toBeNull()
        ->and($ownerless->truck_type_id)->toBeNull()
        ->and($ownerless->primary_driver_party_id)->toBeNull();
});

test('cross-branch invisible vehicle cannot classify', function () {
    $ws = tripWorkspace([SubscriptionModule::OwnFleet->value]);
    $otherBranch = Branch::factory()->create([
        'company_id' => $ws['company']->id,
        'name' => 'Other Branch',
    ]);

    $hidden = Vehicle::query()->create([
        'company_id' => $ws['company']->id,
        'branch_id' => $otherBranch->id,
        'registration_number' => 'MH12HID0001',
        'is_active' => true,
        'ownership' => VehicleOwnership::Own->value,
        'truck_type_id' => $ws['vehicle']->truck_type_id,
        'tyre_layout_id' => $ws['vehicle']->tyre_layout_id,
    ]);

    expect(fn () => (new LrVehicleClassificationService)->resolveFromVehicleId(
        $ws['company'],
        $ws['branch'],
        $hidden->id,
    ))->toThrow(ValidationException::class);
});
