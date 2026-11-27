<?php

/**
 * End-to-end verification for Subscription Activation, Customer Owner vs User
 * permissions, 11-state module/lifecycle combination matrix, and cache/session sync.
 */

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Domains\Subscription\Enums\SubscriptionLifecycleStatus;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Payments\Gateway\FakePaymentGateway;
use App\Domains\Subscription\Payments\PaymentEventProcessor;
use App\Domains\Subscription\Payments\PaymentIntentService;
use App\Domains\Subscription\Purchase\SubscriptionPurchaseService;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\City;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionTrial;
use App\Models\CustomerWebSession;
use App\Models\GstRate;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\PaymentIntent;
use App\Models\PlatformBillingProfile;
use App\Models\Role;
use App\Models\State;
use App\Models\Station;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTaxSetting;
use App\Models\TransporterVoucher;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    app()->forgetInstance(SubscriptionClock::class);
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();

    config([
        'subscription.payments.razorpay.key_id' => '',
        'subscription.payments.razorpay.key_secret' => '',
        'subscription.payments.razorpay.webhook_secret' => '',
    ]);

    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
    app()->forgetInstance(SubscriptionClock::class);
});

function matrixSeedBillingAndGeo(): void
{
    $mh = State::query()->firstOrCreate(
        ['code' => 'MH'],
        ['id' => (string) Str::uuid(), 'name' => 'Maharashtra', 'gst_code' => '27', 'is_active' => true, 'country_code' => 'IN'],
    );
    City::query()->firstOrCreate(
        ['state_id' => $mh->id, 'name_normalized' => 'pune'],
        ['id' => (string) Str::uuid(), 'name' => 'Pune', 'is_active' => true],
    );
    $gst18 = GstRate::query()->firstOrCreate(
        ['rate_percent' => '18.000', 'version' => 1],
        ['id' => (string) Str::uuid(), 'effective_from' => '2025-04-01', 'is_active' => true],
    );
    $pune = City::query()->firstOrFail();

    if (! PlatformBillingProfile::query()->where('is_active', true)->exists()) {
        PlatformBillingProfile::query()->create([
            'id' => (string) Str::uuid(),
            'legal_name' => 'TruckBill Technologies Pvt Ltd',
            'pan' => 'AAACT1234A',
            'contact_number' => '9876543210',
            'email' => 'billing@truckbill.test',
            'registered_address' => '100 Trade Center',
            'city_id' => $pune->id,
            'city' => 'Pune',
            'state_id' => $mh->id,
            'state_code' => '27',
            'state_name' => 'Maharashtra',
            'pin_code' => '411001',
            'gstin' => '27AAACT1234A1Z5',
            'is_active' => true,
        ]);
    }

    if (SubscriptionTaxSetting::current() === null) {
        SubscriptionTaxSetting::query()->create([
            'gst_rate_id' => $gst18->id,
            'gst_rate_percent' => $gst18->rate_percent,
        ]);
    }
}

function matrixLoginAs(User $user, string $password = 'secret-123'): void
{
    test()->flushSession();
    auth()->guard('web')->logout();
    app('auth')->forgetGuards();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();

    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

/**
 * @param  list<string>  $modules
 * @param  list<string>  $addonCodes
 */
function matrixActivateCompany(
    User $owner,
    Company $company,
    array $modules,
    array $addonCodes = [],
    ?CarbonImmutable $paidThrough = null,
    ?CarbonImmutable $graceEndsOn = null,
): void {
    CompanySubscriptionTrial::query()->where('owner_user_id', $owner->id)->delete();

    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $planIds = [];
    foreach ($modules as $module) {
        $plan = SubscriptionPlan::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('module', $module)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->firstOrFail();
        $planIds[] = $plan->id;
    }

    $addonIds = [];
    foreach ($addonCodes as $code) {
        $addon = SubscriptionAddon::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('code', $code)
            ->where('is_active', true)
            ->firstOrFail();
        $addonIds[] = $addon->id;
    }

    test()->actingAs($owner);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: $planIds,
        addonIds: $addonIds,
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: $paidThrough ?? CarbonImmutable::parse('2027-12-31'),
    );

    $clock = app(SubscriptionClock::class);
    $lifecycle = new SubscriptionLifecycleService(clock: $clock);
    $sub = $lifecycle->activateAfterVerifiedPayment(
        $draft,
        $paidThrough ?? CarbonImmutable::parse('2027-12-31'),
        $owner,
    );

    if ($graceEndsOn !== null) {
        $sub->forceFill(['grace_ends_on' => $graceEndsOn->toDateString()])->save();
    }

    CompanyCapabilityResolver::invalidate($company);
}

