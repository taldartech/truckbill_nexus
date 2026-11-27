<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionMasterService;
use App\Domains\Subscription\Billing\PlatformBillingProfileService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Purchase\SubscriptionPurchaseService;
use App\Models\City;
use App\Models\CompanySubscription;
use App\Models\GstRate;
use App\Models\InternalRoleAssignment;
use App\Models\PaymentIntent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\State;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTaxSetting;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

/**
 * @return array{superAdmin: User, mh: State, gj: State, gst18: GstRate, gst5: GstRate}
 */
function catalogueAdminFixture(): array
{
    $mh = State::query()->firstOrCreate(
        ['code' => 'MH'],
        ['id' => (string) Str::uuid(), 'name' => 'Maharashtra', 'gst_code' => '27', 'is_active' => true, 'country_code' => 'IN'],
    );
    $gj = State::query()->firstOrCreate(
        ['code' => 'GJ'],
        ['id' => (string) Str::uuid(), 'name' => 'Gujarat', 'gst_code' => '24', 'is_active' => true, 'country_code' => 'IN'],
    );
    $pune = City::query()->firstOrCreate(
        ['state_id' => $mh->id, 'name_normalized' => 'pune'],
        ['id' => (string) Str::uuid(), 'name' => 'Pune', 'is_active' => true],
    );
    $gst18 = GstRate::query()->firstOrCreate(
        ['rate_percent' => '18.000', 'version' => 1],
        ['id' => (string) Str::uuid(), 'effective_from' => '2025-04-01', 'is_active' => true],
    );
    $gst5 = GstRate::query()->firstOrCreate(
        ['rate_percent' => '5.000', 'version' => 1],
        ['id' => (string) Str::uuid(), 'effective_from' => '2025-04-01', 'is_active' => true],
    );
    $superAdmin = User::factory()->internal()->superAdmin()->create();

    app(PlatformBillingProfileService::class)->create($superAdmin, [
        'legal_name' => 'TruckBill Catalogue Billing Pvt Ltd',
        'pan' => 'AABCT1234F',
        'contact_number' => '9876543210',
        'email' => 'catalogue@truckbill.test',
        'registered_address' => 'Pune',
        'state_id' => $mh->id,
        'city_id' => $pune->id,
        'pin_code' => '411001',
        'gstin' => '27AABCT1234F1Z5',
    ]);

    return compact('superAdmin', 'mh', 'gj', 'gst18', 'gst5');
}

function catalogueSalesUser(User $superAdmin): User
{
    $sales = User::factory()->internal()->create();
    $role = Role::query()->where('domain', PermissionDomain::Internal->value)->where('slug', 'internal.sales_executive')->firstOrFail();
    $permission = Permission::query()->where('code', 'internal.crm.view')->firstOrFail();
    app(PermissionMasterService::class)->assignPermissionToRole($superAdmin, $role, $permission, 'Sales may view CRM');
    InternalRoleAssignment::query()->create(['id' => (string) Str::uuid(), 'user_id' => $sales->id, 'role_id' => $role->id]);

    return $sales;
}

function brokerPlan(string $code = 'starter'): SubscriptionPlan
{
    return SubscriptionPlan::query()->where('module', 'broker')->where('code', $code)->firstOrFail();
}

