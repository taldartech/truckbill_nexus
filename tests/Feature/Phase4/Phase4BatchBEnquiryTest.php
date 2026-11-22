<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Enquiry\EnquiryService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySubscriptionTrial;
use App\Models\CustomerWebSession;
use App\Models\Enquiry;
use App\Models\EnquiryGoodsRow;
use App\Models\EnquiryStatusHistory;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function batchBActingAs(User $user): void
{
    // Flush session so Customer single-session claim can rebind for the new actor.
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
}

function batchBCustomerRole(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

function batchBGrantRolePermission(string $roleSlug, string $permissionCode): void
{
    $role = batchBCustomerRole($roleSlug);
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
 *     party: Party,
 *     truck: OwnerCatalogueItem,
 *     goodsType: OwnerCatalogueItem,
 *     packageType: OwnerCatalogueItem,
 *     otherCompany: Company,
 *     otherBranch: Branch,
 *     otherParty: Party
 * }
 */
function batchBWorkspace(bool $withEnquiryCreate = true): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    if ($withEnquiryCreate) {
        batchBGrantRolePermission('customer.admin', PermissionCodes::ENQUIRY_CREATE);
        batchBGrantRolePermission('customer.admin', PermissionCodes::ENQUIRY_EDIT);
        batchBGrantRolePermission('customer.admin', PermissionCodes::ENQUIRY_VIEW);
        batchBGrantRolePermission('customer.admin', PermissionCodes::ENQUIRY_ASSIGN);
    }

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'Enquiry Co');
    $branch = $company->branches()->firstOrFail();

    // Party creation uses service-layer actingAs (no Fortify login required).
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
        batchBCustomerRole('customer.admin'),
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
        batchBCustomerRole('customer.viewer'),
        'Grant viewer',
    );

    $truck = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'code' => 'TATA407',
        'name' => 'Tata 407',
    ]);
    $goodsType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::GoodsType->value,
        'code' => 'GEN',
        'name' => 'General',
    ]);
    $packageType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::PackageType->value,
        'code' => 'BAG',
        'name' => 'Bag',
    ]);

    $party = app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Customer Party',
            'contact_number' => '9876543210',
            'state' => 'Maharashtra',
            'city' => 'Pune',
        ],
        roles: [
            ['role' => PartyRoleType::Customer->value, 'attributes' => []],
        ],
    );

    $otherOwner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $otherCompany = $workspace->createCompany($otherOwner, 'Other Enquiry Co');
    $otherBranch = $otherCompany->branches()->firstOrFail();
    test()->actingAs($otherOwner);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    $otherParty = app(PartyService::class)->create(
        actor: $otherOwner,
        company: $otherCompany,
        branch: null,
        attributes: [
            'name' => 'Foreign Customer',
            'contact_number' => '9000000000',
            'state' => 'Gujarat',
            'city' => 'Surat',
        ],
        roles: [
            ['role' => PartyRoleType::Customer->value, 'attributes' => []],
        ],
    );

    return compact(
        'owner',
        'company',
        'branch',
        'member',
        'viewer',
        'party',
        'truck',
        'goodsType',
        'packageType',
        'otherCompany',
        'otherBranch',
        'otherParty',
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function batchBManualPayload(array $workspace, array $overrides = []): array
{
    return array_merge([
        'customer_party_id' => $workspace['party']->id,
        'pickup_location' => 'Pune Warehouse',
        'delivery_location' => 'Mumbai Port',
        'vehicle_type_id' => $workspace['truck']->id,
        'vehicle_quantity' => 2,
        'expected_loading_date' => '2026-12-01',
        'instructions' => 'Handle with care',
        'goods' => [
            [
                'goods_type_id' => $workspace['goodsType']->id,
                'package_type_id' => $workspace['packageType']->id,
                'goods_description' => 'Steel rods',
                'package_count' => 10,
                'actual_weight' => 100.5,
                'charged_weight' => 110,
            ],
            [
                'goods_type_id' => $workspace['goodsType']->id,
                'goods_description' => 'Spare parts',
                'package_count' => 2,
                'actual_weight' => 5,
                'charged_weight' => 5,
            ],
        ],
    ], $overrides);
}

test('authorized branch user creates enquiry with company branch goods status audit and outbox', function () {
    $ws = batchBWorkspace();
    batchBActingAs($ws['member']);

    $response = $this->postJson(
        route('branch.enquiries.store', [$ws['company'], $ws['branch']]),
        batchBManualPayload($ws),
    );

    $response->assertCreated()
        ->assertJsonPath('data.company_id', $ws['company']->id)
        ->assertJsonPath('data.branch_id', $ws['branch']->id)
        ->assertJsonPath('data.source', 'manual')
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.vehicle_quantity', 2)
        ->assertJsonCount(2, 'data.goods')
        ->assertJsonPath('data.goods.0.position', 1)
        ->assertJsonPath('data.goods.1.position', 2);

    $enquiryId = $response->json('data.id');
    expect(EnquiryStatusHistory::query()->where('enquiry_id', $enquiryId)->count())->toBe(1)
        ->and(AuditEntry::query()->where('subject_type', Enquiry::class)->where('subject_id', $enquiryId)->where('action', 'created')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'enquiry.created')->where('company_id', $ws['company']->id)->count())->toBe(1)
        ->and(EnquiryGoodsRow::query()->where('enquiry_id', $enquiryId)->count())->toBe(2);
});

