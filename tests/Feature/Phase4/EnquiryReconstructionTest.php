<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Enquiry\EnquiryService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Models\Branch;
use App\Models\City;
use App\Models\Company;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\State;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
    $this->seed(SubscriptionCatalogueSeeder::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

/**
 * @return array{state: State, mumbai: City, pune: City}
 */
function enquiryGeo(): array
{
    $state = State::query()->firstOrCreate(
        ['country_code' => 'IN', 'code' => 'MH'],
        ['id' => (string) Str::uuid(), 'name' => 'Maharashtra', 'gst_code' => '27', 'is_active' => true],
    );

    $city = fn (string $name): City => City::query()->firstOrCreate(
        ['state_id' => $state->id, 'name_normalized' => City::normalizeName($name)],
        ['id' => (string) Str::uuid(), 'name' => $name, 'is_active' => true],
    );

    $mumbai = $city('Mumbai');
    $pune = $city('Pune');

    return compact('state', 'mumbai', 'pune');
}

test('manual enquiry accepts location-only route with party name', function () {
    $geo = enquiryGeo();
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Recon Manual Co');
    $branch = $company->branches()->firstOrFail();

    $truck = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'name' => 'Recon Truck',
        'is_active' => true,
    ]);
    $goodsType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::GoodsType->value,
        'name' => 'Recon Goods',
        'is_active' => true,
    ]);

    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: $branch->id,
        ownerId: (string) $company->owner_user_id,
    ));

    $enquiry = app(EnquiryService::class)->createManual($company, $branch, $owner, [
        'party_name' => 'Walk-in Traders',
        'from_state_id' => $geo['state']->id,
        'from_city_id' => $geo['mumbai']->id,
        'to_state_id' => $geo['state']->id,
        'to_city_id' => $geo['pune']->id,
        'vehicle_type_id' => $truck->id,
        'goods_type_id' => $goodsType->id,
        'weight_mt' => 12.5,
        'expected_loading_date' => '2026-12-01',
        'instructions' => 'Call before pickup',
    ]);

    expect($enquiry->party_name)->toBe('Walk-in Traders')
        ->and($enquiry->customer_party_id)->toBeNull()
        ->and($enquiry->from_city_id)->toBe($geo['mumbai']->id)
        ->and($enquiry->to_city_id)->toBe($geo['pune']->id)
        ->and($enquiry->pickup_location)->toContain('Mumbai')
        ->and($enquiry->goodsRows)->toHaveCount(1)
        ->and($enquiry->goodsRows->first()->actual_weight)->toBe('12500.000');
});

test('website v1 field mapping resolves goods weight and route text', function () {
    $geo = enquiryGeo();
    $owner = User::factory()->customer()->create();
    /** @var Company $company */
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Recon Web Co');

    $truck = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'name' => 'Tata 407',
        'is_active' => true,
    ]);
    $goodsType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::GoodsType->value,
        'name' => 'Tiles',
        'is_active' => true,
    ]);

    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: null,
        ownerId: (string) $company->owner_user_id,
        isOwnerScope: true,
    ));

    $result = app(EnquiryService::class)->createFromWebsite($company, [
        'external_reference' => 'v1-map-'.Str::lower(Str::random(6)),
        'business_name' => 'Acme Logistics',
        'contact_person_name' => 'Ravi',
        'contact_number' => '9876501234',
        'email' => 'ops@acme.test',
        'goods_type' => $goodsType->name,
        'weight_mt' => 2,
        'vehicle_type' => $truck->name,
        'pickup_date' => '2026-12-10',
        'from_state' => $geo['state']->name,
        'from_city' => $geo['mumbai']->name,
        'to_state' => $geo['state']->name,
        'to_city' => $geo['pune']->name,
        'notes' => 'Website v1 mapping',
    ]);

    $enquiry = $result['enquiry'];
    expect($result['created'])->toBeTrue()
        ->and($enquiry->external_business_name)->toBe('Acme Logistics')
        ->and($enquiry->external_contact_person_name)->toBe('Ravi')
        ->and($enquiry->from_city_id)->toBe($geo['mumbai']->id)
        ->and($enquiry->vehicle_type_id)->toBe($truck->id)
        ->and($enquiry->instructions)->toBe('Website v1 mapping')
        ->and($enquiry->goodsRows->first()->goods_type_id)->toBe($goodsType->id);
});

/**
 * @return array{owner: User, company: Company, branch: Branch, geo: array{state: State, mumbai: City, pune: City}, base: array<string, mixed>}
 */