test('billing profiles no longer carry a gst rate and the common subscription gst rate requires a remark to change', function () {
    $fx = catalogueAdminFixture();

    expect(Schema::hasColumn('platform_billing_profiles', 'gst_rate_id'))->toBeFalse()
        ->and(Schema::hasColumn('platform_billing_profiles', 'gst_rate_percent'))->toBeFalse();

    $this->actingAs($fx['superAdmin']);
    $this->put(route('admin.subscription-catalogue.gst.update'), ['gst_rate_id' => $fx['gst18']->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect((string) SubscriptionTaxSetting::current()?->gst_rate_percent)->toBe('18.00');

    $this->put(route('admin.subscription-catalogue.gst.update'), ['gst_rate_id' => $fx['gst5']->id])
        ->assertSessionHasErrors('remark');

    $this->put(route('admin.subscription-catalogue.gst.update'), [
        'gst_rate_id' => $fx['gst5']->id,
        'remark' => 'Rate revision for new purchases',
    ])->assertSessionHasNoErrors();

    expect((string) SubscriptionTaxSetting::current()?->gst_rate_percent)->toBe('5.00')
        ->and(AuditEntry::query()->where('subject_type', SubscriptionTaxSetting::class)->where('remark', 'Rate revision for new purchases')->exists())->toBeTrue();
});

test('sales users can view the catalogue and calculator but cannot edit prices', function () {
    $fx = catalogueAdminFixture();
    $sales = catalogueSalesUser($fx['superAdmin']);
    $this->actingAs($sales);

    $this->get(route('admin.subscription-catalogue'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/subscription-catalogue')
            ->where('canManage', false)
            ->where('plans.0.update_url', null));
    $this->get(route('admin.subscription-calculator'))->assertOk();

    $this->put(route('admin.subscription-catalogue.plans.update', brokerPlan()), [
        'name' => 'Broker Starter',
        'annual_price' => '1.00',
        'capacity_units' => 1200,
        'is_active' => true,
        'remark' => 'Attempt',
    ])->assertForbidden();
    $this->put(route('admin.subscription-catalogue.gst.update'), ['gst_rate_id' => $fx['gst18']->id])->assertForbidden();

    $this->actingAs(User::factory()->customer()->create());
    $this->get(route('admin.subscription-catalogue'))->assertForbidden();
});

test('catalogue edits require a remark, apply to future quotes and leave contract snapshots unchanged', function () {
    $fx = catalogueAdminFixture();
    $plan = brokerPlan();
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Snapshot Carriers');
    $contract = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: SubscriptionCatalogueVersion::query()->where('is_active', true)->firstOrFail(),
        planIds: [$plan->id],
        addonIds: [],
        tenure: SubscriptionTenure::Yearly,
        renewalEligible: false,
        commonRenewalDate: CarbonImmutable::now()->addYear(),
    );
    $snapshotItem = $contract->items()->where('plan_id', $plan->id)->firstOrFail();
    TenantContextHolder::clear();
    $this->actingAs($fx['superAdmin']);

    $payload = ['name' => 'Broker Starter', 'annual_price' => '9900.00', 'capacity_units' => 1500, 'is_active' => true];
    $this->put(route('admin.subscription-catalogue.plans.update', $plan), $payload)->assertSessionHasErrors('remark');

    $this->put(route('admin.subscription-catalogue.plans.update', $plan), [...$payload, 'remark' => 'Annual price revision'])
        ->assertSessionHasNoErrors();

    expect((string) $plan->fresh()->annual_price)->toBe('9900.00')
        ->and($plan->fresh()->capacity_units)->toBe(1500)
        ->and((string) $snapshotItem->fresh()->annual_price_snapshot)->toBe('9000.00');

    $quote = app(SubscriptionPurchaseService::class)->quoteForProspect(['plan_ids' => [$plan->id], 'tenure' => 'monthly']);
    expect($quote['line_items'][0]['unit_annual_price'])->toBe('9900.00');

    $storage = SubscriptionAddon::query()->where('code', 'extra_storage')->firstOrFail();
    $this->put(route('admin.subscription-catalogue.addons.update', $storage), [
        'name' => $storage->name,
        'annual_price' => '1300.00',
        'storage_gb_per_unit' => 10,
        'is_active' => true,
        'remark' => 'Bigger storage pack',
    ])->assertSessionHasNoErrors();
    expect((int) $storage->fresh()->storage_bytes_per_unit)->toBe(10 * 1073741824);

    $this->post(route('admin.subscription-catalogue.plans.store'), [
        'module' => 'partload',
        'name' => 'Partload Enterprise',
        'annual_price' => '45000',
        'capacity_units' => 40000,
    ])->assertSessionHasNoErrors();
    expect(SubscriptionPlan::query()->where('module', 'partload')->where('code', 'partload_enterprise')->exists())->toBeTrue();
});

test('calculator derives additional capacity, persists nothing and matches owner checkout pricing exactly', function () {
    $fx = catalogueAdminFixture();
    app(PlatformBillingProfileService::class)->updateSubscriptionGstRate($fx['superAdmin'], $fx['gst18']->id);
    $owfleet = SubscriptionPlan::query()->where('module', 'ownfleet')->where('code', 'starter')->firstOrFail();
    $accounting = SubscriptionAddon::query()->where('code', 'accounting')->firstOrFail();
    $subscriptionsBefore = CompanySubscription::query()->count();
    $intentsBefore = PaymentIntent::query()->count();

    $this->actingAs($fx['superAdmin']);
    $response = $this->postJson(route('admin.subscription-calculator.quote'), [
        'plan_ids' => [brokerPlan()->id, $owfleet->id],
        'addon_ids' => [$accounting->id],
        'tenure' => 'yearly',
        'total_branches' => 4,
        'total_users' => 10,
        'broker_lr_credits' => 2200,
        'total_storage_gb' => 12,
        'state_id' => $fx['gj']->id,
    ])->assertOk();

    $derived = collect($response->json('derived'))->pluck('quantity', 'code');
    expect($derived->all())->toBe([
        'extra_branch' => 2,
        'extra_user' => 4,
        'lr_credits_broker' => 2,
        'extra_storage' => 2,
    ])
        ->and($response->json('quote.discount_details.bundle_percent'))->toBe('10')
        ->and($response->json('quote.gst.is_inter_state'))->toBeTrue()
        ->and($response->json('quote.gst.gst_rate_percent'))->toBe('18.00')
        ->and(CompanySubscription::query()->count())->toBe($subscriptionsBefore)
        ->and(PaymentIntent::query()->count())->toBe($intentsBefore);

    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Parity Logistics');
    $company->forceFill(['state_id' => $fx['gj']->id, 'state' => 'Gujarat'])->save();
    $addons = SubscriptionAddon::query()->whereIn('code', array_keys($derived->all()))->get()->keyBy('code');
    $ownerQuote = app(SubscriptionPurchaseService::class)->quote($company, [
        'plan_ids' => [brokerPlan()->id, $owfleet->id],
        'addon_ids' => [$accounting->id],
        'addon_quantities' => $addons->mapWithKeys(fn (SubscriptionAddon $addon): array => [$addon->id => $derived[$addon->code]])->all(),
        'tenure' => 'yearly',
        'mode' => 'initial',
    ]);

    expect($response->json('quote.total_payable'))->toBe($ownerQuote['total_payable'])
        ->and($response->json('quote.taxable_amount'))->toBe($ownerQuote['taxable_amount'])
        ->and($response->json('quote.gst.total_tax_amount'))->toBe($ownerQuote['gst']['total_tax_amount']);
});
