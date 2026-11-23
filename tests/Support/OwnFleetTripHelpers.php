<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\OwnFleet\Trip\TripService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySubscriptionTrial;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

if (! function_exists('tripGrant')) {
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
}

if (! function_exists('tripWorkspace')) {
    /**
     * @param  list<string>  $modules
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
            PermissionCodes::TRIP_DISPATCH,
            PermissionCodes::TRIP_COMPLETE,
            PermissionCodes::TRIP_CANCEL,
            PermissionCodes::TRIP_DELETE,
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
}

if (! function_exists('tripActivatePaidModules')) {
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
}

if (! function_exists('tripActivate')) {
    function tripActivate(Company $company, Branch $branch): void
    {
        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: $branch->id,
            ownerId: (string) $company->owner_user_id,
        ));
    }
}

if (! function_exists('tripCreateOwnFleetLr')) {
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
}

if (! function_exists('tripCreatePendingTrip')) {
    function tripCreatePendingTrip(array $ctx): Trip
    {
        $lr = tripCreateOwnFleetLr($ctx);

        return app(TripService::class)->createFromLorryReceipt($lr, $ctx['member'], [
            'vehicle_id' => $ctx['vehicle']->id,
        ]);
    }
}
