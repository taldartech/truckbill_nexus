<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Consignment\LrWorkflowContextResolver;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySubscriptionTrial;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Station;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
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

function lrContextGrantRolePermission(string $roleSlug, string $permissionCode): void
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
 *     consignor: Party,
 *     consignee: Party,
 *     goodsType: OwnerCatalogueItem,
 *     packageType: OwnerCatalogueItem,
 *     fromStation: Station,
 *     toStation: Station
 * }
 */
function lrContextWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    foreach ([
        PermissionCodes::LR_VIEW,
        PermissionCodes::LR_CREATE,
        PermissionCodes::LR_EDIT,
        PermissionCodes::LR_CANCEL,
        PermissionCodes::LR_DELETE,
    ] as $code) {
        lrContextGrantRolePermission('customer.admin', $code);
    }

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'LR Context Co');
    $branch = $company->branches()->firstOrFail();

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
    $consignor = $parties->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Consignor Mills',
            'contact_number' => '9800010001',
            'state' => 'MH',
            'city' => 'Mumbai',
            'is_gst_applicable' => false,
        ],
        roles: [['role' => PartyRoleType::Consignor->value]],
        remark: 'Create consignor',
    );
    $consignee = $parties->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Consignee Depot',
            'contact_number' => '9800010002',
            'state' => 'MH',
            'city' => 'Pune',
            'is_gst_applicable' => false,
        ],
        roles: [['role' => PartyRoleType::Consignee->value]],
        remark: 'Create consignee',
    );

    $fromStation = Station::factory()->create([
        'owner_user_id' => $owner->id,
        'name' => 'Mumbai Hub',
        'code' => 'MUM1',
        'is_active' => true,
    ]);
    $toStation = Station::factory()->create([
        'owner_user_id' => $owner->id,
        'name' => 'Pune Hub',
        'code' => 'PUN1',
        'is_active' => true,
    ]);

    $truckType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'name' => '12 Tyre',
        'code' => 'T12',
        'is_active' => true,
    ]);
    $tyreLayout = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::TyreLayout->value,
        'name' => '6x2',
        'code' => 'L62',
        'is_active' => true,
    ]);
    $billingCustomer = $parties->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Context Billing Customer',
            'contact_number' => '9800010004',
            'state' => 'MH',
            'city' => 'Mumbai',
            'is_gst_applicable' => false,
        ],
        roles: [['role' => PartyRoleType::Customer->value]],
        remark: 'Create billing customer',
    );
    $transporter = $parties->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Context Transporter',
            'contact_number' => '9800010003',
            'pan' => 'CTXTR1234A',
            'state' => 'MH',
            'city' => 'Thane',
            'is_gst_applicable' => false,
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'CTXTR1234A',
                'pan_status' => 'active',
                'declaration' => true,
                'declaration_type' => 'owner',
                'goods_vehicles_count' => 2,
            ],
        ]],
        remark: 'Create transporter',
    );

    $ownVehicle = Vehicle::query()->create([
        'company_id' => $company->id,
        'registration_number' => 'MH12CTXOWN1',
        'is_active' => true,
        'ownership' => VehicleOwnership::Own->value,
        'truck_type_id' => $truckType->id,
        'tyre_layout_id' => $tyreLayout->id,
    ]);
    $marketVehicle = Vehicle::query()->create([
        'company_id' => $company->id,
        'registration_number' => 'MH12CTXMKT1',
        'is_active' => true,
        'ownership' => VehicleOwnership::Market->value,
        'owner_party_id' => $transporter->id,
    ]);

    return [
        'owner' => $owner,
        'company' => $company,
        'branch' => $branch,
        'member' => $member,
        'consignor' => $consignor,
        'consignee' => $consignee,
        'billingCustomer' => $billingCustomer,
        'transporter' => $transporter,
        'goodsType' => $goodsType,
        'packageType' => $packageType,
        'fromStation' => $fromStation,
        'toStation' => $toStation,
        'ownVehicle' => $ownVehicle,
        'marketVehicle' => $marketVehicle,
        'vehicle' => $marketVehicle,
    ];
}

/**
 * Activate a paid subscription for the given catalogue modules via draft + verified payment.
 *
 * @param  list<string>  $modules  SubscriptionModule values (broker|ownfleet|partload)
 */
function lrActivatePaidModules(
    User $owner,
    Company $company,
    array $modules,
    CarbonImmutable $paidThroughDate = new CarbonImmutable('2027-12-31'),
    ?SubscriptionClock $clock = null,
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

    $lifecycle = new SubscriptionLifecycleService(clock: $clock ?? new SubscriptionClock);
    $lifecycle->activateAfterVerifiedPayment($draft, $paidThroughDate, $owner);
}