test('broker purchase activates immediately without invoice approval and restores all broker navigation and operations for owner', function () {
    matrixSeedBillingAndGeo();

    $workspace = app(CompanyWorkspaceService::class);
    $owner = User::factory()->customer()->create(['password' => Hash::make('secret-123')]);
    $firstCompany = $workspace->createCompany($owner, 'First Trial Co');
    $company = $workspace->createCompany($owner, 'Sahyadri Broker Co');
    $branch = $company->branches()->firstOrFail();

    expect(CompanySubscriptionTrial::query()->where('company_id', $company->id)->exists())->toBeFalse();

    matrixLoginAs($owner);

    // Before purchase: second company has no active subscription -> operations nav hidden.
    $this->post(route('workspace.context.branch', ['company' => $company, 'branch' => $branch]))
        ->assertRedirect(route('branch.dashboard', absolute: false));

    $this->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.capabilities.active_modules', [])
            ->where('workspace.navigation.bilty_register', false)
            ->where('workspace.navigation.broker_lorry_hires', false)
            ->where('workspace.navigation.masters_parties', true)
        );

    $this->get(route('branch.broker.lorry-hires.index'))->assertStatus(403);

    // Purchase Broker plan ONLY through HTTP checkout + fake payment verification.
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $brokerPlan = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('module', SubscriptionModule::Broker->value)
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->firstOrFail();

    $this->post(route('owner.companies.subscription.store', ['company' => $company]), [
        'plan_ids' => [$brokerPlan->id],
        'tenure' => SubscriptionTenure::Yearly->value,
        'mode' => 'initial',
    ])->assertRedirect();

    $intent = PaymentIntent::query()
        ->where('company_id', $company->id)
        ->latest('created_at')
        ->firstOrFail();

    $this->post(route('owner.companies.subscription.checkout.simulate', [
        'company' => $company,
        'intent' => $intent,
    ]))->assertRedirect(route('owner.companies.subscription.purchase', $company));

    // Verify subscription active immediately while invoice remains pending approval.
    $subscription = CompanySubscription::query()
        ->where('company_id', $company->id)
        ->where('status', 'active')
        ->first();
    expect($subscription)->not->toBeNull()
        ->and($subscription->activated_at)->not->toBeNull();

    $invoice = SubscriptionInvoice::query()
        ->where('company_id', $company->id)
        ->latest('created_at')
        ->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe(SubscriptionInvoice::STATUS_PENDING_APPROVAL);

    // Re-enter Branch Panel immediately in the same session (no logout / no Cache::flush).
    $this->post(route('workspace.context.branch', ['company' => $company, 'branch' => $branch]))
        ->assertRedirect(route('branch.dashboard', absolute: false));

    $this->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.capabilities.lifecycle_status', SubscriptionLifecycleStatus::Active->value)
            ->where('workspace.capabilities.active_modules', ['broker'])
            ->where('workspace.navigation.enquiries', true)
            ->where('workspace.navigation.bilty_register', true)
            ->where('workspace.navigation.create_bilty', true)
            ->where('workspace.navigation.broker_lorry_hires', true)
            ->where('workspace.navigation.broker_vouchers', true)
            ->where('workspace.navigation.broker_daily_payments', true)
            ->where('workspace.navigation.broker_deliveries', true)
            ->where('workspace.navigation.broker_linked_bilties', true)
            ->where('workspace.navigation.broker_transporter_balances', true)
            ->where('workspace.navigation.customer_invoices', true)
            ->where('workspace.navigation.customer_payments', true)
            ->where('workspace.navigation.broker_registers', true)
            ->where('workspace.navigation.masters_parties', true)
            ->where('workspace.navigation.masters_vehicles', true)
            ->where('workspace.navigation.masters_bank_cash', true)
            ->where('workspace.navigation.masters_stations', true)
            ->where('workspace.navigation.masters_goods_types', true)
            ->where('workspace.navigation.masters_document_types', true)
            ->where('workspace.navigation.masters_package_types', true)
            // Unsubscribed modules must remain hidden.
            ->where('workspace.navigation.ownfleet_vehicle_drivers', false)
            ->where('workspace.navigation.partload_challans', false)
            ->where('workspace.navigation.accounting', false)
            ->where('workspace.navigation.masters_truck_types', false)
            ->where('workspace.navigation.masters_tyre_layouts', false)
            ->where('workspace.navigation.masters_expense_categories', false)
        );

    $geo = geographyIdsFor('Maharashtra', 'Pune');

    // Master + Quick Add endpoints work for Broker Owner without branch role.
    $this->post(route('branch.parties.store'), [
        'name' => 'Broker Billing Customer',
        'contact_number' => '9811100001',
        'state_id' => $geo['state_id'],
        'city_id' => $geo['city_id'],
        'pan' => 'AAACB1111A',
        'gstin' => '27AAACB1111A1Z5',
        'is_gst_applicable' => true,
        'roles' => [
            ['role' => PartyRoleType::Customer->value],
            ['role' => PartyRoleType::Consignor->value],
            ['role' => PartyRoleType::Consignee->value],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->post(route('branch.parties.store'), [
        'name' => 'Broker Roadlines Transporter',
        'contact_number' => '9811100002',
        'state_id' => $geo['state_id'],
        'city_id' => $geo['city_id'],
        'pan' => 'AAACT2222B',
        'is_gst_applicable' => false,
        'roles' => [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan_status' => 'valid',
                'declaration' => true,
                'declaration_type' => 'owner',
                'goods_vehicles_count' => 2,
            ],
        ]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $customer = Party::query()->where('company_id', $company->id)->where('name', 'Broker Billing Customer')->firstOrFail();
    $transporter = Party::query()->where('company_id', $company->id)->where('name', 'Broker Roadlines Transporter')->firstOrFail();

    $this->withCredentials()->getJson(route('branch.parties.options', ['role' => PartyRoleType::Customer->value]))->assertOk();
    $this->withCredentials()->getJson(route('branch.vehicles.options'))->assertOk();

    // Market vehicle allowed; Own vehicle blocked for Broker-only.
    $this->post(route('branch.vehicles.store'), [
        'registration_number' => 'MH12BR0001',
        'ownership' => VehicleOwnership::Market->value,
        'owner_party_id' => $transporter->id,
    ])->assertRedirect();

    $this->post(route('branch.vehicles.store'), [
        'registration_number' => 'MH12OWN999',
        'ownership' => VehicleOwnership::Own->value,
    ])->assertStatus(403);

    $vehicle = Vehicle::query()->where('company_id', $company->id)->where('registration_number', 'MH12BR0001')->firstOrFail();
    $fromStation = Station::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Pune Depot', 'is_active' => true]);
    $toStation = Station::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Mumbai Port', 'is_active' => true]);
    $goodsType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::GoodsType->value,
        'name' => 'Machinery',
        'code' => 'MACH',
        'is_active' => true,
    ]);
    $packageType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::PackageType->value,
        'name' => 'Crate',
        'code' => 'CRATE',
        'is_active' => true,
    ]);

    // Real Broker operational workflow: Enquiry -> Bilty -> Lorry Hire -> Voucher -> Registers / Invoices / Balances.
    $this->get(route('branch.enquiries.index'))->assertOk();
    $this->get(route('branch.lorry-receipts.index'))->assertOk();
    $this->get(route('branch.lorry-receipts.create'))->assertOk();

    $this->post(route('branch.lorry-receipts.store'), [
        'form_mode' => 'truck',
        'vehicle_id' => $vehicle->id,
        'number_mode' => 'auto',
        'lr_date' => now()->toDateString(),
        'from_text' => 'Pune',
        'to_text' => 'Mumbai',
        'billing_customer_party_id' => $customer->id,
        'consignor_party_id' => $customer->id,
        'consignee_party_id' => $customer->id,
        'intended_payee_type' => 'owner',
        'intended_payee_party_id' => $transporter->id,
        'goods' => [[
            'goods_type_id' => $goodsType->id,
            'package_type_id' => $packageType->id,
            'goods_description' => 'CNC Lathe',
            'package_count' => 2,
            'actual_weight' => 2500,
            'charged_weight' => 2500,
            'freight_rate_type' => FreightRateType::FixedAmount->value,
            'freight_rate' => 24000,
            'goods_value' => 450000,
        ]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $lr = LorryReceipt::query()->where('company_id', $company->id)->firstOrFail();
    $lr->load('goodsRows');
    expect($lr->workflow_context)->toBe(LrWorkflowContext::Broker);

    $this->post(route('branch.broker.lorry-hires.store-from-lr', ['lorryReceipt' => $lr]), [
        'hire_date' => now()->toDateString(),
        'transporter_party_id' => $transporter->id,
        'goods_lines' => [[
            'lr_goods_row_id' => $lr->goodsRows->firstOrFail()->id,
            'rate_type' => FreightRateType::FixedAmount->value,
            'rate' => 18000,
        ]],
    ])->assertRedirect();

    $hire = LorryHire::query()->where('company_id', $company->id)->where('lorry_receipt_id', $lr->id)->firstOrFail();

    $this->post(route('branch.broker.vouchers.store'), [
        'lorry_hire_id' => $hire->id,
        'amount' => 5000,
        'payment_type' => 'advance',
        'payment_mode' => 'cash',
        'cash_reference' => 'CASH-001',
    ])->assertRedirect();

    expect(TransporterVoucher::query()->where('company_id', $company->id)->where('lorry_hire_id', $hire->id)->exists())->toBeTrue();

    // All remaining Broker & shared GET surfaces respond 200 OK (and legacy /broker/invoices redirects to unified /invoices?source=broker).
    foreach ([
        'branch.broker.lorry-hires.index',
        'branch.broker.vouchers.index',
        'branch.broker.daily-payments.index',
        'branch.broker.deliveries.index',
        'branch.broker.linked-bilties.index',
        'branch.broker.transporter-balances.index',
        'branch.broker.registers.index',
        'branch.broker.invoices.create',
        'branch.invoices.index',
        'branch.payments.index',
    ] as $routeName) {
        $this->get(route($routeName))->assertOk();
    }
    $this->get(route('branch.broker.invoices.index'))
        ->assertRedirect(route('branch.invoices.index', ['source' => 'broker']));

    // Unsubscribed OwnFleet, Partload & Accounting routes remain 403.
    foreach ([
        'branch.trips.index',
        'branch.vehicle-drivers.index',
        'branch.trip-invoices.index',
        'branch.partload.challans.index',
        'branch.accounting.vouchers.index',
    ] as $blockedRoute) {
        $this->get(route($blockedRoute))->assertStatus(403);
    }
});

test('abandoned draft or pending_payment contract does not mask an active paid subscription', function () {
    $workspace = app(CompanyWorkspaceService::class);
    $owner = User::factory()->customer()->create(['password' => Hash::make('secret-123')]);
    $workspace->createCompany($owner, 'Trial Holder');
    $company = $workspace->createCompany($owner, 'Precedence Broker Co');
    $branch = $company->branches()->firstOrFail();

    matrixActivateCompany($owner, $company, [SubscriptionModule::Broker->value]);

    // Simulate a newer abandoned checkout left in pending_payment / draft.
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $ownFleetPlan = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('module', SubscriptionModule::OwnFleet->value)
        ->where('is_active', true)
        ->firstOrFail();

    $abandoned = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$ownFleetPlan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2028-01-01'),
    );
    $abandoned->forceFill([
        'status' => 'pending_payment',
        'created_at' => now()->addMinute(),
    ])->save();

    CompanyCapabilityResolver::invalidate($company);
    $resolved = app(CompanyCapabilityResolver::class)->resolve($company);

    expect($resolved['lifecycle_status'])->toBe(SubscriptionLifecycleStatus::Active->value)
        ->and($resolved['active_modules'])->toBe(['broker'])
        ->and($resolved['capabilities'][CompanyCapabilities::BROKER_LORRY_HIRE]['mutate'])->toBeTrue();

    matrixLoginAs($owner);
    seedBranchSession($company, $branch);
    $this->get(route('branch.broker.lorry-hires.index'))->assertOk();
});