test('zero vehicle quantity and vehicle type arrays are rejected', function () {
    $ws = batchBWorkspace();
    batchBActingAs($ws['member']);
    $this->postJson(route('branch.enquiries.store', [$ws['company'], $ws['branch']]), batchBManualPayload($ws, [
        'vehicle_quantity' => 0,
    ]))->assertUnprocessable();
});

test('vehicle type arrays are rejected', function () {
    $ws = batchBWorkspace();
    batchBActingAs($ws['member']);
    $this->postJson(route('branch.enquiries.store', [$ws['company'], $ws['branch']]), batchBManualPayload($ws, [
        'vehicle_types' => [$ws['truck']->id],
    ]))->assertUnprocessable();
});

test('invalid catalogue reference is rejected', function () {
    $ws = batchBWorkspace();
    batchBActingAs($ws['member']);
    $this->postJson(route('branch.enquiries.store', [$ws['company'], $ws['branch']]), batchBManualPayload($ws, [
        'vehicle_type_id' => (string) Str::uuid(),
    ]))->assertUnprocessable();
});

test('cross-company customer is rejected', function () {
    $ws = batchBWorkspace();
    batchBActingAs($ws['member']);
    $this->postJson(route('branch.enquiries.store', [$ws['company'], $ws['branch']]), batchBManualPayload($ws, [
        'customer_party_id' => $ws['otherParty']->id,
    ]))->assertUnprocessable();
});

test('unauthorized viewer wrong branch inactive company and missing enquiry.create are rejected', function () {
    $ws = batchBWorkspace();

    batchBActingAs($ws['viewer']);
    $this->postJson(route('branch.enquiries.store', [$ws['company'], $ws['branch']]), batchBManualPayload($ws))
        ->assertForbidden();

    // Cross-company branch path must not authorize the member.
    batchBActingAs($ws['member']);
    $this->postJson(route('branch.enquiries.store', [$ws['company'], $ws['otherBranch']]), batchBManualPayload($ws))
        ->assertNotFound();

    app(CompanyWorkspaceService::class)->deactivateCompany(
        User::factory()->superAdmin()->create(),
        $ws['company'],
        'Deactivate for test',
    );
    batchBActingAs($ws['member']);
    $this->postJson(route('branch.enquiries.store', [$ws['company'], $ws['branch']]), batchBManualPayload($ws))
        ->assertForbidden();
});

