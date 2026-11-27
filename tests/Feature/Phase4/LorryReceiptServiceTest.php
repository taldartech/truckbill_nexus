<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Consignment\Enums\FreightRateSemantics;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Exceptions\LorryReceiptAuthorizationException;
use App\Domains\Consignment\Exceptions\LorryReceiptConflictException;
use App\Domains\Consignment\Exceptions\LorryReceiptDomainException;
use App\Domains\Consignment\Freight\FreightCalculator;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerWebSession;
use App\Models\LorryReceipt;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

function lrGrantRolePermission(string $roleSlug, string $permissionCode): void
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
 *     consignor: Party,
 *     consignee: Party,
 *     goodsType: OwnerCatalogueItem,
 *     packageType: OwnerCatalogueItem,
 *     otherCompany: Company,
 *     otherBranch: Branch
 * }
 */
function lrWorkspace(bool $withPermissions = true): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    if ($withPermissions) {
        foreach ([
            PermissionCodes::LR_VIEW,
            PermissionCodes::LR_CREATE,
            PermissionCodes::LR_EDIT,
            PermissionCodes::LR_CANCEL,
            PermissionCodes::LR_DELETE,
        ] as $code) {
            lrGrantRolePermission('customer.admin', $code);
        }
        lrGrantRolePermission('customer.viewer', PermissionCodes::LR_VIEW);
    }

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'LR Co');
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
    $consignor = $parties->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Consignor Mills',
            'contact_number' => '9800020001',
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
            'contact_number' => '9800020002',
            'state' => 'MH',
            'city' => 'Pune',
            'is_gst_applicable' => false,
        ],
        roles: [['role' => PartyRoleType::Consignee->value]],
        remark: 'Create consignee',
    );
    $billingCustomer = $parties->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Billing Customer Mills',
            'contact_number' => '9800020004',
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
            'name' => 'Market Transporter',
            'contact_number' => '9800020003',
            'pan' => 'ABCDE1234F',
            'state' => 'MH',
            'city' => 'Thane',
            'is_gst_applicable' => false,
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'ABCDE1234F',
                'pan_status' => 'active',
                'declaration' => true,
                'goods_vehicles_count' => 2,
            ],
        ]],
        remark: 'Create transporter',
    );

    $marketVehicle = Vehicle::query()->create([
        'company_id' => $company->id,
        'branch_id' => null,
        'registration_number' => 'MH12LRMKT01',
        'is_active' => true,
        'ownership' => VehicleOwnership::Market->value,
        'owner_party_id' => $transporter->id,
    ]);

    $otherOwner = User::factory()->customer()->create();
    $otherCompany = $workspace->createCompany($otherOwner, 'Other LR Co');
    $otherBranch = $otherCompany->branches()->firstOrFail();

    return [
        'owner' => $owner,
        'company' => $company,
        'branch' => $branch,
        'member' => $member,
        'viewer' => $viewer,
        'consignor' => $consignor,
        'consignee' => $consignee,
        'billingCustomer' => $billingCustomer,
        'transporter' => $transporter,
        'vehicle' => $marketVehicle,
        'goodsType' => $goodsType,
        'packageType' => $packageType,
        'otherCompany' => $otherCompany,
        'otherBranch' => $otherBranch,
    ];
}

function lrActivate(Company $company, Branch $branch): void
{
    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: $branch->id,
        ownerId: (string) $company->owner_user_id,
    ));
}

