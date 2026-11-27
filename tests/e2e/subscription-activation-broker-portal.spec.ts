import { execFileSync } from 'node:child_process';
import { expect, type Page, test } from '@playwright/test';
import { loginAs } from './fixtures/auth';

const E2E_OWNER_EMAIL = 'e2e.sub.owner@truckbill.test';
const E2E_RESTRICTED_EMAIL = 'e2e.sub.restricted@truckbill.test';
const E2E_OPERATOR_EMAIL = 'e2e.sub.operator@truckbill.test';
const E2E_PASSWORD = 'e2e-password-123';

const COMPANIES = {
    anchor: 'E2E Sub Anchor Co',
    brokerPurchase: 'E2E Broker Purchase Co',
    ownFleet: 'E2E OwnFleet Active Co',
    partload: 'E2E Partload Active Co',
    accounting: 'E2E Accounting Active Co',
} as const;

function runPhp(code: string): string {
    if (
        process.env.DB_DATABASE !== 'nexus_test' &&
        process.env.APP_ENV !== 'testing'
    ) {
        throw new Error(
            'Refusing E2E fixture setup outside nexus_test / testing.',
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
            `Tinker failed:\nSTDOUT:\n${err.stdout ?? ''}\nSTDERR:\n${err.stderr ?? ''}`,
        );
    }
}