test('platform permissions alone do not grant enquiry.create', function () {
    $ws = batchBWorkspace(withEnquiryCreate: false);
    batchBActingAs($ws['member']);

    $this->postJson(route('branch.enquiries.store', [$ws['company'], $ws['branch']]), batchBManualPayload($ws))
        ->assertForbidden();

    expect(
        RolePermission::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'customer.admin'))
            ->whereHas('permission', fn ($q) => $q->where('code', PermissionCodes::PLATFORM_CREATE))
            ->whereNull('deactivated_at')
            ->exists()
    )->toBeTrue()
        ->and(
            RolePermission::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'customer.admin'))
                ->whereHas('permission', fn ($q) => $q->where('code', PermissionCodes::ENQUIRY_CREATE))
                ->whereNull('deactivated_at')
                ->exists()
        )->toBeFalse();
});

test('subscription paid grace blocks enquiry mutation', function () {
    (new SubscriptionCatalogueSeeder)->run();

    $ws = batchBWorkspace();
    $clock = new SubscriptionClock;
    $clock->freeze(CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC'));

    CompanySubscriptionTrial::query()->where('owner_user_id', $ws['owner']->id)->delete();
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();

    batchBActingAs($ws['owner']);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $ws['owner'],
        company: $ws['company'],
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2027-03-01'),
    );

    $lifecycle = new SubscriptionLifecycleService(clock: $clock);
    $lifecycle->activateAfterVerifiedPayment($draft, CarbonImmutable::parse('2026-03-10'), $ws['owner']);
    $clock->freeze(CarbonImmutable::parse('2026-03-12 00:00:00', 'UTC'));

    $status = app(SubscriptionEntitlementGate::class)->evaluate($ws['company']);
    expect($status['branch_mutations_allowed'])->toBeFalse();

    batchBActingAs($ws['member']);
    $this->postJson(route('branch.enquiries.store', [$ws['company'], $ws['branch']]), batchBManualPayload($ws))
        ->assertForbidden();
});

test('edit without remark is rejected', function () {
    $ws = batchBWorkspace();
    batchBActingAs($ws['member']);

    $enquiryId = $this->postJson(
        route('branch.enquiries.store', [$ws['company'], $ws['branch']]),
        batchBManualPayload($ws),
    )->assertCreated()->json('data.id');

    // Re-bind session after create so CustomerWebSession matches the testing session id.
    batchBActingAs($ws['member']);
    $this->patchJson(route('branch.enquiries.update', [$ws['company'], $ws['branch'], $enquiryId]), [
        'pickup_location' => 'Updated Pickup',
    ])->assertUnprocessable();
});

test('edit with remark updates enquiry and preserves creation side effects', function () {
    $ws = batchBWorkspace();
    batchBActingAs($ws['member']);

    $enquiryId = $this->postJson(
        route('branch.enquiries.store', [$ws['company'], $ws['branch']]),
        batchBManualPayload($ws),
    )->assertCreated()->json('data.id');

    batchBActingAs($ws['member']);
    $this->patchJson(route('branch.enquiries.update', [$ws['company'], $ws['branch'], $enquiryId]), [
        'remark' => 'Corrected pickup',
        'pickup_location' => 'Updated Pickup',
    ])->assertOk()->assertJsonPath('data.pickup_location', 'Updated Pickup');

    expect(Enquiry::query()->whereKey($enquiryId)->value('assigned_user_id'))->toBeNull()
        ->and(OutboxMessage::query()->where('event_type', 'enquiry.created')->where('company_id', $ws['company']->id)->count())->toBe(1)
        ->and(AuditEntry::query()->where('subject_id', $enquiryId)->where('action', 'updated')->count())->toBe(1);
});