function lrPayload(array $ctx, array $overrides = []): array
{
    return array_merge([
        'workflow_context' => 'broker',
        'vehicle_id' => $ctx['vehicle']->id,
        'number_mode' => 'auto',
        'lr_date' => now()->toDateString(),
        'from_text' => 'Mumbai',
        'to_text' => 'Pune',
        'consignor_party_id' => $ctx['consignor']->id,
        'consignee_party_id' => $ctx['consignee']->id,
        'billing_customer_party_id' => $ctx['billingCustomer']->id,
        'intended_payee_type' => 'owner',
        'intended_payee_party_id' => $ctx['transporter']->id,
        'goods' => [
            [
                'goods_type_id' => $ctx['goodsType']->id,
                'package_type_id' => $ctx['packageType']->id,
                'goods_description' => 'Cartons',
                'package_count' => 10,
                'actual_weight' => 100,
                'charged_weight' => 110,
                'freight_rate_type' => FreightRateType::PerPackageUnit->value,
                'freight_rate' => 150,
                'goods_value' => 5000,
            ],
        ],
        'eway_bills' => [
            [
                'ewb_number' => 'EWB1234567890',
                'ewb_date' => now()->toDateString(),
                'valid_until' => now()->addDays(5)->toDateString(),
            ],
        ],
        'additional_charges' => [
            [
                'description' => 'Loading',
                'amount' => 250,
            ],
        ],
    ], $overrides);
}

test('freight calculator covers all rate types', function () {
    $calc = new FreightCalculator;

    expect($calc->compute(FreightRateType::FixedAmount, '1250.5'))->toBe('1250.50')
        ->and($calc->compute(FreightRateType::PerPackageUnit, '10', '12'))->toBe('120.00')
        ->and($calc->compute(FreightRateType::PerKg, '2.5', null, '40'))->toBe('100.00')
        // Historical weight_kg semantics: rate × KG.
        ->and($calc->compute(FreightRateType::PerActualWeight, '3', null, '20'))->toBe('60.00')
        ->and($calc->compute(FreightRateType::PerMt, '1000', null, '2500'))->toBe('2500.00')
        ->and($calc->compute(FreightRateType::PerChargedWeight, '4', null, null, '15'))->toBe('60.00')
        // New weight_mt semantics: rate × (KG/1000).
        ->and($calc->compute(
            FreightRateType::PerActualWeight,
            '2500',
            null,
            '25000',
            null,
            FreightRateSemantics::WeightMt,
        ))->toBe('62500.00')
        ->and($calc->compute(
            FreightRateType::PerChargedWeight,
            '2500',
            null,
            null,
            '26000',
            FreightRateSemantics::WeightMt,
        ))->toBe('65000.00');
});

test('create computes goods freight totals and persists ewb charges', function () {
    $ctx = lrWorkspace();
    lrActivate($ctx['company'], $ctx['branch']);

    $service = app(LorryReceiptService::class);
    $lr = $service->create($ctx['company'], $ctx['branch'], $ctx['member'], lrPayload($ctx));

    expect($lr->status)->toBe(LrStatus::Pending)
        ->and($lr->freight_total)->toBe('1500.00')
        ->and($lr->additional_charges_total)->toBe('250.00')
        ->and($lr->goods_value)->toBe('5000.00')
        ->and($lr->goodsRows)->toHaveCount(1)
        ->and($lr->goodsRows->first()->freight_amount)->toBe('1500.00')
        ->and($lr->ewayBills)->toHaveCount(1)
        ->and($lr->additionalCharges)->toHaveCount(1);
});

test('manual numbering uniqueness collision is rejected', function () {
    $ctx = lrWorkspace();
    lrActivate($ctx['company'], $ctx['branch']);

    $allocator = new DocumentNumberAllocator;
    $allocator->allocateManual(
        $ctx['company']->id,
        DocumentNumberType::LorryReceipt,
        DocumentNumberScopeType::Company,
        'LR-MANUAL-1',
    );

    $service = app(LorryReceiptService::class);

    expect(fn () => $service->create(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        lrPayload($ctx, [
            'number_mode' => 'manual',
            'lr_number' => 'LR-MANUAL-1',
        ]),
    ))->toThrow(LorryReceiptConflictException::class);
});

