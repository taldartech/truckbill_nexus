<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Trip\Exceptions\TripAuthorizationException;
use App\Domains\OwnFleet\Trip\Exceptions\TripConflictException;
use App\Domains\OwnFleet\Trip\TripService;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\CustomerWebSession;
use App\Models\LorryReceipt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('phase5 trip permissions are registered without blanket grants', function () {
    $codes = collect(Permission::query()->where('code', 'like', 'trip.%')->pluck('code'));

    expect($codes)->toContain(PermissionCodes::TRIP_VIEW)
        ->and($codes)->toContain(PermissionCodes::TRIP_CREATE)
        ->and($codes)->toContain(PermissionCodes::TRIP_DISPATCH);

    $admin = Role::query()->where('slug', 'customer.admin')->firstOrFail();
    $granted = RolePermission::query()
        ->where('role_id', $admin->id)
        ->whereIn('permission_id', Permission::query()->where('code', 'like', 'trip.%')->pluck('id'))
        ->exists();

    expect($granted)->toBeFalse();
});

test('ownfleet lr creates exactly one pending trip with primary driver', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    expect(app(CoreModuleEntitlementResolver::class)->isEntitled($ctx['company'], SubscriptionModule::OwnFleet))->toBeTrue();

    $lr = tripCreateOwnFleetLr($ctx);
    $trip = app(TripService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'vehicle_id' => $ctx['vehicle']->id,
    ]);

    expect($trip->status)->toBe(TripStatus::Pending)
        ->and($trip->lorry_receipt_id)->toBe($lr->id)
        ->and($trip->vehicle_id)->toBe($ctx['vehicle']->id)
        ->and($trip->driver_party_id)->toBe($ctx['primaryDriver']->id)
        ->and($trip->primary_driver_party_id)->toBe($ctx['primaryDriver']->id)
        ->and($trip->substitute_driver_party_id)->toBeNull()
        ->and(Trip::query()->where('lorry_receipt_id', $lr->id)->whereNull('deleted_at')->count())->toBe(1);
});

test('duplicate trip creation for same lr is rejected', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);
    $service = app(TripService::class);

    $service->createFromLorryReceipt($lr, $ctx['member'], [
        'vehicle_id' => $ctx['vehicle']->id,
    ]);

    expect(fn () => $service->createFromLorryReceipt($lr, $ctx['member'], [
        'vehicle_id' => $ctx['vehicle']->id,
    ]))->toThrow(TripConflictException::class);
});

test('broker lr cannot create ownfleet trip', function () {
    $ctx = tripWorkspace([
        SubscriptionModule::OwnFleet->value,
        SubscriptionModule::Broker->value,
    ]);
    tripActivate($ctx['company'], $ctx['branch']);

    $parties = app(PartyService::class);
    $consignor = $parties->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: [
            'name' => 'Broker Consignor',
            'contact_number' => '9800030011',
            'state' => 'MH',
            'city' => 'Mumbai',
            'is_gst_applicable' => false,
        ],
        roles: [['role' => PartyRoleType::Consignor->value]],
        remark: 'Create consignor',
    );
    $consignee = $parties->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: [
            'name' => 'Broker Consignee',
            'contact_number' => '9800030012',
            'state' => 'MH',
            'city' => 'Pune',
            'is_gst_applicable' => false,
        ],
        roles: [['role' => PartyRoleType::Consignee->value]],
        remark: 'Create consignee',
    );

    $transporter = $parties->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: [
            'name' => 'Broker Transporter',
            'contact_number' => '9800030013',
            'pan' => 'BRKTR1234A',
            'state' => 'MH',
            'city' => 'Thane',
            'is_gst_applicable' => false,
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'BRKTR1234A',
                'pan_status' => 'active',
                'declaration' => true,
                'declaration_type' => 'owner',
                'goods_vehicles_count' => 2,
            ],
        ]],
        remark: 'Create transporter',
    );
    $marketVehicle = Vehicle::query()->create([
        'company_id' => $ctx['company']->id,
        'registration_number' => 'MH12BRKMKT1',
        'is_active' => true,
        'ownership' => VehicleOwnership::Market->value,
        'owner_party_id' => $transporter->id,
    ]);

    $lr = app(LorryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'workflow_context' => LrWorkflowContext::Broker->value,
        'vehicle_id' => $marketVehicle->id,
        'number_mode' => 'auto',
        'lr_date' => now()->toDateString(),
        'from_text' => 'Mumbai',
        'to_text' => 'Pune',
        'consignor_party_id' => $consignor->id,
        'consignee_party_id' => $consignee->id,
        'goods' => [
            [
                'goods_type_id' => $ctx['goodsType']->id,
                'package_type_id' => $ctx['packageType']->id,
                'goods_description' => 'Cartons',
                'package_count' => 10,
                'actual_weight' => 100,
                'charged_weight' => 110,
                'freight_rate_type' => FreightRateType::FixedAmount->value,
                'freight_rate' => 500,
            ],
        ],
    ]);

    expect(fn () => app(TripService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'vehicle_id' => $ctx['vehicle']->id,
    ]))->toThrow(ValidationException::class);
});

