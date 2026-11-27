<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Enquiry\EnquiryAssignmentService;
use App\Domains\Enquiry\EnquiryConversionService;
use App\Domains\Enquiry\EnquiryQuotationService;
use App\Domains\Enquiry\EnquiryService;
use App\Domains\Enquiry\Enums\EnquiryStatus;
use App\Domains\Enquiry\Enums\QuotationSelectionStatus;
use App\Domains\Enquiry\Exceptions\EnquiryAuthorizationException;
use App\Domains\Enquiry\Exceptions\EnquiryConflictException;
use App\Domains\Enquiry\Exceptions\EnquiryDomainException;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Enquiry;
use App\Models\EnquiryConversion;
use App\Models\LorryReceipt;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    $this->seed(SubscriptionCatalogueSeeder::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

function cdfGrant(string $roleSlug, string $code): void
{
    $role = Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $roleSlug)
        ->firstOrFail();
    $permission = Permission::query()->where('code', $code)->firstOrFail();
    RolePermission::query()->firstOrCreate(
        ['role_id' => $role->id, 'permission_id' => $permission->id],
        ['deactivated_at' => null],
    );
    Cache::flush();
}

/**
 * @return array{
 *     owner: User,
 *     company: Company,
 *     branch: Branch,
 *     member: User,
 *     customer: Party,
 *     transporter: Party,
 *     truck: OwnerCatalogueItem,
 *     goodsType: OwnerCatalogueItem,
 *     packageType: OwnerCatalogueItem,
 *     vehicle: Vehicle,
 *     otherCompany: Company,
 *     otherBranch: Branch
 * }
 */
function cdfWorkspace(): array
{
    foreach ([
        PermissionCodes::ENQUIRY_VIEW,
        PermissionCodes::ENQUIRY_CREATE,
        PermissionCodes::ENQUIRY_EDIT,
        PermissionCodes::ENQUIRY_ASSIGN,
        PermissionCodes::ENQUIRY_CONVERT,
        PermissionCodes::LR_CREATE,
    ] as $code) {
        cdfGrant('customer.admin', $code);
    }

    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);
    $parties = app(PartyService::class);

    $owner = User::factory()->customer()->create(['password' => Hash::make('secret')]);
    $company = $workspace->createCompany($owner, 'CDF Enquiry Co');
    $branch = $company->branches()->firstOrFail();

    test()->actingAs($owner);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);

    $member = User::factory()->customer()->create();
    $membership = $workspace->addMembership($company, $member, 'Join');
    $assignment = $workspace->assignBranch($membership, $branch, 'Assign');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $assignment,
        Role::query()->where('slug', 'customer.admin')->where('domain', PermissionDomain::Customer->value)->firstOrFail(),
        'Admin',
    );

    $goodsType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::GoodsType->value,
        'code' => 'G-CDF',
        'name' => 'CDF Goods',
        'is_active' => true,
    ]);
    $packageType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::PackageType->value,
        'code' => 'P-CDF',
        'name' => 'CDF Package',
        'is_active' => true,
    ]);
    $truck = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'code' => 'T-CDF',
        'name' => 'CDF Truck',
        'is_active' => true,
    ]);

    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: $branch->id,
        ownerId: (string) $company->owner_user_id,
    ));

    $customer = $parties->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'CDF Customer',
            'contact_number' => '9800010001',
            'state' => 'MH',
            'city' => 'Mumbai',
            'is_gst_applicable' => false,
        ],
        roles: [['role' => PartyRoleType::Customer->value, 'attributes' => []]],
        remark: 'Create customer',
    );

    $transporter = $parties->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'CDF Transporter',
            'contact_number' => '9800010002',
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

    $vehicle = Vehicle::query()->create([
        'company_id' => $company->id,
        'branch_id' => null,
        'registration_number' => 'MH12CDF001',
        'is_active' => true,
        'ownership' => VehicleOwnership::Market->value,
        'owner_party_id' => $transporter->id,
        'truck_type_id' => $truck->id,
    ]);

    $enquiry = app(EnquiryService::class)->createManual($company, $branch, $owner, [
        'customer_party_id' => $customer->id,
        'pickup_location' => 'Mumbai',
        'delivery_location' => 'Pune',
        'vehicle_type_id' => $truck->id,
        'vehicle_quantity' => 2,
        'goods' => [[
            'goods_type_id' => $goodsType->id,
            'package_type_id' => $packageType->id,
            'goods_description' => 'Steel coils',
            'package_count' => 10,
            'actual_weight' => 100,
            'charged_weight' => 110,
        ]],
    ]);

    TenantContextHolder::clear();

    $otherOwner = User::factory()->customer()->create();
    $otherCompany = $workspace->createCompany($otherOwner, 'CDF Other Co');
    $otherBranch = $otherCompany->branches()->firstOrFail();

    return [
        'owner' => $owner,
        'company' => $company,
        'branch' => $branch,
        'member' => $member,
        'customer' => $customer,
        'transporter' => $transporter,
        'truck' => $truck,
        'goodsType' => $goodsType,
        'packageType' => $packageType,
        'vehicle' => $vehicle,
        'enquiry' => $enquiry,
        'otherCompany' => $otherCompany,
        'otherBranch' => $otherBranch,
    ];
}