test('market bilty requires a payee when the vehicle master has no truck owner', function () {
    $ctx = lrWorkspace();
    lrActivate($ctx['company'], $ctx['branch']);
    $ctx['vehicle']->forceFill(['owner_party_id' => null])->save();
    $service = app(LorryReceiptService::class);

    expect(fn () => $service->create($ctx['company'], $ctx['branch'], $ctx['member'], lrPayload($ctx)))
        ->toThrow(ValidationException::class, 'This market vehicle has no Truck Owner.');

    expect(fn () => $service->create($ctx['company'], $ctx['branch'], $ctx['member'], lrPayload($ctx, [
        'intended_payee_type' => null,
    ])))->toThrow(ValidationException::class, 'Select the Agency to pay');

    $lr = $service->create($ctx['company'], $ctx['branch'], $ctx['member'], lrPayload($ctx, [
        'intended_payee_type' => 'agency',
    ]));

    expect($lr->intended_payee_party_id)->toBe($ctx['transporter']->id);
});

test('blank bilty number allocates automatically without client number_mode', function () {
    $ctx = lrWorkspace();
    lrActivate($ctx['company'], $ctx['branch']);

    $payload = lrPayload($ctx);
    unset($payload['number_mode']);
    $payload['lr_number'] = '   ';

    $lr = app(LorryReceiptService::class)->create(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        $payload,
    );

    expect($lr->lr_number)->not->toBe('')
        ->and(trim((string) $lr->lr_number))->not->toBe('');
});

test('entered bilty number is retained without client number_mode', function () {
    $ctx = lrWorkspace();
    lrActivate($ctx['company'], $ctx['branch']);

    $payload = lrPayload($ctx);
    unset($payload['number_mode']);
    $payload['lr_number'] = 'BILTY-PO-1001';

    $lr = app(LorryReceiptService::class)->create(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        $payload,
    );

    expect($lr->lr_number)->toBe('BILTY-PO-1001');
});

test('http create accepts empty lr_number without number_mode field', function () {
    $ctx = lrWorkspace();

    lrLoginAs($ctx['member'], 'member-secret');
    enterBranchWorkspace($ctx['company'], $ctx['branch'])->assertRedirect();

    $payload = lrPayload($ctx, [
        'form_mode' => 'truck',
        'vehicle_id' => $ctx['vehicle']->id,
        'lr_number' => '',
        'eway_bills' => [
            [
                'ewb_number' => 'EWB-BLANK-NUM-'.uniqid(),
                'ewb_date' => now()->toDateString(),
                'valid_until' => now()->addDays(5)->toDateString(),
            ],
        ],
    ]);
    unset($payload['number_mode']);

    $before = LorryReceipt::query()->where('company_id', $ctx['company']->id)->whereNull('deleted_at')->count();

    $this->post(route('branch.lorry-receipts.store'), $payload)
        ->assertRedirect();

    expect(LorryReceipt::query()->where('company_id', $ctx['company']->id)->whereNull('deleted_at')->count())
        ->toBe($before + 1);
});

test('tenant isolation blocks cross-company party usage', function () {
    $ctx = lrWorkspace();
    lrActivate($ctx['company'], $ctx['branch']);

    $otherParty = Party::factory()->create(['company_id' => $ctx['otherCompany']->id]);
    $service = app(LorryReceiptService::class);

    expect(fn () => $service->create(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        lrPayload($ctx, [
            'consignor_party_id' => $otherParty->id,
        ]),
    ))->toThrow(ValidationException::class);
});

test('cancel and soft-delete enforce pending rules', function () {
    $ctx = lrWorkspace();
    lrActivate($ctx['company'], $ctx['branch']);
    $service = app(LorryReceiptService::class);

    $lr = $service->create($ctx['company'], $ctx['branch'], $ctx['member'], lrPayload($ctx));
    $cancelled = $service->cancel($lr, $ctx['member'], 'Customer cancelled');

    expect($cancelled->status)->toBe(LrStatus::Cancelled)
        ->and($cancelled->cancellation_remark)->toBe('Customer cancelled');

    expect(fn () => $service->softDelete($cancelled, $ctx['member']))
        ->toThrow(LorryReceiptDomainException::class);

    $pending = $service->create(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        lrPayload($ctx, [
            'eway_bills' => [[
                'ewb_number' => 'EWB999',
                'valid_until' => now()->addDays(3)->toDateString(),
            ]],
        ]),
    );
    $service->softDelete($pending, $ctx['member'], 'Test soft delete pending bilty');

    expect(LorryReceipt::query()->whereKey($pending->id)->exists())->toBeFalse()
        ->and(LorryReceipt::withTrashed()->whereKey($pending->id)->exists())->toBeTrue();
});

