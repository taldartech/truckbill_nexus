<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Trip\Exceptions\TripAuthorizationException;
use App\Domains\OwnFleet\Trip\Exceptions\TripConflictException;
use App\Domains\OwnFleet\Trip\TripService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySubscriptionTrial;
use App\Models\CustomerWebSession;
use App\Models\LorryReceipt;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Station;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function tripGrant(string $roleSlug, string $permissionCode): void
{
    $role = Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $roleSlug)
        ->firstOrFail();
    $permission = Permission::query()->where('code', $permissionCode)->firstOrFail();

    RolePermission::query()->firstOrCreate(
        [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ],
        [
            'deactivated_at' => null,
        ],
    );

    Cache::flush();
}

/**
 * @return array{
 *     owner: User,
 *     company: Company,
 *     branch: Branch,
 *     member: User,
 *     viewer: User,
 *     vehicle: Vehicle,
 *     primaryDriver: Party,
 *     substituteDriver: Party,
 *     fromStation: Station,
 *     toStation: Station,
 *     goodsType: OwnerCatalogueItem,
 *     packageType: OwnerCatalogueItem
 * }
 * @param  list<string>  $modules
 */
function tripWorkspace(array $modules = [SubscriptionModule::OwnFleet->value]): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    foreach ([
        PermissionCodes::LR_VIEW,
        PermissionCodes::LR_CREATE,
        PermissionCodes::LR_EDIT,
        PermissionCodes::TRIP_VIEW,
        PermissionCodes::TRIP_CREATE,
        PermissionCodes::TRIP_EDIT,
    ] as $code) {
        tripGrant('customer.admin', $code);
    }
    tripGrant('customer.viewer', PermissionCodes::LR_VIEW);
    tripGrant('customer.viewer', PermissionCodes::TRIP_VIEW);

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'Trip Co');
    $branch = $company->branches()->firstOrFail();

    tripActivatePaidModules($owner, $company, $modules);
    test()->actingAs($owner);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);

    $member = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membership = $workspace->addMembership($company, $member, 'Join');
    $memberAssignment = $workspace->assignBranch($membership, $branch, 'Assign');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $memberAssignment,
        Role::query()->where('slug', 'customer.admin')->where('domain', PermissionDomain::Customer->value)->firstOrFail(),
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
        Role::query()->where('slug', 'customer.viewer')->where('domain', PermissionDomain::Customer->value)->firstOrFail(),
        'Grant viewer',
    );

    $goodsType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::GoodsType->value,
        'name' => 'General',
        'code' => 'GEN',
        'is_active' => true,
    ]);
    $packageType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::PackageType->value,
        'name' => 'Bag',
        'code' => 'BAG',
        'is_active' => true,
    ]);

    $parties = app(PartyService::class);
    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: $branch->id,
        ownerId: (string) $company->owner_user_id,
    ));

    $primaryDriver = $parties->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Primary Driver',
            'contact_number' => '9800030001',
            'state' => 'MH',
            'city' => 'Pune',
            'is_gst_applicable' => false,
        ],
        roles: [[
            'role' => PartyRoleType::Driver->value,
            'attributes' => [
                'driver_type' => 'permanent',
                'license_number' => 'MH12TRIP1001',
                'joining_date' => '2024-01-01',
            ],
        ]],
        remark: 'Create primary driver',
    );
    $substituteDriver = $parties->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Substitute Driver',
            'contact_number' => '9800030002',
            'state' => 'MH',
            'city' => 'Pune',
            'is_gst_applicable' => false,
        ],
        roles: [[
            'role' => PartyRoleType::Driver->value,
            'attributes' => [
                'driver_type' => 'market',
            ],
        ]],
        remark: 'Create substitute driver',
    );

    $fromStation = Station::factory()->create([
        'owner_user_id' => $owner->id,
        'name' => 'Origin Hub',
        'is_active' => true,
    ]);
    $toStation = Station::factory()->create([
        'owner_user_id' => $owner->id,
        'name' => 'Destination Hub',
        'is_active' => true,
    ]);

    $vehicle = Vehicle::query()->create([
        'company_id' => $company->id,
        'branch_id' => null,
        'registration_number' => 'MH12TRIP0001',
        'is_active' => true,
        'primary_driver_party_id' => $primaryDriver->id,
    ]);

    return [
        'owner' => $owner,
        'company' => $company,
        'branch' => $branch,
        'member' => $member,
        'viewer' => $viewer,
        'vehicle' => $vehicle,
        'primaryDriver' => $primaryDriver,
        'substituteDriver' => $substituteDriver,
        'fromStation' => $fromStation,
        'toStation' => $toStation,
        'goodsType' => $goodsType,
        'packageType' => $packageType,
    ];
}

/**
 * @param  list<string>  $modules
 */
function tripActivatePaidModules(
    User $owner,
    Company $company,
    array $modules,
    CarbonImmutable $paidThroughDate = new CarbonImmutable('2027-12-31'),
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

    test()->actingAs($owner);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: $planIds,
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: $paidThroughDate,
    );

    $lifecycle = new SubscriptionLifecycleService(clock: new SubscriptionClock);
    $lifecycle->activateAfterVerifiedPayment($draft, $paidThroughDate, $owner);
}

function tripActivate(Company $company, Branch $branch): void
{
    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: $branch->id,
        ownerId: (string) $company->owner_user_id,
    ));
}

function tripCreateOwnFleetLr(array $ctx): LorryReceipt
{
    return app(LorryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'workflow_context' => LrWorkflowContext::OwnFleet->value,
        'number_mode' => 'auto',
        'lr_date' => now()->toDateString(),
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'goods' => [
            [
                'goods_type_id' => $ctx['goodsType']->id,
                'package_type_id' => $ctx['packageType']->id,
                'goods_description' => 'Steel coils',
                'package_count' => 5,
                'actual_weight' => 1000,
                'charged_weight' => 1000,
                'freight_rate_type' => FreightRateType::FixedAmount->value,
                'freight_rate' => 12000,
                'goods_value' => 500000,
            ],
        ],
    ]);
}

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

    $lr = app(LorryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'workflow_context' => LrWorkflowContext::Broker->value,
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
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/trips/index')
            ->has('trips.data', 1));

    $claim();
    $this->get(route('branch.lorry-receipts.show', [$lr]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('linkedTrip.id', $trip->id)
            ->where('permissions.can_create_trip', false));
});