function cdfActivate(array $ctx): void
{
    TenantContextHolder::inject(new TenantContext(
        companyId: $ctx['company']->id,
        branchId: $ctx['branch']->id,
        ownerId: (string) $ctx['company']->owner_user_id,
    ));
}

test('website enquiry can be assigned to branch with history', function () {
    $ctx = cdfWorkspace();
    $unassigned = Enquiry::factory()->forCompany($ctx['company'])->unassigned()->create();

    cdfActivate($ctx);
    $updated = app(EnquiryAssignmentService::class)->assign($ctx['company'], $ctx['branch'], $unassigned, $ctx['owner'], [
        'to_branch_id' => $ctx['branch']->id,
        'to_assigned_user_id' => $ctx['member']->id,
        'remark' => 'Assign to operations desk',
    ]);

    expect($updated->branch_id)->toBe($ctx['branch']->id)
        ->and($updated->assigned_user_id)->toBe($ctx['member']->id)
        ->and($updated->assignmentHistories)->toHaveCount(1)
        ->and($updated->status)->toBe(EnquiryStatus::UnderReview);
});

test('duplicate quotation for transporter is rejected', function () {
    $ctx = cdfWorkspace();
    $quotations = app(EnquiryQuotationService::class);
    cdfActivate($ctx);

    $quotations->create($ctx['company'], $ctx['branch'], $ctx['enquiry'], $ctx['owner'], [
        'transporter_party_id' => $ctx['transporter']->id,
        'quoted_freight' => 12000,
    ]);

    expect(fn () => $quotations->create($ctx['company'], $ctx['branch'], $ctx['enquiry'], $ctx['owner'], [
        'transporter_party_id' => $ctx['transporter']->id,
        'quoted_freight' => 15000,
    ]))->toThrow(EnquiryConflictException::class);
});

test('preferred quotation selection updates status and deselects others', function () {
    $ctx = cdfWorkspace();
    $quotations = app(EnquiryQuotationService::class);
    cdfActivate($ctx);

    $first = $quotations->create($ctx['company'], $ctx['branch'], $ctx['enquiry'], $ctx['owner'], [
        'transporter_party_id' => $ctx['transporter']->id,
        'quoted_freight' => 10000,
    ]);

    $otherTransporter = app(PartyService::class)->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: [
            'name' => 'CDF Transporter B',
            'contact_number' => '9800010003',
            'pan' => 'BCDEF1234G',
            'state' => 'MH',
            'city' => 'Nashik',
            'is_gst_applicable' => false,
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'BCDEF1234G',
                'pan_status' => 'active',
                'declaration' => true,
                'goods_vehicles_count' => 1,
            ],
        ]],
        remark: 'Second transporter',
    );

    $second = $quotations->create($ctx['company'], $ctx['branch'], $ctx['enquiry'], $ctx['owner'], [
        'transporter_party_id' => $otherTransporter->id,
        'quoted_freight' => 9000,
    ]);

    $enquiry = $quotations->selectPreferred($ctx['company'], $ctx['branch'], $ctx['enquiry'], $first, $ctx['owner']);

    expect($enquiry->status)->toBe(EnquiryStatus::Selected)
        ->and($first->fresh()->selection_status)->toBe(QuotationSelectionStatus::Preferred)
        ->and($second->fresh()->selection_status)->toBe(QuotationSelectionStatus::NotSelected);
});