function enquiryRouteFixture(): array
{
    $geo = enquiryGeo();
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Route Derivation Co');
    $branch = $company->branches()->firstOrFail();

    $catalogue = fn (OwnerCatalogueType $type): OwnerCatalogueItem => OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => $type->value,
        'is_active' => true,
    ]);

    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: $branch->id,
        ownerId: (string) $company->owner_user_id,
    ));

    return [
        'owner' => $owner,
        'company' => $company,
        'branch' => $branch,
        'geo' => $geo,
        'base' => [
            'party_name' => 'Route Party',
            'vehicle_type_id' => $catalogue(OwnerCatalogueType::TruckType)->id,
            'goods_type_id' => $catalogue(OwnerCatalogueType::GoodsType)->id,
            'weight_mt' => 5,
        ],
    ];
}

function enquiryRouteParty(Company $company, PartyRoleType $role, ?City $city): Party
{
    $party = Party::factory()->create([
        'company_id' => $company->id,
        'state_id' => $city?->state_id,
        'city_id' => $city?->id,
    ]);
    PartyRole::factory()->create(['company_id' => $company->id, 'party_id' => $party->id, 'role' => $role->value]);

    return $party;
}

test('manual enquiry derives each route side from the consignor and consignee master location', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch, 'geo' => $geo, 'base' => $base] = enquiryRouteFixture();
    $consignor = enquiryRouteParty($company, PartyRoleType::Consignor, $geo['mumbai']);
    $consignee = enquiryRouteParty($company, PartyRoleType::Consignee, $geo['pune']);

    $enquiry = app(EnquiryService::class)->createManual($company, $branch, $owner, [
        ...$base,
        'consignor_party_id' => $consignor->id,
        'consignee_party_id' => $consignee->id,
        'from_state_id' => $geo['state']->id,
        'from_city_id' => $geo['pune']->id,
        'to_state_id' => $geo['state']->id,
        'to_city_id' => $geo['mumbai']->id,
    ]);

    expect($enquiry->from_city_id)->toBe($geo['mumbai']->id)
        ->and($enquiry->to_city_id)->toBe($geo['pune']->id)
        ->and($enquiry->pickup_location)->toContain('Mumbai')
        ->and($enquiry->delivery_location)->toContain('Pune');
});

test('manual enquiry uses the submitted route for a side whose party has no master location', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch, 'geo' => $geo, 'base' => $base] = enquiryRouteFixture();
    $consignor = enquiryRouteParty($company, PartyRoleType::Consignor, $geo['mumbai']);
    $consignee = enquiryRouteParty($company, PartyRoleType::Consignee, null);

    $enquiry = app(EnquiryService::class)->createManual($company, $branch, $owner, [
        ...$base,
        'consignor_party_id' => $consignor->id,
        'consignee_party_id' => $consignee->id,
        'to_state_id' => $geo['state']->id,
        'to_city_id' => $geo['pune']->id,
    ]);

    expect($enquiry->from_city_id)->toBe($geo['mumbai']->id)
        ->and($enquiry->to_city_id)->toBe($geo['pune']->id);
});

test('manual enquiry requires the destination when the consignee has no master location', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch, 'geo' => $geo, 'base' => $base] = enquiryRouteFixture();
    $consignor = enquiryRouteParty($company, PartyRoleType::Consignor, $geo['mumbai']);

    expect(fn () => app(EnquiryService::class)->createManual($company, $branch, $owner, [
        ...$base,
        'consignor_party_id' => $consignor->id,
    ]))->toThrow(function (ValidationException $e): void {
        expect($e->errors())->toHaveKey('to_state_id')->not->toHaveKey('from_state_id');
    });
});

test('manual enquiry reports invalid submitted geography against the route field', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch, 'geo' => $geo, 'base' => $base] = enquiryRouteFixture();

    expect(fn () => app(EnquiryService::class)->createManual($company, $branch, $owner, [
        ...$base,
        'from_state_id' => $geo['state']->id,
        'from_city_id' => (string) Str::uuid(),
        'to_state_id' => $geo['state']->id,
        'to_city_id' => $geo['pune']->id,
    ]))->toThrow(function (ValidationException $e): void {
        expect($e->errors())->toHaveKey('from_city_id');
    });
});

test('replacing the consignor on edit recomputes the origin from the new party', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch, 'geo' => $geo, 'base' => $base] = enquiryRouteFixture();
    $enquiry = app(EnquiryService::class)->createManual($company, $branch, $owner, [
        ...$base,
        'consignor_party_id' => enquiryRouteParty($company, PartyRoleType::Consignor, $geo['mumbai'])->id,
        'to_state_id' => $geo['state']->id,
        'to_city_id' => $geo['mumbai']->id,
    ]);

    $updated = app(EnquiryService::class)->updateManual($company, $branch, $enquiry, $owner, [
        'consignor_party_id' => enquiryRouteParty($company, PartyRoleType::Consignor, $geo['pune'])->id,
    ], 'Consignor changed');

    expect($updated->from_city_id)->toBe($geo['pune']->id)
        ->and($updated->to_city_id)->toBe($geo['mumbai']->id);
});