function lrContextActivateTenant(Company $company, Branch $branch): void
{
    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: $branch->id,
        ownerId: (string) $company->owner_user_id,
    ));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function lrContextPayload(array $ctx, array $overrides = []): array
{
    $context = (string) ($overrides['workflow_context'] ?? 'broker');
    $vehicleId = match ($context) {
        'own_fleet' => $ctx['ownVehicle']->id,
        'partload' => null,
        default => $ctx['marketVehicle']->id,
    };

    $base = [
        'workflow_context' => 'broker',
        'vehicle_id' => $vehicleId,
        'number_mode' => 'auto',
        'lr_date' => now()->toDateString(),
        'from_text' => 'Mumbai',
        'to_text' => 'Pune',
        'consignor_party_id' => $ctx['consignor']->id,
        'consignee_party_id' => $ctx['consignee']->id,
        'billing_customer_party_id' => $ctx['billingCustomer']->id,
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
                'goods_value' => 5000,
            ],
        ],
    ];

    if ($context === 'broker') {
        $base['intended_payee_type'] = 'owner';
        $base['intended_payee_party_id'] = $ctx['transporter']->id;
    }

    return array_merge($base, $overrides);
}

function lrContextLoginAs(User $user, string $password): void
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }

    test()->flushSession();
    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertRedirect();
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