test('substitute driver is trip-only and does not change vehicle primary', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);

    $trip = app(TripService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'vehicle_id' => $ctx['vehicle']->id,
        'use_substitute_driver' => true,
        'substitute_driver_party_id' => $ctx['substituteDriver']->id,
    ]);

    expect($trip->driver_party_id)->toBe($ctx['substituteDriver']->id)
        ->and($trip->substitute_driver_party_id)->toBe($ctx['substituteDriver']->id)
        ->and($trip->primary_driver_party_id)->toBe($ctx['primaryDriver']->id)
        ->and($ctx['vehicle']->fresh()->primary_driver_party_id)->toBe($ctx['primaryDriver']->id);
});

test('viewer cannot create trip', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);

    expect(fn () => app(TripService::class)->createFromLorryReceipt($lr, $ctx['viewer'], [
        'vehicle_id' => $ctx['vehicle']->id,
    ]))->toThrow(TripAuthorizationException::class);
});

test('http create trip from ownfleet lr persists and lists', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);

    $claim = function () use ($ctx): void {
        $this->flushSession();
        CustomerWebSession::query()->where('user_id', $ctx['member']->id)->delete();
        $this->actingAs($ctx['member']);
        session()->forget(WebSessionService::SESSION_VERSION_KEY);
        seedBranchSession($ctx['company'], $ctx['branch']);
    };

    $claim();
    $this->post(route('branch.trips.store-from-lr', [$lr]), [
        'vehicle_id' => $ctx['vehicle']->id,
    ])->assertRedirect();

    $trip = Trip::query()->where('lorry_receipt_id', $lr->id)->whereNull('deleted_at')->firstOrFail();

    $claim();
    $this->get(route('branch.trips.create-from-lr', [$lr]))
        ->assertRedirect(route('branch.trips.show', [$trip]));

    $claim();
    $this->get(route('branch.trips.show', [$trip]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/trips/show')
            ->where('trip.status', TripStatus::Pending->value)
            ->where('trip.lr_number', $lr->lr_number));

    $claim();
    $this->get(route('branch.trips.index'))
        ->assertRedirect(route('branch.lorry-receipts.index'));

    $claim();
    $this->get(route('branch.lorry-receipts.show', [$lr]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('linkedTrip.id', $trip->id)
            ->where('permissions.can_create_trip', false));
});

test('trip creation rejects a different own vehicle than the lr', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);

    $otherVehicle = Vehicle::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => null,
        'registration_number' => 'MH12TRIPOTHER',
        'is_active' => true,
        'ownership' => VehicleOwnership::Own->value,
        'truck_type_id' => $ctx['vehicle']->truck_type_id,
        'tyre_layout_id' => $ctx['vehicle']->tyre_layout_id,
        'primary_driver_party_id' => $ctx['primaryDriver']->id,
    ]);

    expect(fn () => app(TripService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'vehicle_id' => $otherVehicle->id,
    ]))->toThrow(ValidationException::class);
});

test('trip creation rejects market vehicle even when submitted', function () {
    $ctx = tripWorkspace([
        SubscriptionModule::OwnFleet->value,
        SubscriptionModule::Broker->value,
    ]);
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);

    $marketVehicle = Vehicle::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => null,
        'registration_number' => 'MH12TRIPMKT01',
        'is_active' => true,
        'ownership' => VehicleOwnership::Market->value,
        'truck_type_id' => $ctx['vehicle']->truck_type_id,
        'tyre_layout_id' => $ctx['vehicle']->tyre_layout_id,
        'primary_driver_party_id' => $ctx['primaryDriver']->id,
    ]);

    // Forge LR vehicle to market after create — Trip must still reject Market ownership.
    $lr->forceFill(['vehicle_id' => $marketVehicle->id])->save();

    expect(fn () => app(TripService::class)->createFromLorryReceipt($lr->fresh(), $ctx['member'], [
        'vehicle_id' => $marketVehicle->id,
    ]))->toThrow(ValidationException::class);
});

test('http create trip form locks vehicle to the lr truck', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);

    $this->flushSession();
    CustomerWebSession::query()->where('user_id', $ctx['member']->id)->delete();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.trips.create-from-lr', [$lr]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/trips/create')
            ->where('lorryReceipt.vehicle_id', $ctx['vehicle']->id)
            ->has('vehicles', 1)
            ->where('vehicles.0.id', $ctx['vehicle']->id));
});

test('own fleet bilty store can redirect to create trip', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $this->flushSession();
    CustomerWebSession::query()->where('user_id', $ctx['member']->id)->delete();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $response = $this->post(route('branch.lorry-receipts.store'), [
        'form_mode' => 'truck',
        'vehicle_id' => $ctx['vehicle']->id,
        'number_mode' => 'auto',
        'lr_date' => now()->toDateString(),
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'goods' => [[
            'goods_type_id' => $ctx['goodsType']->id,
            'package_type_id' => $ctx['packageType']->id,
            'goods_description' => 'Own fleet cargo',
            'package_count' => 1,
            'actual_weight' => 1000,
            'charged_weight' => 1000,
            'freight_rate_type' => FreightRateType::PerMt->value,
            'freight_rate' => 1000,
            'goods_value' => 1000,
        ]],
        'redirect_to_trip' => '1',
    ]);

    $lr = LorryReceipt::query()
        ->where('company_id', $ctx['company']->id)
        ->where('branch_id', $ctx['branch']->id)
        ->latest('created_at')
        ->first();

    expect($lr)->not->toBeNull()
        ->and($lr->workflow_context)->toBe(LrWorkflowContext::OwnFleet);
    $response->assertRedirect(route('branch.trips.create-from-lr', [$lr]));
});