test('subscription combination matrix enforces exact navigation and route access across all 11 states', function () {
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);
    $clock = app(SubscriptionClock::class);
    $clock->freeze(CarbonImmutable::parse('2026-11-27 10:00:00', 'UTC'));
    app()->instance(SubscriptionClock::class, $clock);

    // Grant customer.admin all customer codes except DELETE so ordinary-user RBAC vs entitlement boundary is isolated.
    $allCustomerCodes = PermissionCodes::customerAllCodes();
    foreach ($allCustomerCodes as $code) {
        tripGrant('customer.admin', $code);
    }

    $owner = User::factory()->customer()->create(['password' => Hash::make('secret-123')]);
    $workspace->createCompany($owner, 'Trial Anchor Co');

    $setupCase = function (string $name, array $modules, array $addons = [], ?CarbonImmutable $paidThrough = null, ?CarbonImmutable $graceEndsOn = null) use ($workspace, $rbac, $owner): array {
        $company = $workspace->createCompany($owner, $name);
        $branch = $company->branches()->firstOrFail();

        if ($modules !== []) {
            matrixActivateCompany($owner, $company, $modules, $addons, $paidThrough, $graceEndsOn);
        }

        $member = User::factory()->customer()->create();
        $membership = $workspace->addMembership($company, $member, 'Member');
        $assignment = $workspace->assignBranch($membership, $branch, 'HO');
        $rbac->assignCustomerBranchRole(
            $owner,
            $membership,
            $branch,
            $assignment,
            Role::query()->where('domain', PermissionDomain::Customer->value)->where('slug', 'customer.admin')->firstOrFail(),
            'Admin role',
        );

        $viewer = User::factory()->customer()->create();
        $vMembership = $workspace->addMembership($company, $viewer, 'Viewer');
        $vAssignment = $workspace->assignBranch($vMembership, $branch, 'HO');
        $rbac->assignCustomerBranchRole(
            $owner,
            $vMembership,
            $branch,
            $vAssignment,
            Role::query()->where('domain', PermissionDomain::Customer->value)->where('slug', 'customer.viewer')->firstOrFail(),
            'Viewer role',
        );

        return compact('company', 'branch', 'member', 'viewer');
    };

    $cases = [
        'broker_only' => [
            'modules' => [SubscriptionModule::Broker->value],
            'addons' => [],
            'broker' => true,
            'ownfleet' => false,
            'partload' => false,
            'accounting' => false,
            'mutate' => true,
        ],
        'ownfleet_only' => [
            'modules' => [SubscriptionModule::OwnFleet->value],
            'addons' => [],
            'broker' => false,
            'ownfleet' => true,
            'partload' => false,
            'accounting' => false,
            'mutate' => true,
        ],
        'partload_only' => [
            'modules' => [SubscriptionModule::Partload->value],
            'addons' => [],
            'broker' => false,
            'ownfleet' => false,
            'partload' => true,
            'accounting' => false,
            'mutate' => true,
        ],
        'broker_ownfleet' => [
            'modules' => [SubscriptionModule::Broker->value, SubscriptionModule::OwnFleet->value],
            'addons' => [],
            'broker' => true,
            'ownfleet' => true,
            'partload' => false,
            'accounting' => false,
            'mutate' => true,
        ],
        'broker_partload' => [
            'modules' => [SubscriptionModule::Broker->value, SubscriptionModule::Partload->value],
            'addons' => [],
            'broker' => true,
            'ownfleet' => false,
            'partload' => true,
            'accounting' => false,
            'mutate' => true,
        ],
        'all_transport_without_accounting' => [
            'modules' => [SubscriptionModule::Broker->value, SubscriptionModule::OwnFleet->value, SubscriptionModule::Partload->value],
            'addons' => [],
            'broker' => true,
            'ownfleet' => true,
            'partload' => true,
            'accounting' => false,
            'mutate' => true,
        ],
        'broker_with_accounting' => [
            'modules' => [SubscriptionModule::Broker->value],
            'addons' => ['accounting'],
            'broker' => true,
            'ownfleet' => false,
            'partload' => false,
            'accounting' => true,
            'mutate' => true,
        ],
        'paid_grace_broker' => [
            'modules' => [SubscriptionModule::Broker->value],
            'addons' => [],
            'paidThrough' => CarbonImmutable::parse('2026-11-20'),
            'graceEndsOn' => CarbonImmutable::parse('2026-11-30'),
            'broker' => true,
            'ownfleet' => false,
            'partload' => false,
            'accounting' => false,
            'mutate' => false,
        ],
        'expired_broker' => [
            'modules' => [SubscriptionModule::Broker->value],
            'addons' => [],
            'paidThrough' => CarbonImmutable::parse('2026-11-01'),
            'graceEndsOn' => CarbonImmutable::parse('2026-11-11'),
            'broker' => true,
            'ownfleet' => false,
            'partload' => false,
            'accounting' => false,
            'mutate' => false,
        ],
    ];

    $resolver = app(CompanyCapabilityResolver::class);
    $authorizer = app(PermissionAuthorizer::class);

    foreach ($cases as $label => $spec) {
        $ctx = $setupCase(
            "Case {$label}",
            $spec['modules'],
            $spec['addons'],
            $spec['paidThrough'] ?? null,
            $spec['graceEndsOn'] ?? null,
        );

        foreach ([$owner, $ctx['member']] as $actor) {
            $nav = $resolver->navigationForUser($actor, $ctx['company'], $ctx['branch'], $authorizer);

            expect($nav['bilty_register'])->toBeTrue("{$label}: bilty_register")
                ->and($nav['create_bilty'])->toBe($spec['mutate'], "{$label}: create_bilty")
                ->and($nav['enquiries'])->toBeTrue("{$label}: enquiries")
                ->and($nav['customer_invoices'])->toBeTrue("{$label}: customer_invoices")
                ->and($nav['customer_payments'])->toBeTrue("{$label}: customer_payments")
                ->and($nav['broker_lorry_hires'])->toBe($spec['broker'], "{$label}: broker_lorry_hires")
                ->and($nav['broker_registers'])->toBe($spec['broker'], "{$label}: broker_registers")
                ->and($nav['ownfleet_vehicle_drivers'])->toBe($spec['ownfleet'], "{$label}: ownfleet_vehicle_drivers")
                ->and($nav['partload_challans'])->toBe($spec['partload'], "{$label}: partload_challans")
                ->and($nav['accounting'])->toBe($spec['accounting'], "{$label}: accounting");
        }

        // Restricted customer.viewer lacks broker/lr role permissions even when Broker IS subscribed.
        $viewerNav = $resolver->navigationForUser($ctx['viewer'], $ctx['company'], $ctx['branch'], $authorizer);
        expect($viewerNav['broker_lorry_hires'])->toBeFalse("{$label}: viewer must still require role perm")
            ->and($viewerNav['bilty_register'])->toBeFalse("{$label}: viewer without LR_VIEW");

        matrixLoginAs($owner);
        enterBranchWorkspace($ctx['company'], $ctx['branch'])->assertRedirect();

        $this->get(route('branch.lorry-receipts.index'))->assertOk();
        $spec['broker']
            ? $this->get(route('branch.broker.lorry-hires.index'))->assertOk()
            : $this->get(route('branch.broker.lorry-hires.index'))->assertStatus(403);
        $spec['partload']
            ? $this->get(route('branch.partload.challans.index'))->assertOk()
            : $this->get(route('branch.partload.challans.index'))->assertStatus(403);
        $spec['accounting']
            ? $this->get(route('branch.accounting.vouchers.index'))->assertOk()
            : $this->get(route('branch.accounting.vouchers.index'))->assertStatus(403);

        if (! $spec['mutate']) {
            $this->get(route('branch.lorry-receipts.create'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('branch/lorry-receipts/blocked'));
            $this->post(route('branch.lorry-receipts.store'), [])->assertStatus(403);
        }
    }

    // Active trial + same-day mid-day expiry automatically clears capability cache.
    $trialOwner = User::factory()->customer()->create();
    $trialCompany = $workspace->createCompany($trialOwner, 'Active Trial Co');
    $trialBranch = $trialCompany->branches()->firstOrFail();

    $trialRecord = CompanySubscriptionTrial::query()->where('company_id', $trialCompany->id)->firstOrFail();
    $trialRecord->forceFill([
        'ends_at' => CarbonImmutable::parse('2026-11-27 12:00:00', 'UTC'),
    ])->save();
    CompanyCapabilityResolver::invalidate($trialCompany);

    $trialNav = $resolver->navigationForUser($trialOwner, $trialCompany, $trialBranch, $authorizer);
    expect($trialNav['bilty_register'])->toBeTrue()
        ->and($trialNav['create_bilty'])->toBeTrue()
        ->and($trialNav['broker_lorry_hires'])->toBeTrue()
        ->and($trialNav['ownfleet_vehicle_drivers'])->toBeTrue()
        ->and($trialNav['partload_challans'])->toBeTrue();

    // Advance frozen clock past same-day trial expiry WITHOUT manual invalidation.
    $clock->freeze(CarbonImmutable::parse('2026-11-27 12:05:00', 'UTC'));
    $expiredTrialState = $resolver->resolve($trialCompany);
    expect($expiredTrialState['lifecycle_status'])->toBe(SubscriptionLifecycleStatus::Restricted->value)
        ->and($expiredTrialState['mutations_allowed'])->toBeFalse();
});