test('update is rejected when delivered', function () {
    $ctx = lrWorkspace();
    lrActivate($ctx['company'], $ctx['branch']);
    $service = app(LorryReceiptService::class);

    $lr = $service->create($ctx['company'], $ctx['branch'], $ctx['member'], lrPayload($ctx));
    $lr->forceFill([
        'status' => LrStatus::Delivered,
        'delivered_at' => now(),
    ])->save();

    expect(fn () => $service->update($lr->fresh(), $ctx['member'], lrPayload($ctx)))
        ->toThrow(LorryReceiptDomainException::class);

    expect(fn () => $service->cancel($lr->fresh(), $ctx['member'], 'too late'))
        ->toThrow(LorryReceiptDomainException::class);
});

function lrLoginAs(User $user, string $password): void
{
    if (auth()->check()) {
        auth()->logout();
    }
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();

    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertRedirect();
    test()->assertAuthenticatedAs($user);
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

test('http authorization denial and happy path index create store', function () {
    $ctx = lrWorkspace();

    lrLoginAs($ctx['viewer'], 'viewer-secret');
    enterBranchWorkspace($ctx['company'], $ctx['branch'])->assertRedirect();

    $this->get(route('branch.lorry-receipts.create'))->assertForbidden();
    $this->get(route('branch.lorry-receipts.index'))->assertOk();

    lrLoginAs($ctx['member'], 'member-secret');
    enterBranchWorkspace($ctx['company'], $ctx['branch'])->assertRedirect();

    // First-company trial entitles all core modules → shared truck form (no chooser).
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

    $response = $this->post(route('branch.lorry-receipts.store'), lrPayload($ctx, [
        'form_mode' => 'truck',
        'vehicle_id' => $ctx['vehicle']->id,
    ]));
    $response->assertRedirect();

    $lr = LorryReceipt::query()
        ->where('company_id', $ctx['company']->id)
        ->where('branch_id', $ctx['branch']->id)
        ->first();

    expect($lr)->not->toBeNull()
        ->and($lr->freight_total)->toBe('1500.00');

    $response->assertRedirect(route('branch.lorry-receipts.show', [$lr]));
});

test('broker bilty store can redirect to create lorry hire', function () {
    $ctx = lrWorkspace();
    lrGrantRolePermission('customer.admin', PermissionCodes::BROKER_LORRY_HIRE_CREATE);

    lrLoginAs($ctx['member'], 'member-secret');
    enterBranchWorkspace($ctx['company'], $ctx['branch'])->assertRedirect();

    $response = $this->post(route('branch.lorry-receipts.store'), lrPayload($ctx, [
        'form_mode' => 'truck',
        'vehicle_id' => $ctx['vehicle']->id,
        'redirect_to_hire' => '1',
    ]));

    $lr = LorryReceipt::query()
        ->where('company_id', $ctx['company']->id)
        ->where('branch_id', $ctx['branch']->id)
        ->latest('created_at')
        ->first();

    expect($lr)->not->toBeNull();
    $response->assertRedirect(route('branch.broker.lorry-hires.create-from-lr', [$lr]));
});

test('tenant mismatch raises authorization exception', function () {
    $ctx = lrWorkspace();
    TenantContextHolder::inject(new TenantContext(
        companyId: $ctx['otherCompany']->id,
        branchId: $ctx['otherBranch']->id,
        ownerId: (string) $ctx['otherCompany']->owner_user_id,
    ));

    $service = app(LorryReceiptService::class);

    expect(fn () => $service->create(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        lrPayload($ctx),
    ))->toThrow(LorryReceiptAuthorizationException::class);
});
