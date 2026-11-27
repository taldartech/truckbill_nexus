<?php

use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Rbac\PermissionCodes;
use App\Models\OwnerCatalogueItem;
use App\Models\PartyRole;
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

test('enquiry create options include only active customers visible in the branch', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripGrant('customer.admin', PermissionCodes::ENQUIRY_CREATE);
    tripGrant('customer.admin', PermissionCodes::ENQUIRY_VIEW);

    $parties = app(PartyService::class);
    $owner = $ctx['owner'];
    $company = $ctx['company'];
    $branch = $ctx['branch'];

    $customer = $parties->create($owner, $company, null, [
        'name' => 'Eligible Customer',
        'contact_number' => '9811111111',
        'state' => 'MH',
        'city' => 'Pune',
    ], [['role' => PartyRoleType::Customer->value]], 'ec');

    $customerConsignor = $parties->create($owner, $company, null, [
        'name' => 'Customer Plus Consignor',
        'contact_number' => '9811111112',
        'state' => 'MH',
        'city' => 'Pune',
    ], [
        ['role' => PartyRoleType::Customer->value],
        ['role' => PartyRoleType::Consignor->value],
    ], 'ecc');

    $transporter = $parties->create($owner, $company, null, [
        'name' => 'Transporter Not Customer',
        'contact_number' => '9811111113',
        'state' => 'MH',
        'city' => 'Pune',
        'pan' => 'AABCT2222B',
        'is_gst_applicable' => false,
    ], [[
        'role' => PartyRoleType::Transporter->value,
        'attributes' => [
            'pan' => 'AABCT2222B',
            'pan_status' => 'active',
            'declaration' => true,
            'goods_vehicles_count' => 1,
        ],
    ]], 'tnc');

    $driver = $parties->create($owner, $company, null, [
        'name' => 'Driver Not Customer',
        'contact_number' => '9811111114',
        'state' => 'MH',
        'city' => 'Pune',
    ], [[
        'role' => PartyRoleType::Driver->value,
        'attributes' => [
            'driver_type' => 'market',
        ],
    ]], 'dnc');

    $inactive = $parties->create($owner, $company, null, [
        'name' => 'Inactive Customer Role',
        'contact_number' => '9811111115',
        'state' => 'MH',
        'city' => 'Pune',
    ], [['role' => PartyRoleType::Customer->value]], 'icr');

    PartyRole::query()
        ->where('party_id', $inactive->id)
        ->where('role', PartyRoleType::Customer->value)
        ->update(['deactivated_at' => now()]);

    rbacHttpAs($ctx['member'], $ctx);

    $this->get(route('branch.enquiries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/enquiries/create')
            ->has('parties')
            ->has('customers')
            ->where('parties', function ($options) use ($customer, $customerConsignor, $transporter, $driver, $inactive): bool {
                $ids = collect($options)->pluck('id')->all();

                return in_array($customer->id, $ids, true)
                    && in_array($customerConsignor->id, $ids, true)
                    && ! in_array($transporter->id, $ids, true)
                    && ! in_array($driver->id, $ids, true)
                    && ! in_array($inactive->id, $ids, true);
            }));
});

test('enquiry store rejects forged non-customer party id', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripGrant('customer.admin', PermissionCodes::ENQUIRY_CREATE);
    tripGrant('customer.admin', PermissionCodes::ENQUIRY_VIEW);

    $transporter = app(PartyService::class)->create(
        $ctx['owner'],
        $ctx['company'],
        null,
        [
            'name' => 'Forged Transporter',
            'contact_number' => '9822222222',
            'state' => 'MH',
            'city' => 'Pune',
            'pan' => 'AABCT3333C',
            'is_gst_applicable' => false,
        ],
        [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'AABCT3333C',
                'pan_status' => 'active',
                'declaration' => true,
                'goods_vehicles_count' => 1,
            ],
        ]],
        'forge',
    );

    $truck = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $ctx['owner']->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'name' => 'Truck',
        'code' => 'TRK-ELIG',
        'is_active' => true,
    ]);

    rbacHttpAs($ctx['member'], $ctx);

    $this->post(route('branch.enquiries.store'), [
        'customer_party_id' => $transporter->id,
        'pickup_location' => 'Pune',
        'delivery_location' => 'Mumbai',
        'vehicle_type_id' => $truck->id,
        'vehicle_quantity' => 1,
        'goods' => [[
            'goods_description' => 'Cargo',
            'package_count' => 1,
        ]],
    ])->assertSessionHasErrors('customer_party_id');
});
