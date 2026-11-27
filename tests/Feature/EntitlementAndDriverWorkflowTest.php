<?php

require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/../Support/PartloadHelpers.php';
require_once __DIR__.'/../Support/RbacPersonaHelpers.php';
require_once __DIR__.'/../Support/BrokerLorryHireHelpers.php';

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Master\Vehicle\VehicleService;
use App\Domains\OwnFleet\Driver\VehicleDriverAssignmentService;
use App\Domains\OwnFleet\Driver\VehicleDriverAttendanceService;
use App\Domains\OwnFleet\Enums\DriverAttendanceStatus;
use App\Domains\Partload\Challan\PartloadChallanService;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanySubscriptionTrial;
use App\Models\LorryReceipt;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\Role;
use App\Models\Station;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDriverAssignment;
use App\Models\VehicleDriverAttendance;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    app()->forgetInstance(SubscriptionClock::class);
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
    app()->forgetInstance(SubscriptionClock::class);
});

function asBranchHttp(User $user, Company $company, Branch $branch): void
{
    rbacHttpAs($user, ['company' => $company, 'branch' => $branch]);
}

function grantBranchAdminPermissions(User $actor, Company $company, Branch $branch, User $targetUser): void
{
    foreach ([
        PermissionCodes::PLATFORM_VIEW,
        PermissionCodes::PLATFORM_CREATE,
        PermissionCodes::PLATFORM_EDIT,
        PermissionCodes::LR_VIEW,
        PermissionCodes::LR_CREATE,
        PermissionCodes::TRIP_VIEW,
        PermissionCodes::TRIP_CREATE,
        PermissionCodes::BROKER_LORRY_HIRE_VIEW,
        PermissionCodes::BROKER_VOUCHER_VIEW,
        PermissionCodes::PARTLOAD_CHALLAN_VIEW,
        PermissionCodes::PARTLOAD_INWARD_VIEW,
        PermissionCodes::BRANCH_ACCOUNTING_VIEW,
    ] as $code) {
        tripGrant('customer.admin', $code);
    }

    $membership = CompanyMembership::query()
        ->where('company_id', $company->id)
        ->where('user_id', $targetUser->id)
        ->firstOrFail();
    $assignment = $membership->branchAssignments()->where('branch_id', $branch->id)->first()
        ?? app(CompanyWorkspaceService::class)->assignBranch($membership, $branch, 'Assign owner branch for test');
    $role = Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', 'customer.admin')
        ->firstOrFail();

    app(RoleAssignmentService::class)->assignCustomerBranchRole(
        $actor,
        $membership,
        $branch,
        $assignment,
        $role,
        'Grant customer.admin for isolated test company',
    );
}

function createIsolatedCompany(
    User $owner,
    string $name,
    array $modules,
    bool $withAccountingAddon = false,
): array {
    $workspace = app(CompanyWorkspaceService::class);
    $company = $workspace->createCompany($owner, $name);
    $branch = $company->branches()->firstOrFail();

    grantBranchAdminPermissions($owner, $company, $branch, $owner);

    // Clear auto first-company trial so paid state tests explicitly control modules.
    CompanySubscriptionTrial::query()->where('company_id', $company->id)->delete();
    CompanyCapabilityResolver::invalidate($company);

    if ($modules !== [] || $withAccountingAddon) {
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

        $addonIds = $withAccountingAddon
            ? SubscriptionAddon::query()
                ->where('catalogue_version_id', $catalogue->id)
                ->where('code', 'accounting')
                ->where('is_active', true)
                ->pluck('id')
                ->all()
            : [];

        test()->actingAs($owner);
        $renewalDate = app(SubscriptionClock::class)->now()->addYear();
        $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
            actor: $owner,
            company: $company,
            catalogue: $catalogue,
            planIds: $planIds,
            addonIds: $addonIds,
            tenure: SubscriptionTenure::Yearly,
            commonRenewalDate: $renewalDate,
        );

        app(SubscriptionLifecycleService::class)->activateAfterVerifiedPayment(
            $draft,
            $renewalDate,
            $owner,
            'Activate isolated module fixture',
        );
    }

    return ['company' => $company, 'branch' => $branch];
}

