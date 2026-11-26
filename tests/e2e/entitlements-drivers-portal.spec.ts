import { execFileSync } from 'node:child_process';
import { expect, type Page, test } from '@playwright/test';
import { loginAs } from './fixtures/auth';

const E2E_OWNER_EMAIL = 'e2e.entitlements@demo.test';
const E2E_MEMBER_EMAIL = 'e2e.operator@demo.test';
const E2E_TRIAL_OWNER_EMAIL = 'e2e.trial.owner@demo.test';
const E2E_PASSWORD = 'password';

const COMPANIES = {
    brokerOnly: 'E2E Broker Only',
    ownFleetOnly: 'E2E OwnFleet Only',
    partloadOnly: 'E2E Partload Only',
    brokerOwnFleet: 'E2E Broker + OwnFleet',
    allModules: 'E2E All Modules',
    activeTrial: 'E2E Active Trial',
    expiredSub: 'E2E Expired Sub',
    graceSub: 'E2E Grace Sub',
} as const;

function runPhpBootstrap(code: string): string {
    if (
        process.env.DB_DATABASE !== 'nexus_test' &&
        process.env.APP_ENV !== 'testing'
    ) {
        throw new Error(
            'Refusing E2E entitlement fixture setup outside nexus_test / testing.',
        );
    }

    try {
        return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
            cwd: process.cwd(),
            env: {
                ...process.env,
                CACHE_STORE: process.env.CACHE_STORE ?? 'file',
            },
            encoding: 'utf8',
            timeout: 120_000,
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (error: unknown) {
        const err = error as { stdout?: string; stderr?: string };
        throw new Error(
            `Bootstrap failed: ${err.stderr ?? ''} ${err.stdout ?? ''}`,
        );
    }
}

function seedIsolatedEntitlementCompanies(): void {
    const php = `
use App\\Domains\\Company\\CompanyWorkspaceService;
use App\\Domains\\Consignment\\Enums\\FreightRateType;
use App\\Domains\\Consignment\\LorryReceiptService;
use App\\Domains\\Master\\Catalogue\\Enums\\OwnerCatalogueType;
use App\\Domains\\Master\\Party\\Enums\\PartyRoleType;
use App\\Domains\\Master\\Party\\PartyService;
use App\\Domains\\Master\\TyreLayout\\Enums\\TyreAxleConfiguration;
use App\\Domains\\Master\\TyreLayout\\TyreLayoutService;
use App\\Domains\\Master\\Vehicle\\Enums\\VehicleOwnership;
use App\\Domains\\Master\\Vehicle\\VehicleService;
use App\\Domains\\Rbac\\PermissionCodes;
use App\\Domains\\Rbac\\RoleAssignmentService;
use App\\Domains\\Subscription\\Contract\\CompanySubscriptionContractService;
use App\\Domains\\Subscription\\Entitlement\\CompanyCapabilityResolver;
use App\\Domains\\Subscription\\Enums\\SubscriptionTenure;
use App\\Domains\\Subscription\\Lifecycle\\SubscriptionLifecycleService;
use App\\Models\\Branch;
use App\\Models\\BranchRoleAssignment;
use App\\Models\\Company;
use App\\Models\\CompanyMembership;
use App\\Models\\CompanySubscription;
use App\\Models\\CompanySubscriptionTrial;
use App\\Models\\OwnerCatalogueItem;
use App\\Models\\OwnerTyreLayout;
use App\\Models\\Party;
use App\\Models\\Role;
use App\\Models\\RolePermission;
use App\\Models\\Station;
use App\\Models\\SubscriptionCatalogueVersion;
use App\\Models\\SubscriptionPlan;
use App\\Models\\User;
use App\\Models\\Vehicle;
use App\\Support\\Tenant\\TenantContext;
use App\\Support\\Tenant\\TenantContextHolder;
use Carbon\\CarbonImmutable;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Str;

$adminRole = Role::query()->where('domain', 'customer')->where('slug', 'customer.admin')->firstOrFail();
$permMap = \\App\\Models\\Permission::query()->pluck('id', 'code');
foreach ([
    PermissionCodes::PLATFORM_VIEW,
    PermissionCodes::PLATFORM_CREATE,
    PermissionCodes::PLATFORM_EDIT,
    PermissionCodes::LR_VIEW,
    PermissionCodes::LR_CREATE,
    PermissionCodes::LR_EDIT,
    PermissionCodes::TRIP_VIEW,
    PermissionCodes::TRIP_CREATE,
    PermissionCodes::INVOICE_VIEW,
    PermissionCodes::PAYMENT_VIEW,
    PermissionCodes::ENQUIRY_VIEW,
    PermissionCodes::BROKER_LORRY_HIRE_VIEW,
    PermissionCodes::BROKER_VOUCHER_VIEW,
    PermissionCodes::BROKER_DELIVERY_VIEW,
    PermissionCodes::BROKER_LINKED_BILTY_VIEW,
    PermissionCodes::BROKER_BOOK_BALANCE_VIEW,
    PermissionCodes::BROKER_INVOICE_VIEW,
    PermissionCodes::PARTLOAD_CHALLAN_VIEW,
    PermissionCodes::PARTLOAD_INWARD_VIEW,
    PermissionCodes::BRANCH_ACCOUNTING_VIEW,
] as $code) {
    $pid = $permMap[$code] ?? null;
    if ($pid && !RolePermission::query()->where('role_id', $adminRole->id)->where('permission_id', $pid)->whereNull('deactivated_at')->exists()) {
        RolePermission::query()->create(['role_id' => $adminRole->id, 'permission_id' => $pid]);
    }
}
\\Illuminate\\Support\\Facades\\Cache::flush();

if (!Company::query()->where('name', '${COMPANIES.graceSub}')->exists()) {
$workspace = app(CompanyWorkspaceService::class);
$rbac = app(RoleAssignmentService::class);
$contracts = app(CompanySubscriptionContractService::class);
$lifecycle = app(SubscriptionLifecycleService::class);
$parties = app(PartyService::class);
$vehicles = app(VehicleService::class);
$lrs = app(LorryReceiptService::class);
$tyres = app(TyreLayoutService::class);

$ensureUser = function (string $email, string $name): User {
    $u = User::query()->where('email', $email)->first();
    if ($u) {
        return $u;
    }
    $u = new User();
    $u->forceFill([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make('${E2E_PASSWORD}'),
        'identity_type' => 'customer',
        'is_super_admin' => false,
        'email_verified_at' => now(),
    ])->save();
    return $u;
};

$owner = $ensureUser('${E2E_OWNER_EMAIL}', 'E2E Entitlements Owner');
$member = $ensureUser('${E2E_MEMBER_EMAIL}', 'E2E Branch Operator');
$trialOwner = $ensureUser('${E2E_TRIAL_OWNER_EMAIL}', 'E2E Trial Owner');

$catalogue = SubscriptionCatalogueVersion::query()->where('is_active', true)->firstOrFail();

$assignAdmin = function (User $actor, Company $company, Branch $branch, User $user) use ($workspace, $rbac, $adminRole): void {
    $m = CompanyMembership::query()->firstOrCreate(
        ['company_id' => $company->id, 'user_id' => $user->id],
        ['status' => 'active', 'joined_at' => now()],
    );
    $a = $m->branchAssignments()->where('branch_id', $branch->id)->first()
        ?? $workspace->assignBranch($m, $branch, 'E2E branch assignment');
    if (!BranchRoleAssignment::query()->where('branch_assignment_id', $a->id)->where('role_id', $adminRole->id)->whereNull('deactivated_at')->exists()) {
        $rbac->assignCustomerBranchRole($actor, $m, $branch, $a, $adminRole, 'E2E admin role');
    }
};

$makeCompany = function (User $ownerUser, string $name, array $modules) use ($workspace, $contracts, $lifecycle, $catalogue, $assignAdmin): array {
    $company = Company::query()->where('owner_user_id', $ownerUser->id)->where('name', $name)->first()
        ?? $workspace->createCompany($ownerUser, $name, $name . ' HO');
    $branch = $company->branches()->firstOrFail();
    $assignAdmin($ownerUser, $company, $branch, $ownerUser);
    CompanySubscriptionTrial::query()->where('company_id', $company->id)->delete();
    CompanyCapabilityResolver::invalidate($company);
    if ($modules !== [] && !CompanySubscription::query()->where('company_id', $company->id)->where('status', 'active')->exists()) {
        Auth::login($ownerUser);
        $planIds = [];
        foreach ($modules as $mod) {
            $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('module', $mod)->where('is_active', true)->orderBy('sort_order')->firstOrFail();
            $planIds[] = $plan->id;
        }
        $renewal = CarbonImmutable::now()->addYear();
        $draft = $contracts->createDraftContract(
            actor: $ownerUser,
            company: $company,
            catalogue: $catalogue,
            planIds: $planIds,
            addonIds: [],
            tenure: SubscriptionTenure::Yearly,
            commonRenewalDate: $renewal,
        );
        $lifecycle->activateAfterVerifiedPayment($draft, $renewal, $ownerUser, 'E2E activate ' . implode(',', $modules));
    }
    return [$company, $branch];
};

[$brokerCo, $brokerBr] = $makeCompany($owner, '${COMPANIES.brokerOnly}', ['broker']);
$assignAdmin($owner, $brokerCo, $brokerBr, $member);

[$ofCo, $ofBr] = $makeCompany($owner, '${COMPANIES.ownFleetOnly}', ['ownfleet']);
[$plCo, $plBr] = $makeCompany($owner, '${COMPANIES.partloadOnly}', ['partload']);
[$boCo, $boBr] = $makeCompany($owner, '${COMPANIES.brokerOwnFleet}', ['broker', 'ownfleet']);
[$allCo, $allBr] = $makeCompany($owner, '${COMPANIES.allModules}', ['broker', 'ownfleet', 'partload']);

$trialCo = Company::query()->where('owner_user_id', $trialOwner->id)->where('name', '${COMPANIES.activeTrial}')->first()
    ?? $workspace->createCompany($trialOwner, '${COMPANIES.activeTrial}', 'Trial HO');
$trialBr = $trialCo->branches()->firstOrFail();
$assignAdmin($trialOwner, $trialCo, $trialBr, $trialOwner);

Auth::login($owner);
TenantContextHolder::set(new TenantContext($ofCo->id, $ofBr->id, (string) $owner->id));

$truckType = OwnerCatalogueItem::query()->where('owner_user_id', $owner->id)->where('catalogue_type', OwnerCatalogueType::TruckType->value)->where('name', 'E2E 20FT Closed')->first()
    ?? OwnerCatalogueItem::query()->create([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'name' => 'E2E 20FT Closed',
        'is_active' => true,
    ]);
$tyreLayout = OwnerTyreLayout::query()->where('owner_user_id', $owner->id)->where('name', 'E2E 6-Tyre Standard')->first()
    ?? $tyres->create($owner, $ofCo, [
        'name' => 'E2E 6-Tyre Standard',
        'spare_tyre_count' => 1,
        'axles' => [
            ['sequence' => 1, 'configuration' => TyreAxleConfiguration::Single->value],
            ['sequence' => 2, 'configuration' => TyreAxleConfiguration::Dual->value],
        ],
    ], 'E2E layout');
$goodsType = OwnerCatalogueItem::query()->where('owner_user_id', $owner->id)->where('catalogue_type', OwnerCatalogueType::GoodsType->value)->where('name', 'E2E Steel Coils')->first()
    ?? OwnerCatalogueItem::query()->create(['id' => (string) Str::uuid(), 'owner_user_id' => $owner->id, 'catalogue_type' => OwnerCatalogueType::GoodsType->value, 'name' => 'E2E Steel Coils', 'is_active' => true]);
$pkgType = OwnerCatalogueItem::query()->where('owner_user_id', $owner->id)->where('catalogue_type', OwnerCatalogueType::PackageType->value)->where('name', 'E2E Pallet')->first()
    ?? OwnerCatalogueItem::query()->create(['id' => (string) Str::uuid(), 'owner_user_id' => $owner->id, 'catalogue_type' => OwnerCatalogueType::PackageType->value, 'name' => 'E2E Pallet', 'is_active' => true]);

$stationA = Station::query()->where('owner_user_id', $owner->id)->where('name', 'E2E Pune Hub')->first()
    ?? Station::query()->create(['id' => (string) Str::uuid(), 'owner_user_id' => $owner->id, 'name' => 'E2E Pune Hub', 'is_active' => true]);
$stationB = Station::query()->where('owner_user_id', $owner->id)->where('name', 'E2E Nashik Yard')->first()
    ?? Station::query()->create(['id' => (string) Str::uuid(), 'owner_user_id' => $owner->id, 'name' => 'E2E Nashik Yard', 'is_active' => true]);

$makeDriver = function (string $name, string $lic) use ($parties, $owner, $ofCo): Party {
    return Party::query()->where('company_id', $ofCo->id)->where('name', $name)->first()
        ?? $parties->create($owner, $ofCo, null, [
            'name' => $name,
            'contact_number' => '98' . str_pad(substr((string) abs(crc32($name)), 0, 8), 8, '0', STR_PAD_LEFT),
            'state' => 'MH',
            'city' => 'Pune',
            'is_gst_applicable' => false,
        ], [[
            'role' => PartyRoleType::Driver->value,
            'attributes' => [
                'driver_type' => 'permanent',
                'license_number' => $lic,
                'license_expiry' => CarbonImmutable::now()->addYear()->toDateString(),
            ],
        ]], 'E2E driver ' . $name);
};

$drvAlpha = $makeDriver('E2E Driver Alpha', 'MH12E2E0001');
$drvBeta = $makeDriver('E2E Driver Beta', 'MH12E2E0002');
$drvGamma = $makeDriver('E2E Driver Gamma', 'MH12E2E0003');
$drvSub = $makeDriver('E2E Driver Sub', 'MH12E2E0004');
$customer = Party::query()->where('company_id', $ofCo->id)->where('name', 'E2E Billing Customer')->first()
    ?? $parties->create($owner, $ofCo, null, [
        'name' => 'E2E Billing Customer',
        'contact_number' => '9876543210',
        'state' => 'MH',
        'city' => 'Pune',
        'is_gst_applicable' => false,
    ], [
        ['role' => PartyRoleType::Customer->value, 'attributes' => []],
        ['role' => PartyRoleType::Consignor->value, 'attributes' => []],
        ['role' => PartyRoleType::Consignee->value, 'attributes' => []],
    ], 'E2E customer');

if (!Vehicle::query()->where('company_id', $ofCo->id)->where('registration_number', 'MH12OF0001')->exists()) {
    $vehicles->create($owner, $ofCo, null, [
        'registration_number' => 'MH12OF0001',
        'ownership' => VehicleOwnership::Own->value,
        'truck_type_id' => $truckType->id,
        'tyre_layout_id' => $tyreLayout->id,
        'is_active' => true,
    ], 'E2E Truck 1 unassigned', $ofBr);
}

if (!Vehicle::query()->where('company_id', $ofCo->id)->where('registration_number', 'MH12OF0002')->exists()) {
    $vehicles->create($owner, $ofCo, null, [
        'registration_number' => 'MH12OF0002',
        'ownership' => VehicleOwnership::Own->value,
        'truck_type_id' => $truckType->id,
        'tyre_layout_id' => $tyreLayout->id,
        'primary_driver_party_id' => $drvAlpha->id,
        'is_active' => true,
    ], 'E2E Truck 2 with Alpha', $ofBr);
}

$makeExpiredOrGrace = function (string $name, bool $isGrace) use ($makeCompany, $owner, $parties, $vehicles, $lrs, $truckType, $tyreLayout, $goodsType, $pkgType, $stationA, $stationB): void {
    [$co, $br] = $makeCompany($owner, $name, ['ownfleet']);
    TenantContextHolder::set(new TenantContext($co->id, $br->id, (string) $owner->id));
    $cust = Party::query()->where('company_id', $co->id)->where('name', $name . ' Cust')->first()
        ?? $parties->create($owner, $co, null, ['name' => $name . ' Cust', 'contact_number' => '9800001111', 'state' => 'MH', 'city' => 'Pune', 'is_gst_applicable' => false], [['role' => PartyRoleType::Customer->value, 'attributes' => []]], 'cust');
    $reg = $isGrace ? 'MH12GRC0001' : 'MH12EXP0001';
    $veh = Vehicle::query()->where('company_id', $co->id)->where('registration_number', $reg)->first()
        ?? $vehicles->create($owner, $co, null, ['registration_number' => $reg, 'ownership' => VehicleOwnership::Own->value, 'truck_type_id' => $truckType->id, 'tyre_layout_id' => $tyreLayout->id, 'is_active' => true], 'hist veh', $br);
    if (!\\App\\Models\\LorryReceipt::query()->where('company_id', $co->id)->exists()) {
        $lrs->create($co, $br, $owner, [
            'workflow_context' => 'own_fleet',
            'vehicle_id' => $veh->id,
            'billing_customer_party_id' => $cust->id,
            'number_mode' => 'auto',
            'lr_date' => CarbonImmutable::now()->toDateString(),
            'from_station_id' => $stationA->id,
            'to_station_id' => $stationB->id,
            'goods' => [['goods_type_id' => $goodsType->id, 'package_type_id' => $pkgType->id, 'goods_description' => 'Historical Protected Freight', 'package_count' => 1, 'actual_weight' => 1000, 'charged_weight' => 1000, 'freight_rate_type' => FreightRateType::FixedAmount->value, 'freight_rate' => 5000]],
        ]);
    }
    $sub = CompanySubscription::query()->where('company_id', $co->id)->firstOrFail();
    $sub->forceFill([
        'status' => 'active',
        'paid_through_date' => CarbonImmutable::now()->subDays($isGrace ? 2 : 30)->toDateString(),
        'common_renewal_date' => CarbonImmutable::now()->subDays($isGrace ? 2 : 30)->toDateString(),
        'grace_ends_on' => CarbonImmutable::now()->addDays($isGrace ? 5 : -10)->toDateString(),
    ])->save();
    CompanyCapabilityResolver::invalidate($co);
};

$makeExpiredOrGrace('${COMPANIES.expiredSub}', false);
$makeExpiredOrGrace('${COMPANIES.graceSub}', true);
} else {
    $brokerCo = Company::query()->where('name', '${COMPANIES.brokerOnly}')->first();
    if ($brokerCo !== null) {
        $nonBrokerIds = CompanySubscription::query()
            ->where('company_id', $brokerCo->id)
            ->whereHas('items', fn ($q) => $q->where('module', '!=', 'broker'))
            ->pluck('id');
        if ($nonBrokerIds->isNotEmpty()) {
            \\App\\Models\\CompanySubscriptionItem::query()->whereIn('company_subscription_id', $nonBrokerIds)->delete();
            \\App\\Models\\CompanySubscriptionSnapshot::query()->whereIn('company_subscription_id', $nonBrokerIds)->delete();
            CompanySubscription::query()->whereIn('id', $nonBrokerIds)->forceDelete();
        }
        CompanyCapabilityResolver::invalidate($brokerCo);
    }
    $ofCo = Company::query()->where('name', '${COMPANIES.ownFleetOnly}')->first();
    if ($ofCo !== null) {
        $truck1 = Vehicle::query()->where('company_id', $ofCo->id)->where('registration_number', 'MH12OF0001')->first();
        if ($truck1 !== null) {
            \\App\\Models\\VehicleDriverAssignment::query()->where('vehicle_id', $truck1->id)->forceDelete();
            \\App\\Models\\VehicleDriverAttendance::query()->where('vehicle_id', $truck1->id)->forceDelete();
            $truck1->forceFill(['primary_driver_party_id' => null])->save();
        }
        $cust = Party::query()->where('company_id', $ofCo->id)->where('name', 'E2E Billing Customer')->first();
        if ($cust !== null) {
            foreach ([PartyRoleType::Consignor->value, PartyRoleType::Consignee->value] as $r) {
                if (!PartyRole::query()->where('party_id', $cust->id)->where('role', $r)->whereNull('deleted_at')->exists()) {
                    PartyRole::query()->create(['id' => (string) Str::uuid(), 'company_id' => $ofCo->id, 'party_id' => $cust->id, 'role' => $r, 'attributes' => []]);
                }
            }
        }
    }
}
echo 'SEEDED_OK';
`;
    runPhpBootstrap(php);
}

function activateOwnFleetOnBrokerOnly(): void {
    const php = `
use App\\Domains\\Subscription\\Contract\\CompanySubscriptionContractService;
use App\\Domains\\Subscription\\Entitlement\\CompanyCapabilityResolver;
use App\\Domains\\Subscription\\Enums\\SubscriptionTenure;
use App\\Domains\\Subscription\\Lifecycle\\SubscriptionLifecycleService;
use App\\Models\\Company;
use App\\Models\\SubscriptionCatalogueVersion;
use App\\Models\\SubscriptionPlan;
use App\\Models\\User;
use Carbon\\CarbonImmutable;
use Illuminate\\Support\\Facades\\Auth;

$owner = User::query()->where('email', '${E2E_OWNER_EMAIL}')->firstOrFail();
$company = Company::query()->where('name', '${COMPANIES.brokerOnly}')->firstOrFail();
Auth::login($owner);
$catalogue = SubscriptionCatalogueVersion::query()->where('is_active', true)->firstOrFail();
$ownPlan = SubscriptionPlan::query()
    ->where('catalogue_version_id', $catalogue->id)
    ->where('module', 'ownfleet')
    ->where('is_active', true)
    ->orderBy('sort_order')
    ->firstOrFail();
$renewal = CarbonImmutable::now()->addYear();
$draft = app(CompanySubscriptionContractService::class)->createDraftContract(
    actor: $owner,
    company: $company,
    catalogue: $catalogue,
    planIds: [$ownPlan->id],
    addonIds: [],
    tenure: SubscriptionTenure::Yearly,
    commonRenewalDate: $renewal,
);
app(SubscriptionLifecycleService::class)->activateAfterVerifiedPayment($draft, $renewal, $owner, 'E2E activate ownfleet upgrade');
CompanyCapabilityResolver::invalidate($company);
echo 'ACTIVATED_OK';
`;
    runPhpBootstrap(php);
}

async function enterCompanyBranch(
    page: Page,
    companyName: string,
): Promise<void> {
    await page.goto('/management/branches', { waitUntil: 'networkidle' });
    await expect(page.getByRole('heading', { name: 'Branches' })).toBeVisible({
        timeout: 20_000,
    });
    await page.locator('#company').selectOption({ label: companyName });
    await page.getByRole('button', { name: /apply/i }).click();

    const targetRow = page
        .locator('table tbody tr')
        .filter({ hasText: companyName })
        .first();
    await expect(targetRow).toBeVisible({ timeout: 20_000 });
    await targetRow.getByRole('button', { name: 'Enter Branch Panel' }).click();
    await expect(page).toHaveURL(/\/operations\/?$/, { timeout: 20_000 });
}

async function selectComboboxItem(
    page: Page,
    fieldId: string,
    textMatch: string,
): Promise<void> {
    await page.locator(`#${fieldId}-trigger`).click();
    const option = page
        .getByRole('listbox')
        .getByRole('option')
        .filter({ hasText: textMatch })
        .first();
    await expect(option).toBeVisible({ timeout: 10_000 });
    await option.click();
}

test.describe('Mandatory indicators, Vehicle Drivers, and Subscription Entitlements', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test.beforeAll(() => {
        if (process.env.E2E_REQUIRE_DEMO === '1') {
            seedIsolatedEntitlementCompanies();
        }
    });

    test('multi-company entitlements, vehicle ownership enforcement, mandatory labels, and non-owner upgrade gating', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await loginAs(page, E2E_OWNER_EMAIL, E2E_PASSWORD);

        // 1. Broker-only company
        await enterCompanyBranch(page, COMPANIES.brokerOnly);
        const sidebar = page.locator('[data-sidebar="sidebar"]').first();
        await expect(sidebar.getByText('Lorry Hires')).toBeVisible();
        await expect(sidebar.getByText('Transporter Balances')).toBeVisible();
        await expect(sidebar.getByText('Vehicle Drivers')).toHaveCount(0);
        await expect(sidebar.getByText('Partload Challans')).toHaveCount(0);
        await expect(sidebar.getByText('Tyre Layouts')).toHaveCount(0);

        // Vehicle Create locked to Market + mandatory indicators + Quick Add
        await page.goto('/vehicles/create', { waitUntil: 'networkidle' });
        await expect(page.locator('#ownership')).toHaveText('Market');
        await expect(page.locator('select#ownership')).toHaveCount(0);
        await expect(
            page.locator('label[for="registration_number"]'),
        ).toHaveAttribute('data-required', 'true');
        await expect(
            page.locator('label[for="owner_party_id"]'),
        ).toHaveAttribute('data-required', 'true');
        await expect(
            page.getByRole('button', { name: 'Add', exact: true }).first(),
        ).toBeVisible();

        // Forged Own vehicle POST rejected (403)
        const forgedStatus = await page.evaluate(async () => {
            const token =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '';
            const res = await fetch('/vehicles', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-XSRF-TOKEN': token,
                },
                body: JSON.stringify({
                    registration_number: 'MH12FORGED99',
                    ownership: 'own',
                }),
            });
            return res.status;
        });
        expect(forgedStatus).toBe(403);

        // Direct OwnFleet URL as Owner shows business message + Manage Subscription link
        await page.goto('/vehicle-drivers');
        await expect(
            page.getByText(/OwnFleet is not included in this Company/i),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Manage Subscription' }),
        ).toBeVisible();

        // 2. Partload-only company
        await enterCompanyBranch(page, COMPANIES.partloadOnly);
        await expect(sidebar.getByText('Partload Challans')).toBeVisible();
        await expect(sidebar.getByText('Pending Loading')).toBeVisible();
        await expect(sidebar.getByText('Lorry Hires')).toHaveCount(0);
        await expect(sidebar.getByText('Vehicle Drivers')).toHaveCount(0);

        // 3. Broker + OwnFleet company -> dynamic conditional mandatory indicators on Vehicle form
        await enterCompanyBranch(page, COMPANIES.brokerOwnFleet);
        await expect(sidebar.getByText('Lorry Hires')).toBeVisible();
        await expect(sidebar.getByText('Vehicle Drivers')).toBeVisible();
        await expect(sidebar.getByText('Partload Challans')).toHaveCount(0);

        await page.goto('/vehicles/create', { waitUntil: 'networkidle' });
        await page.locator('select#ownership').selectOption('own');
        await expect(
            page.locator('label[for="truck_type_id"]'),
        ).toHaveAttribute('data-required', 'true');
        await expect(
            page.locator('label[for="tyre_layout_id"]'),
        ).toHaveAttribute('data-required', 'true');
        await page.locator('select#ownership').selectOption('market');
        await expect(
            page.locator('label[for="owner_party_id"]'),
        ).toHaveAttribute('data-required', 'true');

        // 4. All Modules company
        await enterCompanyBranch(page, COMPANIES.allModules);
        await expect(sidebar.getByText('Lorry Hires')).toBeVisible();
        await expect(sidebar.getByText('Vehicle Drivers')).toBeVisible();
        await expect(sidebar.getByText('Partload Challans')).toBeVisible();

        // 5. Non-Owner user does not see Owner-only subscription upgrade link
        await page.context().clearCookies();
        await loginAs(page, E2E_MEMBER_EMAIL, E2E_PASSWORD);
        await page.goto('/vehicle-drivers');
        await expect(
            page.getByText(/OwnFleet is not included in this Company/i),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Manage Subscription' }),
        ).toHaveCount(0);
    });

    test('OwnFleet Vehicle Driver assignment, replacement history, attendance, conditional Party fields, and OwnFleet Bilty substitute driver', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await loginAs(page, E2E_OWNER_EMAIL, E2E_PASSWORD);
        await enterCompanyBranch(page, COMPANIES.ownFleetOnly);

        const sidebar = page.locator('[data-sidebar="sidebar"]').first();
        await expect(sidebar.getByText('Vehicle Drivers')).toBeVisible();
        await expect(sidebar.getByText('Tyre Layouts')).toBeVisible();
        await expect(sidebar.getByText('Lorry Hires')).toHaveCount(0);
        await expect(sidebar.getByText('Partload Challans')).toHaveCount(0);

        // Conditional Party Master mandatory indicators (Driver toggle)
        await page.goto('/parties/create', { waitUntil: 'networkidle' });
        const driverCheckbox = page.getByRole('checkbox', { name: 'Driver' });
        await driverCheckbox.check();
        await expect(
            page.locator('label[for$="license_number"]'),
        ).toHaveAttribute('data-required', 'true');
        await expect(
            page.locator('label[for$="contact_number"]'),
        ).toHaveAttribute('data-required', 'true');

        // Vehicle Create locked to Own
        await page.goto('/vehicles/create', { waitUntil: 'networkidle' });
        await expect(page.locator('#ownership')).toHaveText('Own');

        // Vehicle Drivers Register
        await page.goto('/vehicle-drivers', { waitUntil: 'networkidle' });
        await expect(
            page.getByRole('heading', { name: 'Vehicle Drivers' }),
        ).toBeVisible();

        const truck1Row = page
            .locator('table tbody tr')
            .filter({ hasText: 'MH12OF0001' })
            .first();
        await expect(truck1Row).toContainText('Unassigned');
        await truck1Row.getByRole('button', { name: 'Assign' }).click();

        await selectComboboxItem(
            page,
            'assign_driver_party_id',
            'E2E Driver Beta',
        );
        await expect(
            page.locator('label[for="assign_from_date"]'),
        ).toHaveAttribute('data-required', 'true');
        await page.getByRole('button', { name: 'Save Assignment' }).click();

        await expect(
            page
                .locator('table tbody tr')
                .filter({ hasText: 'MH12OF0001' })
                .first(),
        ).toContainText('E2E Driver Beta');

        // Replace Driver (Change) -> preserves history
        await page
            .locator('table tbody tr')
            .filter({ hasText: 'MH12OF0001' })
            .first()
            .getByRole('button', { name: 'Change' })
            .click();
        await selectComboboxItem(
            page,
            'assign_driver_party_id',
            'E2E Driver Gamma',
        );
        await page.getByRole('button', { name: 'Save Assignment' }).click();

        await expect(
            page
                .locator('table tbody tr')
                .filter({ hasText: 'MH12OF0001' })
                .first(),
        ).toContainText('E2E Driver Gamma');

        // History link shows both old and new assignments
        await page
            .locator('table tbody tr')
            .filter({ hasText: 'MH12OF0001' })
            .first()
            .getByRole('link', { name: /History/i })
            .click();
        await expect(page).toHaveURL(/\/vehicle-drivers\/[0-9a-f-]+\/history$/);
        await expect(page.getByText('E2E Driver Beta').first()).toBeVisible();
        await expect(page.getByText('E2E Driver Gamma').first()).toBeVisible();

        // Vehicle Master detail shows Primary Driver
        await page.getByRole('link', { name: /Vehicle Detail/i }).click();
        await expect(page).toHaveURL(/\/vehicles\/[0-9a-f-]+$/);
        await expect(
            page.getByText('Primary Driver Assignment', { exact: true }),
        ).toBeVisible();
        await expect(page.getByText('E2E Driver Gamma').first()).toBeVisible();

        // Attendance mark Absent + Substitute, then Reset to Present
        await page.goto('/vehicle-drivers', { waitUntil: 'networkidle' });
        const attRow = page
            .locator('table')
            .nth(1)
            .locator('tbody tr')
            .filter({ hasText: 'MH12OF0001' })
            .first();
        await attRow.getByRole('button', { name: 'Mark Exception' }).click();
        await selectComboboxItem(
            page,
            'att_substitute_driver',
            'E2E Driver Sub',
        );
        await page.getByRole('button', { name: 'Save Attendance' }).click();
        await expect(attRow).toContainText('Absent · Substitute');
        await expect(attRow).toContainText('E2E Driver Sub');

        await attRow.getByRole('button', { name: 'Reset to Present' }).click();
        await expect(attRow).toContainText('Present (Assigned)');

        // OwnFleet Bilty: select MH12OF0001 -> auto-displays E2E Driver Gamma, toggle substitute -> creates Trip with E2E Driver Sub while keeping permanent driver intact
        await page.goto('/lorry-receipts/create', { waitUntil: 'networkidle' });
        await selectComboboxItem(page, 'vehicle_id', 'MH12OF0001');
        await expect(page.getByText('E2E Driver Gamma').first()).toBeVisible();

        const subToggle = page.getByLabel(
            /Use substitute driver for this Trip/i,
        );
        await subToggle.check();
        await expect(
            page.locator('label[for="substitute_driver_party_id"]'),
        ).toHaveAttribute('data-required', 'true');
        await selectComboboxItem(
            page,
            'substitute_driver_party_id',
            'E2E Driver Sub',
        );

        await selectComboboxItem(
            page,
            'billing_customer_party_id',
            'E2E Billing Customer',
        );
        if (
            await page
                .locator('#consignor_party_id-trigger')
                .isVisible()
                .catch(() => false)
        ) {
            await selectComboboxItem(
                page,
                'consignor_party_id',
                'E2E Billing Customer',
            );
        }
        if (
            await page
                .locator('#consignee_party_id-trigger')
                .isVisible()
                .catch(() => false)
        ) {
            await selectComboboxItem(
                page,
                'consignee_party_id',
                'E2E Billing Customer',
            );
        }
        const fromVal = await page
            .locator('#from_station_id option[value]:not([value=""])')
            .first()
            .getAttribute('value');
        const toVal = await page
            .locator('#to_station_id option[value]:not([value=""])')
            .nth(1)
            .getAttribute('value');
        await page.locator('#from_station_id').selectOption(fromVal!);
        await page.locator('#to_station_id').selectOption(toVal!);
        await page
            .locator('#lr_date')
            .fill(new Date().toISOString().slice(0, 10));

        const goodsVal = await page
            .locator(
                'select[name="goods[0][goods_type_id]"] option[value]:not([value=""])',
            )
            .first()
            .getAttribute('value');
        const pkgVal = await page
            .locator(
                'select[name="goods[0][package_type_id]"] option[value]:not([value=""])',
            )
            .first()
            .getAttribute('value');
        await page
            .locator('select[name="goods[0][goods_type_id]"]')
            .selectOption(goodsVal!);
        await page
            .locator('select[name="goods[0][package_type_id]"]')
            .selectOption(pkgVal!);
        await page
            .locator('input[name="goods[0][goods_description]"]')
            .fill('E2E Driver Substitute Freight');
        await page.locator('input[name="goods[0][package_count]"]').fill('1');
        await page.getByLabel('Actual weight MT row 1').fill('1');
        await page.getByLabel('Charged weight MT row 1').fill('1');
        await page
            .locator('input[name="goods[0][freight_rate]"]')
            .fill('10000');

        await page
            .getByRole('button', { name: 'Save Bilty', exact: true })
            .click();
        const confirmBtn = page
            .getByRole('dialog')
            .getByRole('button', { name: 'Save Bilty', exact: true });
        await expect(confirmBtn).toBeVisible({ timeout: 10_000 });
        await confirmBtn.click();
        await expect(page).toHaveURL(/\/lorry-receipts\/[0-9a-f-]+$/, {
            timeout: 30_000,
        });
        await expect(
            page.getByText(/E2E Driver Sub.*Substitute/i).first(),
        ).toBeVisible();

        // Confirm permanent Primary Driver on MH12OF0001 stayed E2E Driver Gamma
        await page.goto('/vehicle-drivers', { waitUntil: 'networkidle' });
        await expect(
            page
                .locator('table tbody tr')
                .filter({ hasText: 'MH12OF0001' })
                .first(),
        ).toContainText('E2E Driver Gamma');
    });

    test('trial, expired historical preservation, grace period, and immediate activation', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        // 1. Active Trial company
        await loginAs(page, E2E_TRIAL_OWNER_EMAIL, E2E_PASSWORD);
        await enterCompanyBranch(page, COMPANIES.activeTrial);
        const sidebar = page.locator('[data-sidebar="sidebar"]').first();
        await expect(sidebar.getByText('Bilty Register')).toBeVisible();

        // 2. Expired & Grace companies preserve historical read access and block new transactions
        await page.context().clearCookies();
        await loginAs(page, E2E_OWNER_EMAIL, E2E_PASSWORD);
        for (const companyName of [COMPANIES.expiredSub, COMPANIES.graceSub]) {
            await enterCompanyBranch(page, companyName);
            await page.goto('/lorry-receipts', { waitUntil: 'networkidle' });
            await expect(
                page.getByRole('heading', { name: 'Bilty Register' }),
            ).toBeVisible();
            await expect(
                page.getByText(`${companyName} Cust`).first(),
            ).toBeVisible();
            await page
                .getByRole('link', { name: /View Bilty/i })
                .first()
                .click();
            await expect(
                page.getByText('Historical Protected Freight').first(),
            ).toBeVisible();

            await page.goto('/lorry-receipts/create');
            await expect(
                page.getByRole('heading', {
                    name: 'Cannot create Lorry Receipt',
                }),
            ).toBeVisible();
            await expect(
                page.getByText(/Renew the subscription to continue/i),
            ).toBeVisible();

            await page.goto('/vehicles/create');
            await expect(
                page.getByRole('heading', {
                    name: 'Subscription Renewal Required',
                }),
            ).toBeVisible();
        }

        // 3. Immediate activation of OwnFleet on Broker-only company updates nav & vehicle ownership immediately
        await enterCompanyBranch(page, COMPANIES.brokerOnly);
        await expect(sidebar.getByText('Vehicle Drivers')).toHaveCount(0);
        activateOwnFleetOnBrokerOnly();
        await page.reload({ waitUntil: 'networkidle' });
        await expect(sidebar.getByText('Vehicle Drivers')).toBeVisible();
        await page.goto('/vehicles/create', { waitUntil: 'networkidle' });
        await expect(
            page.locator('select#ownership option[value="own"]'),
        ).toHaveCount(1);
    });
});
