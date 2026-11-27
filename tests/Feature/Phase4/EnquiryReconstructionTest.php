<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Enquiry\EnquiryService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Models\City;
use App\Models\Company;
use App\Models\OwnerCatalogueItem;
use App\Models\State;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Str;

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
    $state = State::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Maharashtra',
        'code' => 'MH',
        'gst_code' => '27',
        'country_code' => 'IN',
        'is_active' => true,
    ]);

    $mumbai = City::query()->create([
        'id' => (string) Str::uuid(),
        'state_id' => $state->id,
        'name' => 'Mumbai',
        'name_normalized' => City::normalizeName('Mumbai'),
        'is_active' => true,
    ]);

    $pune = City::query()->create([
        'id' => (string) Str::uuid(),
        'state_id' => $state->id,
        'name' => 'Pune',
        'name_normalized' => City::normalizeName('Pune'),
        'is_active' => true,
    ]);

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