test('broadcast request enqueues outbox only', function () {
    $ctx = cdfWorkspace();
    cdfActivate($ctx);

    $request = app(EnquiryQuotationService::class)->requestBroadcast($ctx['company'], $ctx['branch'], $ctx['enquiry'], $ctx['owner'], [
        'transporter_party_ids' => [$ctx['transporter']->id],
        'channels' => ['email'],
        'idempotency_key' => 'broadcast-'.Str::uuid(),
    ]);

    expect($request->status->value)->toBe('queued')
        ->and(OutboxMessage::query()->where('event_type', 'quotation.broadcast.requested')->exists())->toBeTrue();
});

test('option c conversion creates n lrs with reconciliation and idempotency', function () {
    $ctx = cdfWorkspace();
    cdfActivate($ctx);

    $quotations = app(EnquiryQuotationService::class);
    $quote = $quotations->create($ctx['company'], $ctx['branch'], $ctx['enquiry'], $ctx['owner'], [
        'transporter_party_id' => $ctx['transporter']->id,
        'quoted_freight' => 20000,
        'extra_charges_amount' => 1000,
    ]);
    $quotations->selectPreferred($ctx['company'], $ctx['branch'], $ctx['enquiry'], $quote, $ctx['owner']);

    $conversion = app(EnquiryConversionService::class);
    $preview = $conversion->preview($ctx['company'], $ctx['branch'], $ctx['enquiry']->fresh());
    expect($preview['eligible'])->toBeTrue();

    $payload = [
        'workflow_context' => 'broker',
        'vehicle_id' => $ctx['vehicle']->id,
        'idempotency_key' => 'conv-'.$ctx['enquiry']->id,
        'vehicles' => collect($preview['suggested_vehicles'])->map(fn (array $vehicle): array => [
            'goods' => $vehicle['goods'],
        ])->all(),
    ];

    $result = $conversion->convert($ctx['company'], $ctx['branch'], $ctx['enquiry']->fresh(), $ctx['owner'], $payload);

    expect($result['lorry_receipts'])->toHaveCount(2)
        ->and(EnquiryConversion::query()->where('enquiry_id', $ctx['enquiry']->id)->count())->toBe(1)
        ->and($ctx['enquiry']->fresh()->status)->toBe(EnquiryStatus::Converted)
        ->and(LorryReceipt::query()->where('company_id', $ctx['company']->id)->count())->toBe(2)
        ->and(Trip::query()->where('company_id', $ctx['company']->id)->count())->toBe(0);

    $again = $conversion->convert($ctx['company'], $ctx['branch'], $ctx['enquiry']->fresh(), $ctx['owner'], $payload);
    expect($again['conversion']->id)->toBe($result['conversion']->id)
        ->and(LorryReceipt::query()->where('company_id', $ctx['company']->id)->count())->toBe(2);
});

test('tenant isolation blocks cross company conversion preview', function () {
    $ctx = cdfWorkspace();
    TenantContextHolder::inject(new TenantContext(
        companyId: $ctx['otherCompany']->id,
        branchId: $ctx['otherBranch']->id,
        ownerId: (string) $ctx['otherCompany']->owner_user_id,
    ));

    $conversion = app(EnquiryConversionService::class);

    expect(fn () => $conversion->preview($ctx['otherCompany'], $ctx['otherBranch'], $ctx['enquiry']))
        ->toThrow(EnquiryAuthorizationException::class);
});

test('assignment tenant isolation rejects foreign branch context', function () {
    $ctx = cdfWorkspace();
    $unassigned = Enquiry::factory()->forCompany($ctx['company'])->unassigned()->create();

    expect(fn () => app(EnquiryAssignmentService::class)->assign(
        $ctx['company'],
        $ctx['otherBranch'],
        $unassigned,
        $ctx['owner'],
        ['to_branch_id' => $ctx['branch']->id, 'remark' => 'Cross branch attempt'],
    ))->toThrow(EnquiryDomainException::class);
});