test('enquiry goods edit soft-deletes prior rows and retains history identifiers', function () {
    $ws = batchBWorkspace();
    batchBActingAs($ws['member']);

    $enquiryId = $this->postJson(
        route('branch.enquiries.store', [$ws['company'], $ws['branch']]),
        batchBManualPayload($ws),
    )->assertCreated()->json('data.id');

    $originalIds = EnquiryGoodsRow::query()
        ->where('enquiry_id', $enquiryId)
        ->orderBy('position')
        ->pluck('id')
        ->all();

    expect($originalIds)->toHaveCount(2);

    batchBActingAs($ws['member']);
    $this->patchJson(route('branch.enquiries.update', [$ws['company'], $ws['branch'], $enquiryId]), [
        'remark' => 'Replace goods rows',
        'goods' => [
            [
                'goods_type_id' => $ws['goodsType']->id,
                'package_type_id' => $ws['packageType']->id,
                'goods_description' => 'Replacement cargo',
                'package_count' => 3,
                'actual_weight' => 30,
                'charged_weight' => 30,
            ],
        ],
    ])->assertOk()->assertJsonCount(1, 'data.goods')->assertJsonPath('data.goods.0.position', 1);

    $active = EnquiryGoodsRow::query()->where('enquiry_id', $enquiryId)->orderBy('position')->get();
    $trashed = EnquiryGoodsRow::onlyTrashed()->where('enquiry_id', $enquiryId)->orderBy('position')->get();

    expect($active)->toHaveCount(1)
        ->and($trashed)->toHaveCount(2)
        ->and($trashed->pluck('id')->all())->toEqualCanonicalizing($originalIds)
        ->and($active->first()?->id)->not->toBeIn($originalIds)
        ->and($active->first()?->goods_description)->toBe('Replacement cargo');

    // Active position uniqueness still holds after soft-delete reuse of position 1.
    expect(
        EnquiryGoodsRow::query()->where('enquiry_id', $enquiryId)->where('position', 1)->count()
    )->toBe(1);
});

test('failed persistence rolls back enquiry goods number and outbox', function () {
    $ws = batchBWorkspace();
    batchBActingAs($ws['member']);

    $beforeEnquiries = Enquiry::query()->where('company_id', $ws['company']->id)->count();
    $beforeGoods = EnquiryGoodsRow::query()->where('company_id', $ws['company']->id)->count();
    $beforeOutbox = OutboxMessage::query()->where('company_id', $ws['company']->id)->count();

    $failed = false;
    try {
        DB::transaction(function () use ($ws): void {
            TenantContextHolder::inject(new TenantContext(
                companyId: $ws['company']->id,
                branchId: $ws['branch']->id,
                ownerId: (string) $ws['owner']->id,
            ));

            app(EnquiryService::class)->createManual(
                $ws['company'],
                $ws['branch'],
                $ws['member'],
                batchBManualPayload($ws),
            );

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        $failed = true;
    }

    expect($failed)->toBeTrue()
        ->and(Enquiry::query()->where('company_id', $ws['company']->id)->count())->toBe($beforeEnquiries)
        ->and(EnquiryGoodsRow::query()->where('company_id', $ws['company']->id)->count())->toBe($beforeGoods)
        ->and(OutboxMessage::query()->where('company_id', $ws['company']->id)->count())->toBe($beforeOutbox);
});

test('unassigned queue listing requires enquiry.assign', function () {
    $ws = batchBWorkspace();
    batchBActingAs($ws['member']);

    Enquiry::factory()->forCompany($ws['company'])->unassigned()->create([
        'vehicle_type_id' => $ws['truck']->id,
    ]);

    $this->getJson(route('branch.enquiries.index', [$ws['company'], $ws['branch']]).'?unassigned=1')
        ->assertOk()
        ->assertJsonPath('meta.includes_unassigned', true);

    batchBGrantRolePermission('customer.viewer', PermissionCodes::ENQUIRY_VIEW);
    batchBActingAs($ws['viewer']);
    $this->getJson(route('branch.enquiries.index', [$ws['company'], $ws['branch']]).'?unassigned=1')
        ->assertOk()
        ->assertJsonPath('meta.includes_unassigned', false);
});