function seedIsolatedFixtures(): string {
    if (
        process.env.DB_DATABASE !== 'nexus_test' ||
        process.env.APP_ENV !== 'testing'
    ) {
        throw new Error(
            'Refusing E2E migrate:fresh outside nexus_test / testing.',
        );
    }

    execFileSync(
        'php',
        ['artisan', 'migrate:fresh', '--force', '--no-interaction'],
        {
            cwd: process.cwd(),
            env: {
                ...process.env,
                CACHE_STORE: process.env.CACHE_STORE ?? 'file',
            },
            encoding: 'utf8',
            timeout: 120_000,
            stdio: ['ignore', 'pipe', 'pipe'],
        },
    );

    const php = `
use App\\Domains\\Company\\CompanyWorkspaceService;
use App\\Domains\\Master\\Catalogue\\Enums\\OwnerCatalogueType;
use App\\Domains\\Master\\Party\\Enums\\PartyRoleType;
use App\\Domains\\Master\\Party\\PartyService;
use App\\Domains\\Master\\Vehicle\\Enums\\VehicleOwnership;
use App\\Domains\\Master\\Vehicle\\VehicleService;
use App\\Domains\\Rbac\\Enums\\PermissionDomain;
use App\\Domains\\Rbac\\PermissionCodes;
use App\\Domains\\Rbac\\PermissionMasterService;
use App\\Domains\\Rbac\\RoleAssignmentService;
use App\\Domains\\Subscription\\Contract\\CompanySubscriptionContractService;
use App\\Domains\\Subscription\\Entitlement\\CompanyCapabilityResolver;
use App\\Domains\\Subscription\\Enums\\SubscriptionTenure;
use App\\Domains\\Subscription\\Lifecycle\\SubscriptionLifecycleService;
use App\\Models\\BankCashAccount;
use App\\Models\\City;
use App\\Models\\Company;
use App\\Models\\GstRate;
use App\\Models\\OwnerCatalogueItem;
use App\\Models\\PlatformBillingProfile;
use App\\Models\\Role;
use App\\Models\\State;
use App\\Models\\SubscriptionAddon;
use App\\Models\\SubscriptionCatalogueVersion;
use App\\Models\\SubscriptionPlan;
use App\\Models\\User;
use App\\Support\\Tenant\\TenantContext;
use App\\Support\\Tenant\\TenantContextHolder;
use Carbon\\CarbonImmutable;
use Database\\Seeders\\IndiaGeographySeeder;
use Database\\Seeders\\RbacSeeder;
use Database\\Seeders\\SubscriptionCatalogueSeeder;
use Database\\Seeders\\TdsReferenceRulesSeeder;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Str;

Cache::flush();
(new RbacSeeder)->run();
(new SubscriptionCatalogueSeeder)->run();
(new TdsReferenceRulesSeeder)->run();
if (State::query()->where('country_code', 'IN')->count() < 36) {
    (new IndiaGeographySeeder)->run();
}

$mh = State::query()->where('country_code', 'IN')->where('name', 'Maharashtra')->firstOrFail();
$pune = City::query()->firstOrCreate(
    ['state_id' => $mh->id, 'name_normalized' => 'pune'],
    ['id' => (string) Str::uuid(), 'name' => 'Pune', 'is_active' => true],
);
$gst18 = GstRate::query()->firstOrCreate(
    ['rate_percent' => '18.000', 'version' => 1],
    ['id' => (string) Str::uuid(), 'effective_from' => '2025-04-01', 'is_active' => true],
);

PlatformBillingProfile::query()->create([
    'id' => (string) Str::uuid(),
    'legal_name' => 'TruckBill E2E Billing Pvt Ltd',
    'pan' => 'AAACT1234A',
    'contact_number' => '9876543210',
    'email' => 'billing-e2e@truckbill.test',
    'registered_address' => '100 Trade Tower',
    'city_id' => $pune->id,
    'city' => 'Pune',
    'state_id' => $mh->id,
    'state_code' => '27',
    'state_name' => 'Maharashtra',
    'pin_code' => '411001',
    'gstin' => '27AAACT1234A1Z5',
    'gst_rate_id' => $gst18->id,
    'gst_rate_percent' => $gst18->rate_percent,
    'is_active' => true,
]);

$pw = Hash::make('${E2E_PASSWORD}');
$owner = User::factory()->customer()->create([
    'name' => 'E2E Subscription Owner',
    'email' => '${E2E_OWNER_EMAIL}',
    'password' => $pw,
]);

$workspace = app(CompanyWorkspaceService::class);
$catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
$lifecycle = new SubscriptionLifecycleService;

// 1st company consumes trial; 2nd+ companies have NO trial.
$workspace->createCompany($owner, '${COMPANIES.anchor}');

$brokerCo = $workspace->createCompany($owner, '${COMPANIES.brokerPurchase}');
$brokerCo->forceFill([
    'state_id' => $mh->id,
    'city_id' => $pune->id,
    'state' => 'Maharashtra',
    'city' => 'Pune',
    'gstin' => '27AABCE1111E1Z5',
    'pan' => 'AABCE1111E',
])->save();
$brokerBr = $brokerCo->branches()->firstOrFail();

// Seed Broker company operational masters (Party, Transporter, Market Vehicle, Bank/Cash, Goods, Package)
TenantContextHolder::set(new TenantContext($brokerCo->id, $brokerBr->id, (string) $owner->id));
$parties = app(PartyService::class);
$customer = $parties->create(
    actor: $owner,
    company: $brokerCo,
    branch: null,
    attributes: [
        'name' => 'E2E Broker Customer',
        'contact_number' => '9822000001',
        'state_id' => $mh->id,
        'city_id' => $pune->id,
        'pan' => 'AAACC1234A',
        'gstin' => '27AAACC1234A1Z5',
        'is_gst_applicable' => true,
    ],
    roles: [
        ['role' => PartyRoleType::Customer->value],
        ['role' => PartyRoleType::Consignor->value],
        ['role' => PartyRoleType::Consignee->value],
    ],
    remark: 'E2E Broker Customer',
);

$transporter = $parties->create(
    actor: $owner,
    company: $brokerCo,
    branch: null,
    attributes: [
        'name' => 'E2E Transporter One',
        'contact_number' => '9822000002',
        'state_id' => $mh->id,
        'city_id' => $pune->id,
        'pan' => 'AAACT2345B',
        'is_gst_applicable' => false,
    ],
    roles: [[
        'role' => PartyRoleType::Transporter->value,
        'attributes' => [
            'pan_status' => 'valid',
            'declaration' => true,
            'declaration_type' => 'owner',
            'goods_vehicles_count' => 2,
        ],
    ]],
    remark: 'E2E Transporter',
);

// Temporarily insert Market vehicle directly as master fixture before Broker purchase (will operate once Broker activates)
\\App\\Models\\Vehicle::query()->create([
    'id' => (string) Str::uuid(),
    'company_id' => $brokerCo->id,
    'branch_id' => null,
    'registration_number' => 'MH12BR0101',
    'ownership' => VehicleOwnership::Market->value,
    'owner_party_id' => $transporter->id,
    'is_active' => true,
]);

BankCashAccount::query()->create([
    'id' => (string) Str::uuid(),
    'company_id' => $brokerCo->id,
    'branch_id' => $brokerBr->id,
    'name' => 'E2E Operating Bank',
    'account_kind' => 'bank',
    'bank_name' => 'HDFC Bank',
    'is_active' => true,
]);

OwnerCatalogueItem::factory()->create([
    'owner_user_id' => $owner->id,
    'catalogue_type' => OwnerCatalogueType::GoodsType->value,
    'name' => 'Auto Parts',
    'code' => 'AUTO',
    'is_active' => true,
]);
OwnerCatalogueItem::factory()->create([
    'owner_user_id' => $owner->id,
    'catalogue_type' => OwnerCatalogueType::PackageType->value,
    'name' => 'Box',
    'code' => 'BOX',
    'is_active' => true,
]);

// Create restricted viewer user & authorized Broker operator user on E2E Broker Purchase Co
$restrictedUser = User::factory()->customer()->create([
    'name' => 'E2E Restricted Viewer',
    'email' => '${E2E_RESTRICTED_EMAIL}',
    'password' => $pw,
]);

$operatorUser = User::factory()->customer()->create([
    'name' => 'E2E Broker Operator',
    'email' => '${E2E_OPERATOR_EMAIL}',
    'password' => $pw,
]);

$rbac = app(RoleAssignmentService::class);
$viewerRole = Role::query()->where('domain', PermissionDomain::Customer->value)->where('slug', 'customer.viewer')->firstOrFail();
$rMem = $workspace->addMembership($brokerCo, $restrictedUser, 'Viewer join');
$rBr = $workspace->assignBranch($rMem, $brokerBr, 'Viewer HO');
$rbac->assignCustomerBranchRole($owner, $rMem, $brokerBr, $rBr, $viewerRole, 'Viewer role');

$sa = User::factory()->internal()->superAdmin()->create(['password' => $pw]);
$master = app(PermissionMasterService::class);
$customOpRole = $master->createRole($sa, [
    'domain' => PermissionDomain::Customer,
    'name' => 'E2E Broker Operator',
    'slug' => 'e2e.broker_op',
    'description' => 'E2E Broker Operator',
]);
foreach ([
    PermissionCodes::PLATFORM_VIEW,
    PermissionCodes::PLATFORM_CREATE,
    PermissionCodes::LR_VIEW,
    PermissionCodes::LR_CREATE,
    PermissionCodes::BROKER_LORRY_HIRE_VIEW,
    PermissionCodes::BROKER_VOUCHER_VIEW,
    PermissionCodes::BROKER_VOUCHER_CREATE,
] as $code) {
    $perm = \\App\\Models\\Permission::query()->where('code', $code)->firstOrFail();
    $master->assignPermissionToRole($sa, $customOpRole, $perm, "Grant {$code}");
}
$oMem = $workspace->addMembership($brokerCo, $operatorUser, 'Op join');
$oBr = $workspace->assignBranch($oMem, $brokerBr, 'Op HO');
$rbac->assignCustomerBranchRole($owner, $oMem, $brokerBr, $oBr, $customOpRole, 'Broker operator role');

$activateModuleCo = static function (string $name, array $modules, array $addonCodes = []) use ($workspace, $owner, $catalogue, $lifecycle): void {
    $co = $workspace->createCompany($owner, $name);
    $planIds = [];
    foreach ($modules as $mod) {
        $planIds[] = SubscriptionPlan::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('module', $mod)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->firstOrFail()->id;
    }
    $addonIds = [];
    foreach ($addonCodes as $code) {
        $addonIds[] = SubscriptionAddon::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('code', $code)
            ->where('is_active', true)
            ->firstOrFail()->id;
    }
    $renewal = CarbonImmutable::now()->addYear();
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $co,
        catalogue: $catalogue,
        planIds: $planIds,
        addonIds: $addonIds,
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: $renewal,
    );
    $lifecycle->activateAfterVerifiedPayment($draft, $renewal, $owner, 'E2E seed activation');
    CompanyCapabilityResolver::invalidate($co);
};

$activateModuleCo('${COMPANIES.ownFleet}', ['ownfleet']);
$activateModuleCo('${COMPANIES.partload}', ['partload']);
$activateModuleCo('${COMPANIES.accounting}', ['broker'], ['accounting']);

echo json_encode(['brokerCoId' => $brokerCo->id]);
`;

    const out = runPhp(php).trim();
    const match = out.match(/\{"brokerCoId":"([^"]+)"\}/);
    if (!match) {
        throw new Error(`Unexpected seed output: ${out}`);
    }
    return match[1];
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

test.describe
    .serial('Subscription Activation, Navigation & Broker Browser Journeys', () => {
    let brokerCoId = '';

    test.beforeAll(() => {
        brokerCoId = seedIsolatedFixtures();
    });

    test('owner purchases broker subscription, activates immediately, enters branch, creates bilty -> lorry hire -> voucher', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        await loginAs(page, E2E_OWNER_EMAIL, E2E_PASSWORD);
        await expect(page).toHaveURL(/\/management/, { timeout: 15_000 });

        // 1. Purchase Broker subscription & verify simulated fake payment
        await page.goto(
            `/management/companies/${brokerCoId}/subscription/purchase`,
            { waitUntil: 'networkidle' },
        );
        await page
            .locator('button')
            .filter({ hasText: /Broker/i })
            .first()
            .click();

        const reviewPay = page.getByRole('button', { name: /Review & Pay/i });
        await expect(reviewPay).toBeEnabled({ timeout: 10_000 });
        await reviewPay.click();

        await expect(page).toHaveURL(/\/subscription\/checkout\//, {
            timeout: 15_000,
        });
        await page
            .getByRole('button', { name: /Simulate Verified Capture/i })
            .click();

        await expect(page).toHaveURL(/\/subscription\/purchase/, {
            timeout: 15_000,
        });
        await expect(page.getByText(/Status:\s*active/i)).toBeVisible({
            timeout: 10_000,
        });

        // 2. Enter Broker Company Branch Panel immediately (no logout/cache clear)
        await enterCompanyBranch(page, COMPANIES.brokerPurchase);

        const sidebar = page.locator('[data-sidebar="sidebar"]').first();
        for (const label of [
            'Bilty Register',
            'Lorry Hires',
            'Transporter Voucher Payments',
            'Daily Payments',
            'Invoices',
            'Customer Invoice Receipts',
            'Delivery / Memo / POD',
            'Linked Bilties',
            'Transporter Balances',
            'Enquiries',
            'Broker Registers',
            'Party Master',
            'Vehicle Master',
            'Bank/Cash Accounts',
            'Station Master',
            'Goods Types',
            'Document Types',
            'Package Types',
        ]) {
            await expect(
                sidebar.getByText(label, { exact: true }),
            ).toBeVisible();
        }

        for (const hiddenLabel of [
            'Vehicle Drivers',
            'Partload Challans',
            'Accounting',
            'Truck Types',
            'Tyre Layouts',
            'Expense Categories',
        ]) {
            await expect(
                sidebar.getByText(hiddenLabel, { exact: true }),
            ).toHaveCount(0);
        }

        // 3. Create Broker Bilty via UI
        await sidebar.getByRole('link', { name: 'Bilty Register' }).click();
        await expect(page).toHaveURL(/\/lorry-receipts\/?$/);

        await page.goto('/lorry-receipts/create', { waitUntil: 'networkidle' });
        await selectComboboxItem(page, 'vehicle_id', 'MH12BR0101');
        const truckOwnerBtn = page.getByRole('button', {
            name: 'Truck Owner',
            exact: true,
        });
        if (await truckOwnerBtn.isVisible().catch(() => false)) {
            await truckOwnerBtn.click();
        }
        await selectComboboxItem(
            page,
            'billing_customer_party_id',
            'E2E Broker Customer',
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
                'E2E Broker Customer',
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
                'E2E Broker Customer',
            );
        }

        await page.locator('#from_text').fill('Pune');
        await page.locator('#to_text').fill('Mumbai');
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
            .fill('E2E Broker Auto Parts');
        await page.locator('input[name="goods[0][package_count]"]').fill('10');
        await page.getByLabel('Actual weight MT row 1').fill('5');
        await page.getByLabel('Charged weight MT row 1').fill('5');
        await page
            .locator('input[name="goods[0][freight_rate]"]')
            .fill('25000');

        await page
            .getByRole('button', { name: 'Save Bilty', exact: true })
            .click();
        const confirmBiltyBtn = page
            .getByRole('dialog')
            .getByRole('button', { name: 'Save Bilty', exact: true });
        await expect(confirmBiltyBtn).toBeVisible({ timeout: 10_000 });
        await confirmBiltyBtn.click();

        await expect(page).toHaveURL(/\/lorry-receipts\/[0-9a-f-]+$/, {
            timeout: 20_000,
        });
        const lrUrl = page.url();
        const lrIdMatch = lrUrl.match(/\/lorry-receipts\/([0-9a-f-]+)$/);
        expect(lrIdMatch).not.toBeNull();
        const lrId = lrIdMatch![1];

        // 4. Create Lorry Hire directly from the created Bilty
        await page.goto(`/lorry-receipts/${lrId}/create-lorry-hire`, {
            waitUntil: 'networkidle',
        });
        await page.locator('#rate_0').fill('18000');
        await page
            .getByRole('button', { name: 'Create Lorry Hire', exact: true })
            .click();

        const hireDialog = page.getByRole('dialog');
        await expect(hireDialog).toBeVisible({ timeout: 10_000 });
        await hireDialog
            .getByRole('button', { name: 'Create Lorry Hire', exact: true })
            .click();

        await expect(page).toHaveURL(/\/broker\/lorry-hires\/[0-9a-f-]+$/, {
            timeout: 20_000,
        });
        const hireIdMatch = page
            .url()
            .match(/\/broker\/lorry-hires\/([0-9a-f-]+)$/);
        expect(hireIdMatch).not.toBeNull();
        const hireId = hireIdMatch![1];

        // 5. Record Transporter Voucher Payment
        await page.goto(`/broker/vouchers/create?lorry_hire_id=${hireId}`, {
            waitUntil: 'networkidle',
        });
        const selectedHire = await page
            .locator('#lorry_hire_id-trigger')
            .innerText()
            .catch(() => '');
        if (!selectedHire || /Search hire/i.test(selectedHire)) {
            await page.locator('#lorry_hire_id-trigger').click();
            await page.getByRole('listbox').getByRole('option').first().click();
        }
        await page.locator('#payment_mode').selectOption('cash');
        await page.locator('#amount').fill('5000');
        await page
            .getByRole('button', { name: 'Save voucher', exact: true })
            .click();

        const voucherDialog = page.getByRole('dialog');
        await expect(voucherDialog).toBeVisible({ timeout: 10_000 });
        await voucherDialog
            .getByRole('button', { name: /Confirm create|Confirm save/i })
            .click();

        await expect(page).toHaveURL(/\/broker\/vouchers\/[0-9a-f-]+$/, {
            timeout: 20_000,
        });
        await expect(page.getByText(/5,?000\.00|5000/).first()).toBeVisible();
    });

    test('ownfleet, partload, and accounting add-on companies expose exact module navigation for owner without branch role', async ({
        page,
    }) => {
        test.setTimeout(120_000);

        await loginAs(page, E2E_OWNER_EMAIL, E2E_PASSWORD);
        const sidebar = page.locator('[data-sidebar="sidebar"]').first();

        // OwnFleet
        await enterCompanyBranch(page, COMPANIES.ownFleet);
        await expect(
            sidebar.getByText('Bilty Register', { exact: true }),
        ).toBeVisible();
        await expect(
            sidebar.getByText('Vehicle Drivers', { exact: true }),
        ).toBeVisible();
        await expect(sidebar.getByText('Lorry Hires')).toHaveCount(0);
        await expect(sidebar.getByText('Partload Challans')).toHaveCount(0);
        await expect(sidebar.getByText('Accounting')).toHaveCount(0);
        await page.goto('/vehicle-drivers', { waitUntil: 'networkidle' });
        await expect(
            page.getByRole('heading', { name: /Vehicle Drivers/i }),
        ).toBeVisible();

        // Partload
        await enterCompanyBranch(page, COMPANIES.partload);
        await expect(
            sidebar.getByText('Bilty Register', { exact: true }),
        ).toBeVisible();
        await expect(
            sidebar.getByText('Partload Challans', { exact: true }),
        ).toBeVisible();
        await expect(
            sidebar.getByText('Pending Loading', { exact: true }),
        ).toBeVisible();
        await expect(sidebar.getByText('Lorry Hires')).toHaveCount(0);
        await expect(sidebar.getByText('Vehicle Drivers')).toHaveCount(0);
        await expect(sidebar.getByText('Accounting')).toHaveCount(0);
        await page.goto('/partload/challans', { waitUntil: 'networkidle' });
        await expect(
            page.getByRole('heading', { name: /Partload Challans/i }),
        ).toBeVisible();

        // Accounting add-on + Broker
        await enterCompanyBranch(page, COMPANIES.accounting);
        await expect(
            sidebar.getByText('Accounting', { exact: true }),
        ).toBeVisible();
        await expect(
            sidebar.getByText('Lorry Hires', { exact: true }),
        ).toBeVisible();
        await page.goto('/accounting/vouchers', { waitUntil: 'networkidle' });
        await expect(
            page.getByRole('heading', { name: /Day Book/i }),
        ).toBeVisible();
    });

    test('restricted and authorized customer user personas obey role permissions AND company subscription entitlements', async ({
        browser,
    }) => {
        test.setTimeout(90_000);

        // 1. Restricted Viewer lacks LR_VIEW and BROKER_LORRY_HIRE_VIEW despite active Broker subscription
        const restrictedCtx = await browser.newContext();
        const restrictedPage = await restrictedCtx.newPage();
        await loginAs(restrictedPage, E2E_RESTRICTED_EMAIL, E2E_PASSWORD);
        await expect(restrictedPage).toHaveURL(/\/operations\/?$/, {
            timeout: 15_000,
        });

        const restrictedSidebar = restrictedPage
            .locator('[data-sidebar="sidebar"]')
            .first();
        await expect(restrictedSidebar.getByText('Lorry Hires')).toHaveCount(0);
        await expect(restrictedSidebar.getByText('Bilty Register')).toHaveCount(
            0,
        );
        const deniedResp = await restrictedPage.goto('/broker/lorry-hires');
        expect(deniedResp?.status()).toBe(403);
        await restrictedCtx.close();

        // 2. Authorized Broker Operator sees Broker items but stays blocked on unsubscribed Partload / Accounting
        const operatorCtx = await browser.newContext();
        const operatorPage = await operatorCtx.newPage();
        await loginAs(operatorPage, E2E_OPERATOR_EMAIL, E2E_PASSWORD);
        await expect(operatorPage).toHaveURL(/\/operations\/?$/, {
            timeout: 15_000,
        });

        const opSidebar = operatorPage
            .locator('[data-sidebar="sidebar"]')
            .first();
        await expect(
            opSidebar.getByText('Bilty Register', { exact: true }),
        ).toBeVisible();
        await expect(
            opSidebar.getByText('Lorry Hires', { exact: true }),
        ).toBeVisible();
        await expect(opSidebar.getByText('Partload Challans')).toHaveCount(0);
        await expect(opSidebar.getByText('Accounting')).toHaveCount(0);
        await expect(opSidebar.getByText('Invoices')).toHaveCount(0);

        await operatorPage.goto('/broker/lorry-hires', {
            waitUntil: 'networkidle',
        });
        await expect(
            operatorPage.getByRole('heading', {
                name: /Good Hires|Lorry Hire/i,
            }),
        ).toBeVisible();

        const blockedPartload = await operatorPage.goto('/partload/challans');
        expect(blockedPartload?.status()).toBe(403);
        await operatorCtx.close();
    });
});
