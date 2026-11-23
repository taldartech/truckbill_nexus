<?php

/**
 * Enquiry quotation freight must not leak to No-Freight View personas (AUD-012).
 */

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Enquiry\EnquiryQuotationService;
use App\Domains\Enquiry\EnquiryService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerWebSession;
use App\Models\Enquiry;
use App\Models\OwnerCatalogueItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function enquiryNfGrant(string $roleSlug, string $code): void
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
 *     viewer: User,
 *     admin: User,
 *     enquiry: Enquiry
 * }
 */
function enquiryNfWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    foreach ([
        PermissionCodes::ENQUIRY_VIEW,
        PermissionCodes::ENQUIRY_CREATE,
        PermissionCodes::ENQUIRY_EDIT,
        PermissionCodes::QUOTATION_CREATE,
        PermissionCodes::QUOTATION_VIEW,
    ] as $code) {
        enquiryNfGrant('customer.admin', $code);
    }

    enquiryNfGrant('customer.viewer_no_freight', PermissionCodes::ENQUIRY_VIEW);
    enquiryNfGrant('customer.viewer_no_freight', PermissionCodes::QUOTATION_VIEW);

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'Enquiry NF Co');
    $branch = $company->branches()->firstOrFail();
    tripActivatePaidModules($owner, $company, [SubscriptionModule::Broker->value]);

    $admin = User::factory()->customer()->create(['password' => Hash::make('admin-secret')]);
    $adminMembership = $workspace->addMembership($company, $admin, 'Join admin');
    $adminAssignment = $workspace->assignBranch($adminMembership, $branch, 'HO');
    $rbac->assignCustomerBranchRole(
        $owner,
        $adminMembership,
        $branch,
        $adminAssignment,
        Role::query()->where('slug', 'customer.admin')->where('domain', PermissionDomain::Customer->value)->firstOrFail(),
        'Admin',
    );

    $viewer = User::factory()->customer()->create(['password' => Hash::make('viewer-secret')]);
    $viewerMembership = $workspace->addMembership($company, $viewer, 'Join viewer');
    $viewerAssignment = $workspace->assignBranch($viewerMembership, $branch, 'HO');
    $rbac->assignCustomerBranchRole(
        $owner,
        $viewerMembership,
        $branch,
        $viewerAssignment,
        Role::query()->where('slug', 'customer.viewer_no_freight')->where('domain', PermissionDomain::Customer->value)->firstOrFail(),
        'No freight viewer',
    );

    $truck = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'name' => 'Truck',
        'code' => 'TRK',
        'is_active' => true,
    ]);

    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: $branch->id,
        ownerId: (string) $company->owner_user_id,
    ));

    $customer = app(PartyService::class)->create(
        actor: $admin,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'NF Customer',
            'contact_number' => '9800011111',
            'state' => 'MH',
            'city' => 'Pune',
        ],
        roles: [['role' => PartyRoleType::Customer->value, 'attributes' => []]],
        remark: 'NF customer',
    );

    $transporter = app(PartyService::class)->create(
        actor: $admin,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'NF Transporter',
            'contact_number' => '9800022222',
            'state' => 'MH',
            'city' => 'Mumbai',
            'pan' => 'AABCT9999D',
            'is_gst_applicable' => false,
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'AABCT9999D',
                'pan_status' => 'active',
                'declaration' => true,
                'declaration_type' => 'owner',
                'goods_vehicles_count' => 2,
            ],
        ]],
        remark: 'NF transporter',
    );

    $enquiry = app(EnquiryService::class)->createManual($company, $branch, $admin, [
        'customer_party_id' => $customer->id,
        'pickup_location' => 'Pune',
        'delivery_location' => 'Mumbai',
        'vehicle_type_id' => $truck->id,
        'vehicle_quantity' => 1,
        'goods' => [[
            'goods_description' => 'Boxes',
            'package_count' => 10,
            'actual_weight' => 100,
            'charged_weight' => 100,
        ]],
    ]);

    app(EnquiryQuotationService::class)->create($company, $branch, $enquiry, $admin, [
        'transporter_party_id' => $transporter->id,
        'quoted_freight' => 15500.50,
        'remarks' => 'Confidential quote',
    ]);

    return compact('owner', 'company', 'branch', 'viewer', 'enquiry', 'admin');
}

function enquiryNfHttpAs(User $user, array $ctx): void
{
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

test('no-freight viewer receives null quoted_freight on enquiry show', function () {
    $ctx = enquiryNfWorkspace();
    enquiryNfHttpAs($ctx['viewer'], $ctx);

    test()->get(route('branch.enquiries.show', [$ctx['enquiry']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('capabilities.view_freight', false)
            ->where('enquiry.quotations.0.quoted_freight', null)
            ->where('enquiry.quotations.0.transporter_name', 'NF Transporter'));
});

test('admin with freight view still receives quoted_freight amounts', function () {
    $ctx = enquiryNfWorkspace();
    enquiryNfHttpAs($ctx['admin'], $ctx);

    test()->get(route('branch.enquiries.show', [$ctx['enquiry']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('capabilities.view_freight', true)
            ->where('enquiry.quotations.0.quoted_freight', fn ($value) => $value !== null && $value !== ''));
});