test('company switching and mid-term upgrade refresh capabilities and navigation without logout or manual cache clear', function () {
    matrixSeedBillingAndGeo();

    $workspace = app(CompanyWorkspaceService::class);
    $owner = User::factory()->customer()->create(['password' => Hash::make('secret-123')]);

    // Company A holds expired trial (restricted). Company B holds Broker.
    $companyA = $workspace->createCompany($owner, 'Company A Expired');
    $branchA = $companyA->branches()->firstOrFail();
    CompanySubscriptionTrial::query()->where('company_id', $companyA->id)->update([
        'ends_at' => now()->subDay(),
    ]);
    CompanyCapabilityResolver::invalidate($companyA);

    $companyB = $workspace->createCompany($owner, 'Company B Broker');
    $branchB = $companyB->branches()->firstOrFail();
    matrixActivateCompany($owner, $companyB, [SubscriptionModule::Broker->value]);

    matrixLoginAs($owner);

    // Enter Company A -> restricted.
    $this->post(route('workspace.context.branch', ['company' => $companyA, 'branch' => $branchA]))->assertRedirect();
    $this->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.active_company_id', $companyA->id)
            ->where('workspace.capabilities.mutations_allowed', false)
        );

    // Switch directly to Company B in the same web session -> Broker active immediately.
    $this->post(route('workspace.context.branch', ['company' => $companyB, 'branch' => $branchB]))->assertRedirect();
    $this->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.active_company_id', $companyB->id)
            ->where('workspace.capabilities.mutations_allowed', true)
            ->where('workspace.capabilities.active_modules', ['broker'])
            ->where('workspace.navigation.broker_lorry_hires', true)
            ->where('workspace.navigation.ownfleet_vehicle_drivers', false)
            ->where('workspace.navigation.accounting', false)
        );

    // Mid-term upgrade of Company B to add OwnFleet + Accounting via Purchase + FakePaymentGateway callback.
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $ownFleetPlan = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('module', SubscriptionModule::OwnFleet->value)
        ->where('is_active', true)
        ->firstOrFail();
    $accountingAddon = SubscriptionAddon::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('code', 'accounting')
        ->where('is_active', true)
        ->firstOrFail();

    $purchase = app(SubscriptionPurchaseService::class)->initiateCheckout(
        actor: $owner,
        company: $companyB,
        input: [
            'plan_ids' => [$ownFleetPlan->id],
            'addon_ids' => [$accountingAddon->id],
            'mode' => 'upgrade',
        ],
        paymentIntents: app(PaymentIntentService::class),
    );

    $gateway = new FakePaymentGateway;
    $intent = $purchase['intent'];
    $rawPayload = json_encode([
        'event_id' => 'evt_upgrade_'.Str::random(8),
        'event_type' => 'payment.captured',
        'order_id' => $intent->gateway_order_id,
        'payment_id' => 'pay_upgrade_'.Str::random(8),
        'status' => 'captured',
        'amount' => $intent->amount,
        'currency' => $intent->currency,
    ], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $rawPayload, $gateway->secret());

    (new PaymentEventProcessor($gateway))->processRawWebhook($rawPayload, $signature);

    // Next Inertia request in the same session sees OwnFleet + Accounting immediately without logout or Cache::flush.
    $this->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.capabilities.active_modules', ['broker', 'ownfleet'])
            ->where('workspace.navigation.broker_lorry_hires', true)
            ->where('workspace.navigation.ownfleet_vehicle_drivers', true)
            ->where('workspace.navigation.accounting', true)
        );
});