function createDriverPartyForCompany(User $actor, Company $company, Branch $branch, string $name, string $licenseNumber): Party
{
    TenantContextHolder::set(new TenantContext(
        companyId: $company->id,
        branchId: $branch->id,
        ownerId: (string) $company->owner_user_id,
    ));

    return app(PartyService::class)->create(
        actor: $actor,
        company: $company,
        branch: null,
        attributes: [
            'name' => $name,
            'contact_number' => '98'.str_pad(substr((string) abs(crc32($name)), 0, 8), 8, '0', STR_PAD_LEFT),
            'state' => 'MH',
            'city' => 'Pune',
            'is_gst_applicable' => false,
        ],
        roles: [[
            'role' => PartyRoleType::Driver->value,
            'attributes' => [
                'driver_type' => 'permanent',
                'license_number' => $licenseNumber,
                'license_expiry' => CarbonImmutable::now()->addYear()->toDateString(),
                'joining_date' => '2024-01-01',
            ],
        ]],
        remark: 'Test driver fixture',
    );
}

test('broker-only company allows market vehicles only, blocks ownfleet and partload navigation and endpoints, and prevents upgrade bypass', function () {
    $ctx = tripWorkspace([SubscriptionModule::Broker->value]);
    tripActivate($ctx['company'], $ctx['branch']);

    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->get(route('branch.vehicles.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.capabilities.allowed_vehicle_ownerships', ['market'])
            ->where('workspace.capabilities.ownfleet_drivers_enabled', false)
            ->where('formOptions.ownershipOptions.0.value', 'market')
            ->has('formOptions.ownershipOptions', 1));

    // Verify Broker sidebar has Lorry Hires but omits Vehicle Drivers / Trips / Partload.
    tripGrant('customer.admin', PermissionCodes::BROKER_LORRY_HIRE_VIEW);
    tripGrant('customer.admin', PermissionCodes::PARTLOAD_CHALLAN_VIEW);
    asBranchHttp($ctx['member'], $ctx['company'], $ctx['branch']);
    $this->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $nav = $page->toArray()['props']['workspace']['navigation'];

            expect($nav['broker_lorry_hires'])->toBeTrue()
                ->and($nav['bilty_register'])->toBeTrue()
                ->and($nav['ownfleet_vehicle_drivers'])->toBeFalse()
                ->and($nav['masters_vehicle_drivers'])->toBeFalse()
                ->and($nav['masters_truck_types'])->toBeFalse()
                ->and($nav['partload_challans'])->toBeFalse()
                ->and($nav['partload_pending_loading'])->toBeFalse();
        });

    // Forged Own vehicle POST + JSON Quick Create + Domain Service must reject.
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->post(route('branch.vehicles.store'), [
        'registration_number' => 'MH12FORGEDOWN1',
        'ownership' => VehicleOwnership::Own->value,
        'remark' => 'Forged own vehicle on Broker-only plan',
    ])->assertForbidden();

    // Party options endpoint must reject Driver role query on Broker-only plan.
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->getJson(route('branch.parties.options', ['role' => PartyRoleType::Driver->value]))
        ->assertForbidden();

    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->postJson(route('branch.vehicles.store'), [
        'registration_number' => 'MH12FORGEDOWN2',
        'ownership' => VehicleOwnership::Own->value,
    ])->assertForbidden();

    expect(fn () => app(VehicleService::class)->create(
        $ctx['owner'],
        $ctx['company'],
        null,
        ['registration_number' => 'MH12FORGEDOWN3', 'ownership' => VehicleOwnership::Own->value],
        'Direct service call',
        $ctx['branch'],
    ))->toThrow(ValidationException::class);

    // Market vehicle creation succeeds.
    $transporter = app(PartyService::class)->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: ['name' => 'Broker Transporter', 'contact_number' => '9811112222', 'state' => 'MH', 'city' => 'Pune', 'pan' => 'ABCDE1234F', 'is_gst_applicable' => false],
        roles: [['role' => PartyRoleType::Transporter->value, 'attributes' => ['pan' => 'ABCDE1234F', 'pan_status' => 'valid', 'declaration' => true, 'declaration_type' => 'owner', 'goods_vehicles_count' => 2]]],
        remark: 'Broker transporter',
    );
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->postJson(route('branch.vehicles.store'), [
        'registration_number' => 'MH12BRKVALID01',
        'ownership' => VehicleOwnership::Market->value,
        'owner_party_id' => $transporter->id,
    ])->assertCreated();

    // Unavailable OwnFleet / Partload Inertia routes render 403 capability-blocked with Owner upgrade link.
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->get(route('branch.vehicle-drivers.index'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('branch/capability-blocked')
            ->where('manageSubscriptionUrl', route('owner.subscriptions.index', absolute: false))
            ->where('purchaseSubscriptionUrl', route('owner.companies.subscription.purchase', ['company' => $ctx['company']->id], absolute: false)));

    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->get(route('branch.partload.challans.index'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page->component('branch/capability-blocked'));

    // Non-owner operational user sees capability-blocked WITHOUT Owner subscription URL.
    asBranchHttp($ctx['member'], $ctx['company'], $ctx['branch']);
    $this->get(route('branch.vehicle-drivers.index'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('branch/capability-blocked')
            ->where('manageSubscriptionUrl', null)
            ->where('purchaseSubscriptionUrl', null));
});

test('ownfleet-only company enforces own vehicles only, blocks broker/partload, and blocks market vehicle forgery', function () {
    $ctx = tripWorkspace([SubscriptionModule::OwnFleet->value]);
    tripActivate($ctx['company'], $ctx['branch']);

    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->get(route('branch.vehicles.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.capabilities.allowed_vehicle_ownerships', ['own'])
            ->where('workspace.capabilities.ownfleet_drivers_enabled', true)
            ->where('formOptions.ownershipOptions.0.value', 'own')
            ->has('formOptions.ownershipOptions', 1));

    // Market vehicle creation rejected via HTTP & service.
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->postJson(route('branch.vehicles.store'), [
        'registration_number' => 'MH12FORGEDMKT1',
        'ownership' => VehicleOwnership::Market->value,
    ])->assertForbidden();

    // Broker Lorry Hires & Partload Challans return 403.
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->get(route('branch.broker.lorry-hires.index'))->assertForbidden();

    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->get(route('branch.partload.pending-loading.index'))->assertForbidden();

    // Vehicle Drivers register & Trips (for branch role user) are accessible.
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->get(route('branch.vehicle-drivers.index'))->assertOk();

    asBranchHttp($ctx['member'], $ctx['company'], $ctx['branch']);
    $this->get(route('branch.trips.index'))
        ->assertRedirect(route('branch.lorry-receipts.index', absolute: false));
});

test('partload-only company enables partload operations and blocks unrestricted broker and ownfleet workflows', function () {
    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $pl = createIsolatedCompany($owner, 'Partload Only Co', [SubscriptionModule::Partload->value]);

    asBranchHttp($owner, $pl['company'], $pl['branch']);
    $this->get(route('branch.partload.pending-loading.index'))->assertOk();
    asBranchHttp($owner, $pl['company'], $pl['branch']);
    $this->get(route('branch.partload.challans.index'))->assertOk();
    asBranchHttp($owner, $pl['company'], $pl['branch']);
    $this->get(route('branch.partload.main-challans.index'))->assertOk();
    asBranchHttp($owner, $pl['company'], $pl['branch']);
    $this->get(route('branch.partload.challans.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.allowed_vehicle_ownerships', ['market']));
    asBranchHttp($owner, $pl['company'], $pl['branch']);
    $this->get(route('branch.partload.main-challans.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.allowed_vehicle_ownerships', ['market']));

    // Partload Challan service rejects historical/forged Own vehicle when OwnFleet is absent.
    $historicalOwn = Vehicle::query()->create([
        'company_id' => $pl['company']->id,
        'branch_id' => null,
        'registration_number' => 'MH12PLHISTOWN1',
        'ownership' => VehicleOwnership::Own->value,
        'is_active' => true,
    ]);
    $plDriver = createDriverPartyForCompany($owner, $pl['company'], $pl['branch'], 'PL Challan Driver', 'MH12PLDRV1');
    $plCustomer = app(PartyService::class)->create(
        actor: $owner,
        company: $pl['company'],
        branch: null,
        attributes: ['name' => 'PL Cust', 'contact_number' => '9800088001', 'state' => 'MH', 'city' => 'Pune', 'is_gst_applicable' => false],
        roles: [['role' => PartyRoleType::Customer->value], ['role' => PartyRoleType::Consignor->value], ['role' => PartyRoleType::Consignee->value]],
        remark: 'PL customer',
    );
    $stationA = Station::factory()->create(['owner_user_id' => $owner->id, 'name' => 'PL Origin', 'is_active' => true]);
    $stationB = Station::factory()->create(['owner_user_id' => $owner->id, 'name' => 'PL Dest', 'is_active' => true]);
    $goodsType = OwnerCatalogueItem::factory()->create(['owner_user_id' => $owner->id, 'catalogue_type' => OwnerCatalogueType::GoodsType->value, 'name' => 'Carton Goods', 'is_active' => true]);
    $pkgType = OwnerCatalogueItem::factory()->create(['owner_user_id' => $owner->id, 'catalogue_type' => OwnerCatalogueType::PackageType->value, 'name' => 'Box', 'is_active' => true]);
    $plLr = app(LorryReceiptService::class)->create($pl['company'], $pl['branch'], $owner, [
        'workflow_context' => LrWorkflowContext::Partload->value,
        'lr_date' => now()->toDateString(),
        'from_station_id' => $stationA->id,
        'to_station_id' => $stationB->id,
        'billing_customer_party_id' => $plCustomer->id,
        'consignor_party_id' => $plCustomer->id,
        'consignee_party_id' => $plCustomer->id,
        'freight_payment_term' => PartloadFreightPaymentTerm::ToPay->value,
        'goods' => [[
            'goods_type_id' => $goodsType->id,
            'package_type_id' => $pkgType->id,
            'goods_description' => 'Cartons',
            'package_count' => 2,
            'actual_weight' => 100,
            'charged_weight' => 100,
            'freight_rate_type' => FreightRateType::FixedAmount->value,
            'freight_rate' => 500,
        ]],
    ]);
    expect(fn () => app(PartloadChallanService::class)->create($pl['company'], $pl['branch'], $owner, [
        'from_station_id' => $stationA->id,
        'to_station_id' => $stationB->id,
        'vehicle_id' => $historicalOwn->id,
        'driver_party_id' => $plDriver->id,
        'lorry_receipt_ids' => [$plLr->id],
    ]))->toThrow(ValidationException::class);

    // Unrestricted OwnFleet & Broker remain blocked.
    asBranchHttp($owner, $pl['company'], $pl['branch']);
    $this->get(route('branch.vehicle-drivers.index'))->assertForbidden();
    asBranchHttp($owner, $pl['company'], $pl['branch']);
    $this->get(route('branch.trips.index'))->assertForbidden();
    asBranchHttp($owner, $pl['company'], $pl['branch']);
    $this->get(route('branch.broker.lorry-hires.index'))->assertForbidden();
    asBranchHttp($owner, $pl['company'], $pl['branch']);
    $this->get(route('branch.broker.vouchers.index'))->assertForbidden();

    asBranchHttp($owner, $pl['company'], $pl['branch']);
    $this->postJson(route('branch.vehicles.store'), [
        'registration_number' => 'MH12PLFORGEOWN',
        'ownership' => VehicleOwnership::Own->value,
    ])->assertForbidden();
});

test('combined and full-suite subscriptions unlock exact combined module capabilities', function () {
    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $combo = createIsolatedCompany($owner, 'Combo Co', [SubscriptionModule::Broker->value, SubscriptionModule::OwnFleet->value]);
    $all = createIsolatedCompany($owner, 'All Modules Co', [
        SubscriptionModule::Broker->value,
        SubscriptionModule::OwnFleet->value,
        SubscriptionModule::Partload->value,
    ], withAccountingAddon: true);

    $resolver = app(CompanyCapabilityResolver::class);
    expect($resolver->allowedVehicleOwnerships($combo['company']))->toHaveCount(2)
        ->and($resolver->allowsMutate($combo['company'], CompanyCapabilities::BROKER_LORRY_HIRE))->toBeTrue()
        ->and($resolver->allowsMutate($combo['company'], CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS))->toBeTrue()
        ->and($resolver->allowsMutate($combo['company'], CompanyCapabilities::PARTLOAD_CHALLANS))->toBeFalse();

    expect($resolver->allowsMutate($all['company'], CompanyCapabilities::BROKER_LORRY_HIRE))->toBeTrue()
        ->and($resolver->allowsMutate($all['company'], CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS))->toBeTrue()
        ->and($resolver->allowsMutate($all['company'], CompanyCapabilities::PARTLOAD_CHALLANS))->toBeTrue()
        ->and($resolver->allowsMutate($all['company'], CompanyCapabilities::ACCOUNTING))->toBeTrue();
});

test('first-company 15-day trial works only for first company, while expired and paid grace preserve reads and block mutations', function () {
    $clock = app(SubscriptionClock::class);
    $clock->freeze(CarbonImmutable::parse('2026-05-01 10:00:00', 'UTC'));

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $workspace = app(CompanyWorkspaceService::class);

    // First eligible company receives 15-day trial; second company gets no trial.
    $first = $workspace->createCompany($owner, 'Trial First Co');
    $firstBranch = $first->branches()->firstOrFail();
    $second = $workspace->createCompany($owner, 'No Trial Second Co');

    $resolver = app(CompanyCapabilityResolver::class);
    expect($resolver->allowsMutate($first, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS))->toBeTrue()
        ->and($resolver->allowsMutate($second, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS))->toBeFalse()
        ->and($resolver->allowsRead($second, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS))->toBeFalse();

    // Seed an active Own vehicle during active trial on first company so historical reads can be checked after expiry.
    $driver = createDriverPartyForCompany($owner, $first, $firstBranch, 'Trial Primary Driver', 'MH12TRIAL001');
    $vehicle = Vehicle::query()->create([
        'company_id' => $first->id,
        'branch_id' => null,
        'registration_number' => 'MH12TRIAL999',
        'ownership' => VehicleOwnership::Own->value,
        'primary_driver_party_id' => $driver->id,
        'is_active' => true,
    ]);

    // Move clock past 15-day trial expiry -> trial expired (no grace on trial; mutations blocked, historical read preserved).
    $clock->freeze(CarbonImmutable::parse('2026-05-20 10:00:00', 'UTC'));
    CompanyCapabilityResolver::invalidate($first);

    expect($resolver->allowsMutate($first, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS))->toBeFalse();
    asBranchHttp($owner, $first, $firstBranch);
    $this->get(route('branch.vehicle-drivers.index'))->assertOk();
    asBranchHttp($owner, $first, $firstBranch);
    $this->post(route('branch.vehicle-drivers.assign', [$vehicle]), [
        'driver_party_id' => $driver->id,
        'assigned_from' => '2026-05-20',
    ])->assertForbidden();
    expect(Vehicle::query()->whereKey($vehicle->id)->exists())->toBeTrue();

    // Paid subscription entering PaidGrace preserves historical reads and blocks mutations.
    $clock->freeze(CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'));
    $paid = createIsolatedCompany($owner, 'Grace Co', [SubscriptionModule::OwnFleet->value]);
    $paidDriver = createDriverPartyForCompany($owner, $paid['company'], $paid['branch'], 'Grace Driver', 'MH12GRACE001');
    $paidVehicle = Vehicle::query()->create([
        'company_id' => $paid['company']->id,
        'branch_id' => null,
        'registration_number' => 'MH12GRACE999',
        'ownership' => VehicleOwnership::Own->value,
        'primary_driver_party_id' => $paidDriver->id,
        'is_active' => true,
    ]);

    // Advance clock 2 days past 1-year renewal -> PaidGrace (10-day window).
    $clock->freeze(CarbonImmutable::parse('2027-06-03 10:00:00', 'UTC'));
    CompanyCapabilityResolver::invalidate($paid['company']);

    asBranchHttp($owner, $paid['company'], $paid['branch']);
    $this->get(route('branch.vehicle-drivers.index'))->assertOk();
    asBranchHttp($owner, $paid['company'], $paid['branch']);
    $this->get(route('branch.vehicles.show', [$paidVehicle]))->assertOk();
    asBranchHttp($owner, $paid['company'], $paid['branch']);
    $this->post(route('branch.vehicle-drivers.assign', [$paidVehicle]), [
        'driver_party_id' => $paidDriver->id,
        'assigned_from' => '2027-06-03',
    ])->assertForbidden();
    expect(Vehicle::query()->whereKey($paidVehicle->id)->exists())->toBeTrue();
});

test('multi-company switching isolates capabilities per company and activation immediately clears capability cache', function () {
    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $brokerCo = createIsolatedCompany($owner, 'Company A Broker', [SubscriptionModule::Broker->value]);
    $ownFleetCo = createIsolatedCompany($owner, 'Company B OwnFleet', [SubscriptionModule::OwnFleet->value]);
    $allCo = createIsolatedCompany($owner, 'Company C Full', [
        SubscriptionModule::Broker->value,
        SubscriptionModule::OwnFleet->value,
        SubscriptionModule::Partload->value,
    ]);

    // Switching companies on same Owner changes allowed capabilities cleanly without stale cache.
    asBranchHttp($owner, $brokerCo['company'], $brokerCo['branch']);
    $this->get(route('branch.vehicle-drivers.index'))->assertForbidden();
    asBranchHttp($owner, $brokerCo['company'], $brokerCo['branch']);
    $this->get(route('branch.broker.lorry-hires.index'))->assertOk();

    asBranchHttp($owner, $ownFleetCo['company'], $ownFleetCo['branch']);
    $this->get(route('branch.vehicle-drivers.index'))->assertOk();
    asBranchHttp($owner, $ownFleetCo['company'], $ownFleetCo['branch']);
    $this->get(route('branch.broker.lorry-hires.index'))->assertForbidden();

    asBranchHttp($owner, $allCo['company'], $allCo['branch']);
    $this->get(route('branch.vehicle-drivers.index'))->assertOk();
    asBranchHttp($owner, $allCo['company'], $allCo['branch']);
    $this->get(route('branch.broker.lorry-hires.index'))->assertOk();
    asBranchHttp($owner, $allCo['company'], $allCo['branch']);
    $this->get(route('branch.partload.challans.index'))->assertOk();

    // Warm cache for Broker company, then add OwnFleet module and verify immediate availability.
    asBranchHttp($owner, $brokerCo['company'], $brokerCo['branch']);
    $this->get(route('branch.vehicle-drivers.index'))->assertForbidden();

    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $ownPlan = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('module', SubscriptionModule::OwnFleet->value)
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->firstOrFail();

    $this->actingAs($owner);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $brokerCo['company'],
        catalogue: $catalogue,
        planIds: [$ownPlan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::now()->addYear(),
    );
    app(SubscriptionLifecycleService::class)->activateAfterVerifiedPayment(
        $draft,
        CarbonImmutable::now()->addYear(),
        $owner,
        'Upgrade Broker company with OwnFleet',
    );

    // Immediately accessible without Cache::flush().
    asBranchHttp($owner, $brokerCo['company'], $brokerCo['branch']);
    $this->get(route('branch.vehicle-drivers.index'))->assertOk();
});

test('ownfleet vehicle driver assignment, replacement history, overlap rejection, vehicle master sync, attendance, and bilty trip substitute driver preserve invariants', function () {
    $ctx = tripWorkspace([SubscriptionModule::OwnFleet->value]);
    tripActivate($ctx['company'], $ctx['branch']);

    $service = app(VehicleDriverAssignmentService::class);
    $attendanceService = app(VehicleDriverAttendanceService::class);

    // Second Own vehicle initially unassigned; Driver 2 created cleanly.
    $truckA = $ctx['vehicle'];
    $truckB = Vehicle::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'registration_number' => 'MH12TRUCKB2',
        'ownership' => VehicleOwnership::Own->value,
        'truck_type_id' => $truckA->truck_type_id,
        'tyre_layout_id' => $truckA->tyre_layout_id,
        'is_active' => true,
    ]);
    $driverOne = $ctx['primaryDriver'];
    $driverTwo = createDriverPartyForCompany($ctx['owner'], $ctx['company'], $ctx['branch'], 'Driver Two', 'MH12DRV2002');
    $driverSub = $ctx['substituteDriver'];

    $day1 = CarbonImmutable::now()->subDays(10)->toDateString();
    $day2 = CarbonImmutable::now()->subDays(5)->toDateString();
    $today = CarbonImmutable::now()->toDateString();

    // Assign Driver One to Truck A on day1.
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->post(route('branch.vehicle-drivers.assign', [$truckA]), [
        'driver_party_id' => $driverOne->id,
        'assigned_from' => $day1,
        'remarks' => 'Initial primary assignment',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($truckA->fresh()->primary_driver_party_id)->toBe($driverOne->id);

    // Cross-vehicle overlap rejection: Driver One cannot simultaneously be active primary on Truck B.
    expect(fn () => $service->assignPrimaryDriver(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: $ctx['branch'],
        vehicle: $truckB,
        driverPartyId: $driverOne->id,
        assignedFrom: $day2,
    ))->toThrow(ValidationException::class);

    // Replace Truck A driver with Driver Two on day2 -> closes Driver One assignment on day2 - 1 and preserves history.
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->post(route('branch.vehicle-drivers.assign', [$truckA]), [
        'driver_party_id' => $driverTwo->id,
        'assigned_from' => $day2,
        'remarks' => 'Handover to Driver Two',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($truckA->fresh()->primary_driver_party_id)->toBe($driverTwo->id);

    $history = VehicleDriverAssignment::query()
        ->where('company_id', $ctx['company']->id)
        ->where('vehicle_id', $truckA->id)
        ->orderBy('assigned_from')
        ->get();

    expect($history)->toHaveCount(2)
        ->and($history[0]->driver_party_id)->toBe($driverOne->id)
        ->and($history[0]->assigned_to?->toDateString())->toBe(CarbonImmutable::parse($day2)->subDay()->toDateString())
        ->and($history[1]->driver_party_id)->toBe($driverTwo->id)
        ->and($history[1]->assigned_to)->toBeNull();

    // Create OwnFleet Bilty using Truck A with Substitute Driver -> Trip uses Driver Sub, while Truck A permanent Primary Driver stays Driver Two.
    $customer = app(PartyService::class)->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: ['name' => 'OF Billing Customer', 'contact_number' => '9822223333', 'state' => 'MH', 'city' => 'Pune', 'is_gst_applicable' => false],
        roles: [['role' => PartyRoleType::Customer->value, 'attributes' => []]],
        remark: 'Customer',
    );

    asBranchHttp($ctx['member'], $ctx['company'], $ctx['branch']);
    $this->post(route('branch.lorry-receipts.store'), [
        'form_mode' => 'truck',
        'vehicle_id' => $truckA->id,
        'billing_customer_party_id' => $customer->id,
        'number_mode' => 'auto',
        'lr_date' => $today,
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'use_substitute_driver' => '1',
        'substitute_driver_party_id' => $driverSub->id,
        'goods' => [[
            'goods_type_id' => $ctx['goodsType']->id,
            'package_type_id' => $ctx['packageType']->id,
            'goods_description' => 'Steel Coils',
            'package_count' => '5',
            'actual_weight' => '1000',
            'charged_weight' => '1000',
            'freight_rate_type' => FreightRateType::FixedAmount->value,
            'freight_rate' => '15000',
        ]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $lr = LorryReceipt::query()->where('company_id', $ctx['company']->id)->latest('created_at')->firstOrFail();
    $trip = Trip::query()->where('lorry_receipt_id', $lr->id)->firstOrFail();

    expect($trip->driver_party_id)->toBe($driverSub->id)
        ->and($trip->primary_driver_party_id)->toBe($driverTwo->id)
        ->and($trip->substitute_driver_party_id)->toBe($driverSub->id)
        ->and($truckA->fresh()->primary_driver_party_id)->toBe($driverTwo->id);

    // Removing Driver Two from Truck A preserves historical assignment & historical Trip driver!
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->post(route('branch.vehicle-drivers.remove', [$truckA]), [
        'effective_date' => $today,
        'remarks' => 'Driver Two went on leave',
    ])->assertRedirect();

    expect($truckA->fresh()->primary_driver_party_id)->toBeNull()
        ->and($trip->fresh()->driver_party_id)->toBe($driverSub->id);

    // Vehicle Master integration: assigning Driver One via VehicleController::update syncs VehicleDriverAssignment!
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->patch(route('branch.vehicles.update', [$truckB]), [
        'ownership' => VehicleOwnership::Own->value,
        'primary_driver_party_id' => $driverOne->id,
        'remark' => 'Assign via Vehicle Master edit',
    ])->assertRedirect();

    expect($truckB->fresh()->primary_driver_party_id)->toBe($driverOne->id)
        ->and(VehicleDriverAssignment::query()
            ->where('vehicle_id', $truckB->id)
            ->where('driver_party_id', $driverOne->id)
            ->whereNull('assigned_to')
            ->exists())->toBeTrue();

    // Daily Attendance Exception workflow:
    // 1. Explicit PRESENT is rejected (assigned drivers are implicitly present).
    expect(fn () => $attendanceService->mark($ctx['owner'], $ctx['company'], $ctx['branch'], [
        'vehicle_id' => $truckB->id,
        'attendance_date' => $today,
        'status' => DriverAttendanceStatus::Present->value,
    ]))->toThrow(ValidationException::class);

    // 2. Substitute driver already on an active Trip today cannot be double-booked onto Truck B attendance.
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->post(route('branch.vehicle-drivers.attendance.mark'), [
        'vehicle_id' => $truckB->id,
        'attendance_date' => $today,
        'status' => DriverAttendanceStatus::Absent->value,
        'temporary_driver_party_id' => $driverSub->id,
    ])->assertSessionHasErrors('temporary_driver_party_id');

    // 3. Marking Truck B Absent with Driver Two as substitute succeeds, and resetting clears it.
    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->post(route('branch.vehicle-drivers.attendance.mark'), [
        'vehicle_id' => $truckB->id,
        'attendance_date' => $today,
        'status' => DriverAttendanceStatus::Absent->value,
        'temporary_driver_party_id' => $driverTwo->id,
        'remarks' => 'Driver One absent today',
    ])->assertRedirect();

    expect(VehicleDriverAttendance::query()
        ->where('vehicle_id', $truckB->id)
        ->whereDate('attendance_date', $today)
        ->where('temporary_driver_party_id', $driverTwo->id)
        ->exists())->toBeTrue();

    asBranchHttp($ctx['owner'], $ctx['company'], $ctx['branch']);
    $this->delete(route('branch.vehicle-drivers.attendance.clear', [$truckB]), [
        'attendance_date' => $today,
    ])->assertRedirect();

    expect(VehicleDriverAttendance::query()
        ->where('vehicle_id', $truckB->id)
        ->whereDate('attendance_date', $today)
        ->whereNull('deleted_at')
        ->exists())->toBeFalse();
});
