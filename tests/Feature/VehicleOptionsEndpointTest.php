<?php

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Master\Vehicle\VehicleOptionQuery;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/../Support/RbacPersonaHelpers.php';

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

test('vehicle options endpoint returns searchable branch-visible vehicles', function () {
    $ctx = tripWorkspace([
        SubscriptionModule::Broker->value,
        SubscriptionModule::OwnFleet->value,
    ]);
    tripActivate($ctx['company'], $ctx['branch']);

    $match = Vehicle::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => null,
        'registration_number' => 'MH12VXSEARCH01',
        'is_active' => true,
        'ownership' => VehicleOwnership::Market->value,
    ]);

    Vehicle::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => null,
        'registration_number' => 'MH12VXOTHER99',
        'is_active' => true,
        'ownership' => VehicleOwnership::Own->value,
    ]);

    rbacHttpAs($ctx['owner'], $ctx);

    $this->getJson(route('branch.vehicles.options', [
        'q' => 'VXSEARCH',
        'limit' => 20,
        'ownership' => [VehicleOwnership::Market->value],
    ]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $match->id)
        ->assertJsonMissing(['registration_number' => 'MH12VXOTHER99']);
});

test('vehicle option query hydrates selected id beyond the bounded page', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $selected = null;
    for ($i = 1; $i <= 30; $i++) {
        $vehicle = Vehicle::query()->create([
            'company_id' => $ctx['company']->id,
            'branch_id' => null,
            'registration_number' => sprintf('MH12VXPAGE%02d', $i),
            'is_active' => true,
            'ownership' => VehicleOwnership::Market->value,
        ]);
        if ($i === 30) {
            $selected = $vehicle;
        }
    }

    $options = app(VehicleOptionQuery::class)->forBranch(
        company: $ctx['company'],
        branch: $ctx['branch'],
        limit: 10,
        ownerships: [VehicleOwnership::Market],
        selectedId: $selected?->id,
    );

    expect(collect($options)->pluck('id')->all())->toContain($selected->id)
        ->and(count($options))->toBeLessThanOrEqual(11);
});

test('json vehicle store returns option payload for quick create', function () {
    $ctx = tripWorkspace([SubscriptionModule::Broker->value]);
    tripActivate($ctx['company'], $ctx['branch']);
    rbacHttpAs($ctx['owner'], $ctx);

    TenantContextHolder::inject(new TenantContext(
        companyId: $ctx['company']->id,
        branchId: $ctx['branch']->id,
        ownerId: (string) $ctx['company']->owner_user_id,
    ));

    $ownerParty = app(PartyService::class)->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: [
            'name' => 'Quick Truck Owner',
            'contact_number' => '9777666555',
            'pan' => 'QXOWN1234P',
            'state' => 'MH',
            'city' => 'Pune',
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan_status' => 'active',
                'declaration' => true,
            ],
        ]],
        remark: 'Quick create owner',
    );

    $this->postJson(route('branch.vehicles.store'), [
        'registration_number' => 'MH12QXCREATE01',
        'ownership' => VehicleOwnership::Market->value,
        'owner_party_id' => $ownerParty->id,
        'vehicle_type' => 'Container',
        'is_active' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.registration_number', 'MH12QXCREATE01')
        ->assertJsonStructure([
            'data' => ['id', 'registration_number', 'ownership', 'label'],
        ]);
});
