import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { expect, type Page, test } from '@playwright/test';
import { loginAs } from './fixtures/auth';

const PASSWORD = 'e2e-password-123';
const EMAILS = {
    superAdmin: 'e2e.superadmin@truckbill.test',
    internalAdmin: 'e2e.admin@truckbill.test',
    salesExec: 'e2e.sales@truckbill.test',
    owner: 'e2e.billing.owner@truckbill.test',
    branchUser: 'e2e.billing.operator@truckbill.test',
} as const;

const COMPANY_NAME = 'E2E Sahyadri Freight Lines';
const BRANCH_NAME = 'Head Office';
const PROFILE_1_NAME = 'E2E TruckBill West Billing Pvt Ltd';
const PROFILE_1_EDITED_NAME = 'E2E TruckBill West Revised LLP';
const PROFILE_2_NAME = 'E2E TruckBill North Billing Pvt Ltd';

function runPhp(code: string): string {
    if (
        process.env.DB_DATABASE !== 'nexus_test' &&
        process.env.APP_ENV !== 'testing'
    ) {
        throw new Error(
            'Refusing E2E fixture setup outside nexus_test / testing environment.',
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

function seedIsolatedFixtures(): void {
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
use App\\Domains\\Rbac\\Enums\\PermissionDomain;
use App\\Domains\\Rbac\\RoleAssignmentService;
use App\\Models\\City;
use App\\Models\\Company;
use App\\Models\\GstRate;
use App\\Models\\InternalRoleAssignment;
use App\\Models\\PlatformBillingProfile;
use App\\Models\\Role;
use App\\Models\\State;
use App\\Models\\SubscriptionInvoice;
use App\\Models\\SubscriptionTaxSetting;
use App\\Models\\User;
use Database\\Seeders\\IndiaGeographySeeder;
use Database\\Seeders\\RbacSeeder;
use Database\\Seeders\\SubscriptionCatalogueSeeder;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Str;

Cache::flush();
(new RbacSeeder)->run();
(new SubscriptionCatalogueSeeder)->run();
if (State::query()->where('country_code', 'IN')->count() < 36) {
    (new IndiaGeographySeeder)->run();
}

$mh = State::query()->where('country_code', 'IN')->where('name', 'Maharashtra')->firstOrFail();
City::query()->firstOrCreate(
    ['state_id' => $mh->id, 'name_normalized' => 'pune'],
    ['id' => (string) Str::uuid(), 'name' => 'Pune', 'is_active' => true],
);
$gj = State::query()->where('country_code', 'IN')->where('name', 'Gujarat')->firstOrFail();
City::query()->firstOrCreate(
    ['state_id' => $gj->id, 'name_normalized' => 'ahmedabad'],
    ['id' => (string) Str::uuid(), 'name' => 'Ahmedabad', 'is_active' => true],
);
$gst18 = GstRate::query()->firstOrCreate(
    ['rate_percent' => '18.000', 'version' => 1],
    ['id' => (string) Str::uuid(), 'effective_from' => '2025-04-01', 'is_active' => true],
);
SubscriptionTaxSetting::query()->delete();
SubscriptionTaxSetting::query()->create(['gst_rate_id' => $gst18->id, 'gst_rate_percent' => '18.00']);

$pw = Hash::make('${PASSWORD}');

$makeUser = static function (string $email, string $name, string $type, bool $superAdmin) use ($pw): User {
    $existing = User::query()->where('email', $email)->first();
    if ($existing !== null) {
        return $existing;
    }
    $user = new User();
    $user->forceFill([
        'name' => $name,
        'email' => $email,
        'identity_type' => $type,
        'is_super_admin' => $superAdmin,
        'password' => $pw,
        'email_verified_at' => now(),
    ]);
    $user->save();

    return $user;
};

$superAdmin = $makeUser('${EMAILS.superAdmin}', 'E2E Super Admin', 'internal', true);
$admin = $makeUser('${EMAILS.internalAdmin}', 'E2E Internal Admin', 'internal', false);
$adminRole = Role::query()->where('domain', PermissionDomain::Internal->value)->where('slug', 'internal.admin')->firstOrFail();
InternalRoleAssignment::query()->firstOrCreate(
    ['user_id' => $admin->id, 'role_id' => $adminRole->id, 'deactivated_at' => null],
    ['id' => (string) Str::uuid()],
);

$sales = $makeUser('${EMAILS.salesExec}', 'E2E Sales Exec', 'internal', false);
$salesRole = Role::query()->where('domain', PermissionDomain::Internal->value)->where('slug', 'internal.sales_executive')->firstOrFail();
InternalRoleAssignment::query()->firstOrCreate(
    ['user_id' => $sales->id, 'role_id' => $salesRole->id, 'deactivated_at' => null],
    ['id' => (string) Str::uuid()],
);

// Clean previous runs for idempotency.
foreach (['${PROFILE_1_NAME}', '${PROFILE_1_EDITED_NAME}', '${PROFILE_2_NAME}'] as $pName) {
    PlatformBillingProfile::query()->where('legal_name', $pName)->forceDelete();
}

$owner = $makeUser('${EMAILS.owner}', 'E2E Billing Owner', 'customer', false);

$existingCompany = Company::query()->where('owner_user_id', $owner->id)->where('name', '${COMPANY_NAME}')->first();
if ($existingCompany) {
    SubscriptionInvoice::query()->where('company_id', $existingCompany->id)->forceDelete();
    $existingCompany->subscriptions()->delete();
    $existingCompany->branches()->delete();
    $existingCompany->forceDelete();
}

$workspace = app(CompanyWorkspaceService::class);
$company = $workspace->createCompany($owner, '${COMPANY_NAME}');
$company->forceFill([
    'registered_address' => '22 Transport Plaza, Nashik',
    'state_id' => $mh->id,
    'state' => 'Maharashtra',
    'city' => 'Nashik',
    'pin' => '422001',
    'gstin' => '27AAACS4444K1Z2',
    'pan' => 'AAACS4444K',
])->save();

$branch = $company->branches()->where('name', '${BRANCH_NAME}')->firstOrFail();

$branchUser = $makeUser('${EMAILS.branchUser}', 'E2E Branch Operator', 'customer', false);
$customerAdminRole = Role::query()->where('domain', PermissionDomain::Customer->value)->where('slug', 'customer.admin')->firstOrFail();
$membership = $workspace->addMembership($company, $branchUser, 'E2E operator setup');
$assignment = $workspace->assignBranch($membership, $branch, 'E2E HO branch');
app(RoleAssignmentService::class)->assignCustomerBranchRole($owner, $membership, $branch, $assignment, $customerAdminRole, 'E2E admin');

echo 'SEEDED_OK:' . $company->id;
`;
    const out = runPhp(php);
    expect(out).toContain('SEEDED_OK:');
}

function createSamplePngPath(): string {
    const dir = mkdtempSync(join(tmpdir(), 'tb-e2e-logo-'));
    const path = join(dir, 'company-logo.png');
    // 1x1 transparent PNG
    const base64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+X2Z0AAAAASUVORK5CYII=';
    writeFileSync(path, Buffer.from(base64, 'base64'));
    return path;
}

async function logoutViaUi(page: Page): Promise<void> {
    await page.evaluate(async () => {
        const token =
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '';
        await fetch('/logout', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': token,
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        });
    });
    await page.goto('/login', { waitUntil: 'networkidle' });
}

async function selectGeography(
    page: Page,
    stateLabel: string,
    cityLabel: string,
): Promise<void> {
    await page.locator('#state_id-trigger').click();
    const stateSearch = page.getByPlaceholder(/search state/i);
    await expect(stateSearch).toBeVisible({ timeout: 10_000 });
    await stateSearch.fill(stateLabel);
    const panel = page.locator('[data-searchable-combobox-panel]');
    await expect(
        panel.getByRole('option', { name: new RegExp(`^${stateLabel}$`, 'i') }),
    ).toBeVisible({ timeout: 10_000 });
    await panel
        .getByRole('option', { name: new RegExp(`^${stateLabel}$`, 'i') })
        .click();

    await page.locator('#city_id-trigger').click();
    const citySearch = page.getByPlaceholder(/search city/i);
    await expect(citySearch).toBeVisible({ timeout: 10_000 });
    await citySearch.fill(cityLabel);
    await expect(
        panel.getByRole('option', { name: new RegExp(`^${cityLabel}$`, 'i') }),
    ).toBeVisible({ timeout: 10_000 });
    await panel
        .getByRole('option', { name: new RegExp(`^${cityLabel}$`, 'i') })
        .click();
}

test.describe
    .serial('Billing Profiles, Invoice Approval & CRM Login E2E', () => {
    test.beforeAll(() => {
        seedIsolatedFixtures();
    });

    test('authentication routing works cleanly across all internal and customer personas without branch redirect error', async ({
        browser,
    }) => {
        // 1. Clean Super Admin login
        const ctx1 = await browser.newContext();
        const p1 = await ctx1.newPage();
        await loginAs(p1, EMAILS.superAdmin, PASSWORD);
        await expect(p1).toHaveURL(/\/dashboard\/?$/, { timeout: 15_000 });
        await expect(
            p1.getByText(/Branch Panel requires an active Customer identity/i),
        ).toHaveCount(0);
        await ctx1.close();

        // 2. Internal Admin login
        const ctx2 = await browser.newContext();
        const p2 = await ctx2.newPage();
        await loginAs(p2, EMAILS.internalAdmin, PASSWORD);
        await expect(p2).toHaveURL(/\/dashboard\/?$/, { timeout: 15_000 });
        await expect(
            p2.getByText(/Branch Panel requires an active Customer identity/i),
        ).toHaveCount(0);
        await ctx2.close();

        // 3. Sales Executive login
        const ctx3 = await browser.newContext();
        const p3 = await ctx3.newPage();
        await loginAs(p3, EMAILS.salesExec, PASSWORD);
        await expect(p3).toHaveURL(/\/dashboard\/?$/, { timeout: 15_000 });
        await expect(
            p3.getByText(/Branch Panel requires an active Customer identity/i),
        ).toHaveCount(0);
        await ctx3.close();

        // 4. Internal login AFTER visiting protected Branch URL (/operations)
        const ctx4 = await browser.newContext();
        const p4 = await ctx4.newPage();
        await p4.goto('/operations', { waitUntil: 'networkidle' });
        await expect(p4).toHaveURL(/\/login\/?$/, { timeout: 15_000 });
        await p4.locator('#email').fill(EMAILS.internalAdmin);
        await p4.locator('#password').fill(PASSWORD);
        await p4.getByRole('button', { name: /log in|sign in/i }).click();
        await expect(p4).toHaveURL(/\/dashboard\/?$/, { timeout: 15_000 });
        await expect(
            p4.getByText(/Branch Panel requires an active Customer identity/i),
        ).toHaveCount(0);
        await ctx4.close();

        // 5. Internal login with stale Customer workspace session in same browser context
        const ctx5 = await browser.newContext();
        const p5 = await ctx5.newPage();
        await loginAs(p5, EMAILS.branchUser, PASSWORD);
        await expect(p5).toHaveURL(/\/operations\/?$/, { timeout: 15_000 });
        await logoutViaUi(p5);
        await p5.locator('#email').fill(EMAILS.superAdmin);
        await p5.locator('#password').fill(PASSWORD);
        await p5.getByRole('button', { name: /log in|sign in/i }).click();
        await expect(p5).toHaveURL(/\/dashboard\/?$/, { timeout: 15_000 });
        await expect(
            p5.getByText(/Branch Panel requires an active Customer identity/i),
        ).toHaveCount(0);
        await ctx5.close();

        // 6. Customer Owner login lands on Owner Panel (/management)
        const ctx6 = await browser.newContext();
        const p6 = await ctx6.newPage();
        await loginAs(p6, EMAILS.owner, PASSWORD);
        await expect(p6).toHaveURL(/\/management/, { timeout: 15_000 });
        await ctx6.close();

        // 7. Customer User login lands on Branch Panel (/operations)
        const ctx7 = await browser.newContext();
        const p7 = await ctx7.newPage();
        await loginAs(p7, EMAILS.branchUser, PASSWORD);
        await expect(p7).toHaveURL(/\/operations\/?$/, { timeout: 15_000 });
        await ctx7.close();
    });

    test('end-to-end multiple billing profiles, subscription purchase, pending approval visibility block, manual approval, immutable snapshot, edit and duplicate rejection', async ({
        browser,
    }) => {
        test.setTimeout(120_000);
        const logoPath = createSamplePngPath();
        const adminCtx = await browser.newContext();
        const adminPage = await adminCtx.newPage();

        // Step 1: Super Admin creates 2 Billing Profiles via UI (no remark on creation).
        await loginAs(adminPage, EMAILS.superAdmin, PASSWORD);
        await adminPage.goto('/admin/billing-profiles', {
            waitUntil: 'networkidle',
        });
        await expect(
            adminPage.getByRole('heading', {
                name: 'Company Billing Profiles',
            }),
        ).toBeVisible();

        // Create Profile 2 FIRST (Gujarat) so Profile 1 (Maharashtra) becomes currentActive() for intra-state checkout,
        // allowing us to verify BOTH the Gujarat GST-conflict block AND Maharashtra Logo/Bank/Terms snapshot immutability!
        await adminPage.goto('/admin/billing-profiles/create', {
            waitUntil: 'networkidle',
        });
        await adminPage.locator('#legal_name').fill(PROFILE_2_NAME);
        await adminPage.locator('#pan').fill('AABCT9999H');
        await adminPage.locator('#contact_number').fill('9876543299');
        await adminPage.locator('#email').fill('north-e2e@truckbill.test');
        await adminPage
            .locator('#registered_address')
            .fill('77 Corporate Road, SG Highway');
        await selectGeography(adminPage, 'Gujarat', 'Ahmedabad');
        await adminPage.locator('#pin_code').fill('380054');
        await adminPage.locator('#gstin').fill('24AABCT9999H1Z2');
        await adminPage
            .getByRole('button', { name: /Create Billing Profile/i })
            .click();

        await expect(adminPage.getByText(PROFILE_2_NAME).first()).toBeVisible({
            timeout: 15_000,
        });

        // Create Profile 1 SECOND (Maharashtra, with Logo, Bank, Website, Notes)
        await adminPage.goto('/admin/billing-profiles/create', {
            waitUntil: 'networkidle',
        });
        await adminPage.locator('#legal_name').fill(PROFILE_1_NAME);
        await adminPage.locator('#pan').fill('AABCT1234F');
        await adminPage.locator('#logo').setInputFiles(logoPath);
        await adminPage.locator('#contact_number').fill('9876543210');
        await adminPage.locator('#email').fill('west-e2e@truckbill.test');
        await adminPage.locator('#website').fill('https://west.truckbill.test');
        await adminPage
            .locator('#registered_address')
            .fill('401 Tech Park, Baner');
        await selectGeography(adminPage, 'Maharashtra', 'Pune');
        await adminPage.locator('#pin_code').fill('411045');
        await adminPage.locator('#gstin').fill('27AABCT1234F1Z5');
        await adminPage.locator('#bank_name').fill('HDFC Bank');
        await adminPage.locator('#bank_account_name').fill(PROFILE_1_NAME);
        await adminPage.locator('#bank_account_number').fill('50200012345678');
        await adminPage.locator('#bank_ifsc').fill('HDFC0000123');
        await adminPage.locator('#notes_terms').fill('Original E2E Terms V1');
        await adminPage
            .getByRole('button', { name: /Create Billing Profile/i })
            .click();

        await expect(adminPage).toHaveURL(/\/admin\/billing-profiles\/[^/]+$/, {
            timeout: 15_000,
        });
        await expect(adminPage.getByText(PROFILE_1_NAME).first()).toBeVisible();

        await adminPage.goto('/admin/billing-profiles', {
            waitUntil: 'networkidle',
        });
        await expect(
            adminPage.locator('table').getByText(PROFILE_1_NAME).first(),
        ).toBeVisible();
        await expect(
            adminPage.locator('table').getByText(PROFILE_2_NAME).first(),
        ).toBeVisible();

        // Step 2: Customer Owner purchases Broker subscription using FakePaymentGateway test mode.
        const ownerCtx = await browser.newContext();
        const ownerPage = await ownerCtx.newPage();
        await loginAs(ownerPage, EMAILS.owner, PASSWORD);
        await ownerPage.goto('/management/subscription/purchase', {
            waitUntil: 'networkidle',
        });

        // Select Broker plan & proceed to checkout
        await ownerPage
            .locator('button')
            .filter({ hasText: /Broker/i })
            .first()
            .click();
        const reviewPay = ownerPage.getByRole('button', {
            name: /Review & Pay/i,
        });
        await expect(reviewPay).toBeEnabled({ timeout: 10_000 });
        await reviewPay.click();

        await expect(ownerPage).toHaveURL(/\/subscription\/checkout\//, {
            timeout: 15_000,
        });
        await ownerPage
            .getByRole('button', { name: /Simulate Verified Capture/i })
            .click();

        // Verify immediate activation AND pending invoice visibility block!
        await expect(ownerPage).toHaveURL(/\/subscription\/purchase/, {
            timeout: 15_000,
        });
        await expect(ownerPage.getByText(/Status:\s*active/i)).toBeVisible({
            timeout: 10_000,
        });

        await ownerPage.goto('/management/subscription/payments', {
            waitUntil: 'networkidle',
        });
        await expect(
            ownerPage.getByText(/Invoice Pending Approval/i).first(),
        ).toBeVisible({ timeout: 10_000 });
        await expect(
            ownerPage.getByRole('link', { name: /Download invoice/i }),
        ).toHaveCount(0);

        await ownerPage.goto('/management/subscription/invoices', {
            waitUntil: 'networkidle',
        });
        await expect(
            ownerPage.getByText(/Invoice Pending Approval/i).first(),
        ).toBeVisible();

        // Fetch pending invoice ID from DB to test direct URL 403 block.
        const pendingId = runPhp(
            `echo App\\Models\\SubscriptionInvoice::query()->where('status', 'pending_approval')->latest('created_at')->value('id');`,
        ).trim();
        expect(pendingId.length).toBeGreaterThan(10);

        const directShowResp = await ownerPage.goto(
            `/management/subscription/invoices/${pendingId}`,
        );
        expect(directShowResp?.status()).toBe(403);
        const directDownloadResp = await ownerPage.goto(
            `/management/subscription/invoices/${pendingId}/download`,
        );
        expect(directDownloadResp?.status()).toBe(403);

        // Step 3: Internal Admin opens Subscription Invoices Register, filters Pending Approval, selects Profile 1 + Invoice Number, and approves.
        await adminPage.goto(
            '/admin/subscription-invoices?status=pending_approval',
            {
                waitUntil: 'networkidle',
            },
        );
        await expect(
            adminPage.locator('table').getByText(COMPANY_NAME).first(),
        ).toBeVisible({
            timeout: 15_000,
        });
        await adminPage.goto(`/admin/subscription-invoices/${pendingId}`, {
            waitUntil: 'networkidle',
        });

        const profile2OptionVal = await adminPage.evaluate((name) => {
            const sel = document.getElementById(
                'billing_profile_id',
            ) as HTMLSelectElement | null;
            const opt = Array.from(sel?.options ?? []).find((o) =>
                o.text.includes(name),
            );
            return opt?.value ?? '';
        }, PROFILE_2_NAME);
        expect(profile2OptionVal).not.toBe('');
        // Selecting incompatible Gujarat profile shows GST conflict alert and blocks approval button.
        await adminPage
            .locator('#billing_profile_id')
            .selectOption(profile2OptionVal);
        await expect(
            adminPage.getByText(/GST configuration conflict/i),
        ).toBeVisible();
        await expect(
            adminPage.getByRole('button', {
                name: /Review & Approve Invoice/i,
            }),
        ).toBeDisabled();

        // Selecting compatible Maharashtra Profile 1 clears conflict and enables approval.
        const profile1OptionVal = await adminPage.evaluate((name) => {
            const sel = document.getElementById(
                'billing_profile_id',
            ) as HTMLSelectElement | null;
            const opt = Array.from(sel?.options ?? []).find((o) =>
                o.text.includes(name),
            );
            return opt?.value ?? '';
        }, PROFILE_1_NAME);
        expect(profile1OptionVal).not.toBe('');
        await adminPage
            .locator('#billing_profile_id')
            .selectOption(profile1OptionVal);
        await adminPage.locator('#invoice_number').fill('E2E-INV-2026-001');
        await adminPage
            .getByRole('button', { name: /Review & Approve Invoice/i })
            .click();

        await expect(
            adminPage.getByText(/Confirm Subscription Invoice Approval/i),
        ).toBeVisible();
        await adminPage
            .getByRole('button', { name: /Confirm & Approve Invoice/i })
            .click();

        await expect(
            adminPage.getByText(/E2E-INV-2026-001/).first(),
        ).toBeVisible({ timeout: 15_000 });
        // Duplicate approval form is no longer rendered on approved invoice.
        await expect(
            adminPage.getByRole('button', {
                name: /Review & Approve Invoice/i,
            }),
        ).toHaveCount(0);

        // Step 4: Customer Owner can now view & download approved invoice from Owner Panel -> Payment History.
        await ownerPage.goto('/management/subscription/payments', {
            waitUntil: 'networkidle',
        });
        await expect(
            ownerPage.getByText('E2E-INV-2026-001').first(),
        ).toBeVisible({ timeout: 10_000 });
        const approvedShowResp = await ownerPage.goto(
            `/management/subscription/invoices/${pendingId}`,
            { waitUntil: 'networkidle' },
        );
        expect(approvedShowResp?.status()).toBe(200);
        await expect(ownerPage.getByText(PROFILE_1_NAME).first()).toBeVisible();
        await expect(
            ownerPage.getByText('Original E2E Terms V1').first(),
        ).toBeVisible();

        const downloadResp = await ownerPage.request.get(
            `/management/subscription/invoices/${pendingId}/download`,
        );
        expect(downloadResp.status()).toBe(200);
        const downloadedHtml = await downloadResp.text();
        expect(downloadedHtml).toContain(PROFILE_1_NAME);
        expect(downloadedHtml).toContain('E2E-INV-2026-001');
        expect(downloadedHtml).toContain('Original E2E Terms V1');

        // Step 5: Edit Billing Profile in UI with diff confirmation & mandatory Edit Remark.
        await adminPage.goto(
            `/admin/billing-profiles/${profile1OptionVal}/edit`,
            { waitUntil: 'networkidle' },
        );
        await adminPage.locator('#legal_name').fill(PROFILE_1_EDITED_NAME);
        await adminPage.locator('#notes_terms').fill('Revised E2E Terms V2');
        await adminPage
            .getByRole('button', { name: /Review & Confirm Update/i })
            .click();

        await expect(
            adminPage.getByText(/Confirm Billing Profile Update/i),
        ).toBeVisible();
        await expect(adminPage.getByText(PROFILE_1_NAME).first()).toBeVisible();
        await expect(
            adminPage.getByText(PROFILE_1_EDITED_NAME).first(),
        ).toBeVisible();
        await adminPage
            .locator('#edit_remark')
            .fill('E2E entity rename verification');
        await adminPage
            .getByRole('button', { name: /Confirm & Save Changes/i })
            .click();

        await expect(
            adminPage.getByText(PROFILE_1_EDITED_NAME).first(),
        ).toBeVisible({ timeout: 15_000 });

        // Step 6: Verify historical approved invoice snapshot remains completely UNCHANGED!
        const postEditDownload = await ownerPage.request.get(
            `/management/subscription/invoices/${pendingId}/download`,
        );
        expect(postEditDownload.status()).toBe(200);
        const postEditHtml = await postEditDownload.text();
        expect(postEditHtml).toContain(PROFILE_1_NAME);
        expect(postEditHtml).toContain('Original E2E Terms V1');
        expect(postEditHtml).not.toContain(PROFILE_1_EDITED_NAME);
        expect(postEditHtml).not.toContain('Revised E2E Terms V2');

        // Step 7: Create a 2nd payment via OwnFleet upgrade & verify duplicate invoice-number rejection in UI.
        await ownerPage.goto('/management/subscription/purchase', {
            waitUntil: 'networkidle',
        });
        await ownerPage
            .locator('button')
            .filter({ hasText: /OwnFleet/i })
            .first()
            .click();
        await ownerPage.getByRole('button', { name: /Review & Pay/i }).click();
        await expect(ownerPage).toHaveURL(/\/subscription\/checkout\//, {
            timeout: 15_000,
        });
        await ownerPage
            .getByRole('button', { name: /Simulate Verified Capture/i })
            .click();
        await expect(ownerPage).toHaveURL(/\/subscription\/purchase/, {
            timeout: 15_000,
        });

        const secondPendingId = runPhp(
            `echo App\\Models\\SubscriptionInvoice::query()->where('status', 'pending_approval')->latest('created_at')->value('id');`,
        ).trim();
        expect(secondPendingId).not.toBe(pendingId);

        await adminPage.goto(
            `/admin/subscription-invoices/${secondPendingId}`,
            {
                waitUntil: 'networkidle',
            },
        );
        await adminPage
            .locator('#billing_profile_id')
            .selectOption(profile1OptionVal);
        await adminPage.locator('#invoice_number').fill('E2E-INV-2026-001');
        await adminPage
            .getByRole('button', { name: /Review & Approve Invoice/i })
            .click();
        await adminPage
            .getByRole('button', { name: /Confirm & Approve Invoice/i })
            .click();

        await expect(
            adminPage.getByText(/already issued/i).first(),
        ).toBeVisible({ timeout: 15_000 });

        await ownerCtx.close();
        await adminCtx.close();
    });
});