test('broker-only active subscription opens shared truck LR form', function () {
    $ws = lrContextWorkspace();
    lrActivatePaidModules($ws['owner'], $ws['company'], [SubscriptionModule::Broker->value]);

    $resolver = app(LrWorkflowContextResolver::class);
    $resolution = $resolver->resolveForCreate($ws['company'], $ws['member'], $ws['branch'], null);

    expect($resolution['status'])->toBe('ready')
        ->and($resolution['form_mode'])->toBe('truck')
        ->and($resolution['context'])->toBeNull()
        ->and(app(CoreModuleEntitlementResolver::class)->entitledModules($ws['company']))
        ->toEqual([SubscriptionModule::Broker]);

    lrContextLoginAs($ws['member'], 'member-secret');
    enterBranchWorkspace($ws['company'], $ws['branch'])->assertRedirect();

    $this->get(route('branch.lorry-receipts.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/lorry-receipts/create')
            ->where('formMode', 'truck')
            ->where('workflowContext', null));

    $this->get(route('branch.lorry-receipts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('createAction.mode', 'direct')
            ->where('createAction.url', route('branch.lorry-receipts.create', absolute: false)));
});

test('ownfleet-only active subscription opens shared truck LR form', function () {
    $ws = lrContextWorkspace();
    lrActivatePaidModules($ws['owner'], $ws['company'], [SubscriptionModule::OwnFleet->value]);

    $resolver = app(LrWorkflowContextResolver::class);
    $resolution = $resolver->resolveForCreate($ws['company'], $ws['member'], $ws['branch'], null);

    expect($resolution['status'])->toBe('ready')
        ->and($resolution['form_mode'])->toBe('truck')
        ->and($resolution['context'])->toBeNull();
});

test('partload-only active subscription resolves partload form', function () {
    $ws = lrContextWorkspace();
    lrActivatePaidModules($ws['owner'], $ws['company'], [SubscriptionModule::Partload->value]);

    $resolver = app(LrWorkflowContextResolver::class);
    $resolution = $resolver->resolveForCreate($ws['company'], $ws['member'], $ws['branch'], null);

    expect($resolution['status'])->toBe('ready')
        ->and($resolution['form_mode'])->toBe('partload')
        ->and($resolution['context'])->toBe(LrWorkflowContext::Partload);
});

test('multi-module without origin opens shared truck form with no chooser menu', function () {
    $ws = lrContextWorkspace();
    lrActivatePaidModules($ws['owner'], $ws['company'], [
        SubscriptionModule::Broker->value,
        SubscriptionModule::OwnFleet->value,
    ]);

    $resolver = app(LrWorkflowContextResolver::class);
    $resolution = $resolver->resolveForCreate($ws['company'], $ws['member'], $ws['branch'], null);

    expect($resolution['status'])->toBe('ready')
        ->and($resolution['form_mode'])->toBe('truck')
        ->and($resolution['context'])->toBeNull();

    lrContextLoginAs($ws['member'], 'member-secret');
    enterBranchWorkspace($ws['company'], $ws['branch'])->assertRedirect();

    $this->get(route('branch.lorry-receipts.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/lorry-receipts/create')
            ->where('formMode', 'truck'));

    $this->get(route('branch.lorry-receipts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/lorry-receipts/index')
            ->where('createAction.mode', 'direct')
            ->missing('createAction.options'));
});

test('multi-module with origin own_fleet still opens shared truck form', function () {
    $ws = lrContextWorkspace();
    lrActivatePaidModules($ws['owner'], $ws['company'], [
        SubscriptionModule::Broker->value,
        SubscriptionModule::OwnFleet->value,
        SubscriptionModule::Partload->value,
    ]);

    $resolver = app(LrWorkflowContextResolver::class);
    $resolution = $resolver->resolveForCreate(
        $ws['company'],
        $ws['member'],
        $ws['branch'],
        'own_fleet',
    );

    expect($resolution['status'])->toBe('ready')
        ->and($resolution['form_mode'])->toBe('truck')
        ->and($resolution['context'])->toBeNull();

    lrContextLoginAs($ws['member'], 'member-secret');
    enterBranchWorkspace($ws['company'], $ws['branch'])->assertRedirect();

    $this->get(route('branch.lorry-receipts.create', ['context' => 'own_fleet']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/lorry-receipts/create')
            ->where('formMode', 'truck')
            ->where('workflowContext', null));
});

test('unentitled context POST is rejected', function () {
    $ws = lrContextWorkspace();
    lrActivatePaidModules($ws['owner'], $ws['company'], [SubscriptionModule::Broker->value]);

    lrContextLoginAs($ws['member'], 'member-secret');
    enterBranchWorkspace($ws['company'], $ws['branch'])->assertRedirect();

    $this->post(route('branch.lorry-receipts.store'), lrContextPayload($ws, [
        'form_mode' => 'truck',
        'workflow_context' => 'own_fleet',
        'vehicle_id' => $ws['ownVehicle']->id,
        'from_station_id' => $ws['fromStation']->id,
        'to_station_id' => $ws['toStation']->id,
        'from_text' => null,
        'to_text' => null,
    ]))->assertSessionHasErrors('vehicle_id');
});

test('edit cannot change workflow context', function () {
    $ws = lrContextWorkspace();
    lrActivatePaidModules($ws['owner'], $ws['company'], [SubscriptionModule::Broker->value]);
    lrContextActivateTenant($ws['company'], $ws['branch']);

    $service = app(LorryReceiptService::class);
    $lr = $service->create($ws['company'], $ws['branch'], $ws['member'], lrContextPayload($ws));

    expect(fn () => $service->update($lr, $ws['member'], lrContextPayload($ws, [
        'workflow_context' => 'partload',
        'from_station_id' => $ws['fromStation']->id,
        'to_station_id' => $ws['toStation']->id,
        'from_text' => null,
        'to_text' => null,
    ])))->toThrow(ValidationException::class);

    $updated = $service->update($lr->fresh(), $ws['member'], lrContextPayload($ws, [
        // Broker primary route is derived from parties — free-text cannot override it.
        'from_text' => 'Updated origin',
    ]));

    expect($updated->workflow_context)->toBe(LrWorkflowContext::Broker)
        ->and($updated->from_text)->toBe('Mumbai, MH')
        ->and($updated->to_text)->toBe('Pune, MH');
});

test('broker validation requires parties and own_fleet requires stations', function () {
    $ws = lrContextWorkspace();
    lrActivatePaidModules($ws['owner'], $ws['company'], [
        SubscriptionModule::Broker->value,
        SubscriptionModule::OwnFleet->value,
    ]);
    lrContextActivateTenant($ws['company'], $ws['branch']);

    $service = app(LorryReceiptService::class);

    expect(fn () => $service->create($ws['company'], $ws['branch'], $ws['member'], lrContextPayload($ws, [
        'workflow_context' => 'broker',
        'consignor_party_id' => null,
        'consignee_party_id' => null,
    ])))->toThrow(ValidationException::class);

    expect(fn () => $service->create($ws['company'], $ws['branch'], $ws['member'], lrContextPayload($ws, [
        'workflow_context' => 'own_fleet',
        'from_text' => null,
        'to_text' => null,
        'consignor_party_id' => null,
        'consignee_party_id' => null,
    ])))->toThrow(ValidationException::class);

    $ownFleet = $service->create($ws['company'], $ws['branch'], $ws['member'], lrContextPayload($ws, [
        'workflow_context' => 'own_fleet',
        'from_text' => null,
        'to_text' => null,
        'from_station_id' => $ws['fromStation']->id,
        'to_station_id' => $ws['toStation']->id,
        'consignor_party_id' => null,
        'consignee_party_id' => null,
    ]));

    expect($ownFleet->workflow_context)->toBe(LrWorkflowContext::OwnFleet)
        ->and($ownFleet->from_station_id)->toBe($ws['fromStation']->id)
        ->and($ownFleet->from_text)->toBeNull();
});

test('lifecycle restricted blocks create', function () {
    $ws = lrContextWorkspace();
    $clock = new SubscriptionClock;
    $clock->freeze(CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC'));

    lrActivatePaidModules(
        $ws['owner'],
        $ws['company'],
        [SubscriptionModule::Broker->value],
        CarbonImmutable::parse('2026-03-10'),
        $clock,
    );

    // Past paid-through into paid grace → Branch mutations blocked (Rules §15).
    $clock->freeze(CarbonImmutable::parse('2026-03-12 00:00:00', 'UTC'));
    app()->instance(SubscriptionClock::class, $clock);
    app()->instance(SubscriptionEntitlementGate::class, new SubscriptionEntitlementGate(clock: $clock));

    $status = app(SubscriptionEntitlementGate::class)->evaluate($ws['company']);
    expect($status['branch_mutations_allowed'])->toBeFalse();

    lrContextLoginAs($ws['member'], 'member-secret');
    enterBranchWorkspace($ws['company'], $ws['branch'])->assertRedirect();

    $this->post(route('branch.lorry-receipts.store'), lrContextPayload($ws, [
        'context' => 'broker',
    ]))->assertForbidden();
});
