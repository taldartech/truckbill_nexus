import { execFileSync } from 'node:child_process';
import {
    type Browser,
    type BrowserContext,
    expect,
    type Page,
    test,
} from '@playwright/test';
import { loginAs } from './fixtures/auth';
import { enterDemoAlphaBranch } from './fixtures/demo-workspace';

const PASSWORD = 'e2e-journeys-123';
const SUPER_ADMIN = 'sa.demo.lifecycle@demo.test';
const OWNER = 'owner.alpha@demo.test';
const PARITY_OWNER = 'e2e.parity.owner@demo.test';

function assertTestDatabase(): void {
    if (
        process.env.DB_DATABASE !== 'nexus_test' ||
        process.env.APP_ENV !== 'testing'
    ) {
        throw new Error('Refusing E2E fixtures outside nexus_test / testing.');
    }
}

function artisan(
    args: string[],
    extraEnv: Record<string, string> = {},
): string {
    assertTestDatabase();

    try {
        return execFileSync('php', ['artisan', ...args], {
            cwd: process.cwd(),
            env: {
                ...process.env,
                CACHE_STORE: process.env.CACHE_STORE ?? 'file',
                ...extraEnv,
            },
            encoding: 'utf8',
            timeout: 600_000,
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (error: unknown) {
        const err = error as { stdout?: string; stderr?: string };
        throw new Error(
            `artisan ${args[0]} failed:\n${err.stdout ?? ''}\n${err.stderr ?? ''}`,
        );
    }
}

let parityCompanyId = '';

function seedFixtures(): void {
    artisan(['migrate:fresh', '--force', '--no-interaction']);
    artisan(
        [
            'db:seed',
            '--class=LocalDemoWorkspaceSeeder',
            '--force',
            '--no-interaction',
        ],
        { LOCAL_DEMO_PASSWORD: PASSWORD, LOCAL_DEMO_PROFILE: 'small' },
    );

    const output = artisan([
        'tinker',
        '--execute',
        `
use App\\Domains\\Company\\CompanyWorkspaceService;
use App\\Domains\\Master\\Party\\PartyService;
use App\\Models\\Company;
use App\\Models\\User;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Hash;

$owner = User::query()->where('email', '${OWNER}')->firstOrFail();
$company = Company::query()->where('name', 'Demo Logistics Alpha')->firstOrFail();
Auth::login($owner);
app(PartyService::class)->create($owner, $company, null, [
    'name' => 'E2E Disposable Party',
    'contact_number' => '9000099999',
    'state' => 'Maharashtra',
    'city' => 'Pune',
], [['role' => 'customer', 'attributes' => []]]);
Auth::logout();

$parity = User::factory()->customer()->create([
    'name' => 'E2E Parity Owner',
    'email' => '${PARITY_OWNER}',
    'password' => Hash::make('${PASSWORD}'),
    'email_verified_at' => now(),
]);
$parityCompany = app(CompanyWorkspaceService::class)->createCompany($parity, 'E2E Parity Co');
echo 'PARITY_COMPANY='.$parityCompany->id.PHP_EOL;
`,
    ]);

    const match = output.match(/PARITY_COMPANY=([0-9a-f-]{36})/);
    if (!match) {
        throw new Error(`Parity company not created:\n${output}`);
    }
    parityCompanyId = match[1];
}

const sessions = new Map<string, BrowserContext>();

/**
 * One authenticated context per persona keeps the serial journeys under the login throttle.
 */
async function sessionPage(browser: Browser, email: string): Promise<Page> {
    const existing = sessions.get(email);
    if (existing) {
        return existing.pages()[0];
    }

    const context = await browser.newContext();
    sessions.set(email, context);
    const page = await context.newPage();
    await loginAs(page, email, PASSWORD);
    if (email === OWNER) {
        await enterDemoAlphaBranch(page);
    }

    return page;
}

async function openPartyFromRegister(page: Page, name: string): Promise<void> {
    await page.goto(`/parties?q=${encodeURIComponent(name)}`);
    const row = page.locator('table tbody tr').filter({ hasText: name });
    await expect(row.first()).toBeVisible({ timeout: 15_000 });
    await row.first().getByRole('link', { name: 'View party' }).click();
    await expect(page.getByTestId('party-name')).toHaveText(name, {
        timeout: 15_000,
    });
}

async function selectGeography(
    page: Page,
    stateLabel: string,
    cityLabel: string,
): Promise<void> {
    const panel = page.locator('[data-searchable-combobox-panel]');
    await page.locator('#state_id-trigger').click();
    await page.getByPlaceholder(/search state/i).fill(stateLabel);
    await panel
        .getByRole('option', { name: new RegExp(`^${stateLabel}$`, 'i') })
        .click();
    await page.locator('#city_id-trigger').click();
    await page.getByPlaceholder(/search city/i).fill(cityLabel);
    await panel
        .getByRole('option', { name: new RegExp(`^${cityLabel}$`, 'i') })
        .click();
}

function parseInr(text: string): number {
    return Number(text.replace(/[^0-9.]/g, ''));
}

test.describe.configure({ mode: 'serial' });

test.describe('Subscription administration, Party Master and validation UX', () => {
    test.setTimeout(180_000);

    test.beforeAll(() => {
        test.setTimeout(900_000);
        seedFixtures();
    });

    test.afterAll(async () => {
        for (const context of sessions.values()) {
            await context.close();
        }
        sessions.clear();
    });

    test('1. billing profile is created without GST rate fields', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, SUPER_ADMIN);
        await page.goto('/admin/billing-profiles/create');
        await expect(page.locator('#legal_name')).toBeVisible();
        await expect(page.locator('#gst_rate_id')).toHaveCount(0);
        await expect(page.getByLabel(/GST Rate/i)).toHaveCount(0);

        await page.locator('#legal_name').fill('E2E No GST Profile');
        await page.locator('#pan').fill('AABCN1234F');
        await page.locator('#contact_number').fill('9876500001');
        await page.locator('#email').fill('nogst-e2e@truckbill.test');
        await page.locator('#registered_address').fill('1 Test Road');
        await selectGeography(page, 'Maharashtra', 'Pune');
        await page.locator('#pin_code').fill('411001');
        await page.locator('#gstin').fill('27AABCN1234F1Z5');
        await page
            .getByRole('button', { name: /Create Billing Profile/i })
            .click();
        await expect(page).toHaveURL(/\/admin\/billing-profiles\/[^/]+$/, {
            timeout: 15_000,
        });
        await expect(
            page.getByText('E2E No GST Profile').first(),
        ).toBeVisible();
    });

    test('2. subscription GST rate change requires remark and confirmation', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, SUPER_ADMIN);
        await page.goto('/admin/subscription-catalogue');
        const select = page.locator('#gst_rate_id');
        await expect(select).toBeVisible();
        const original = await select.inputValue();
        const alternative = await select.evaluate(
            (el, current) =>
                Array.from((el as HTMLSelectElement).options).find(
                    (o) => o.value !== '' && o.value !== current,
                )?.value ?? '',
            original,
        );
        expect(alternative).not.toBe('');

        await select.selectOption(alternative);
        await page.getByRole('button', { name: 'Save GST Rate' }).click();
        await expect(
            page.getByRole('heading', {
                name: 'Change subscription GST rate?',
            }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Confirm save' }).click();
        await expect(
            page.getByText(/edit remark is required when changing/i),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('form-error-summary')).toHaveCount(0);
        await expect(page.locator('#gst_remark')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
        await expect(select).toHaveValue(alternative);

        await page.locator('#gst_remark').fill('E2E temporary GST change');
        await page.getByRole('button', { name: 'Save GST Rate' }).click();
        await page.getByRole('button', { name: 'Confirm save' }).click();
        await expect(page.locator('#gst_remark')).toHaveValue('', {
            timeout: 15_000,
        });

        await select.selectOption(original);
        await page.locator('#gst_remark').fill('E2E restore GST rate');
        await page.getByRole('button', { name: 'Save GST Rate' }).click();
        await page.getByRole('button', { name: 'Confirm save' }).click();
        await expect(page.locator('#gst_remark')).toHaveValue('', {
            timeout: 15_000,
        });
        await expect(page.getByText(/Current rate: 18%/)).toBeVisible();
    });

    test('3. catalogue edit shows previous/new values and requires a remark', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, SUPER_ADMIN);
        await page.goto('/admin/subscription-catalogue');
        const editButton = page
            .locator('section[aria-labelledby="addons-heading"]')
            .getByRole('button', { name: /^Edit / })
            .first();
        await editButton.click();

        const price = page.locator('#item_annual_price');
        const originalPrice = await price.inputValue();
        const newPrice = String(Number(originalPrice) + 100);
        await price.fill(newPrice);
        await page.locator('#item_remark').fill('E2E price review');
        await page.getByRole('button', { name: 'Review changes' }).click();
        await expect(
            page.getByRole('heading', { name: 'Confirm changes' }),
        ).toBeVisible();
        await expect(
            page.getByRole('cell', { name: 'Annual Price' }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Confirm save' }).click();
        await expect(page.getByRole('dialog')).toHaveCount(0, {
            timeout: 15_000,
        });

        await editButton.click();
        await expect(price).toHaveValue(new RegExp(`^${newPrice}`));
        await price.fill(originalPrice);
        await page.locator('#item_remark').fill('E2E restore price');
        await page.getByRole('button', { name: 'Review changes' }).click();
        await page.getByRole('button', { name: 'Confirm save' }).click();
        await expect(page.getByRole('dialog')).toHaveCount(0, {
            timeout: 15_000,
        });
    });

    test('4-6. calculator prices Broker, combined modules and accounting with capacity', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, SUPER_ADMIN);
        await page.goto('/admin/subscription-calculator');
        const total = page.getByTestId('calculator-total');
        const summary = page.getByTestId('calculator-summary');

        const firstPlan = async (selector: string) =>
            page
                .locator(selector)
                .evaluate(
                    (el) =>
                        Array.from((el as HTMLSelectElement).options).find(
                            (o) => o.value !== '',
                        )?.value ?? '',
                );

        await page
            .locator('#plan_broker')
            .selectOption(await firstPlan('#plan_broker'));
        await expect(total).toBeVisible({ timeout: 15_000 });
        const brokerTotal = parseInr((await total.textContent()) ?? '');
        expect(brokerTotal).toBeGreaterThan(0);
        await expect(summary).toContainText('multi-module 0%');

        await page
            .locator('#plan_ownfleet')
            .selectOption(await firstPlan('#plan_ownfleet'));
        await expect(summary).toContainText('multi-module 10%', {
            timeout: 15_000,
        });
        await expect
            .poll(async () => parseInr((await total.textContent()) ?? ''))
            .toBeGreaterThan(brokerTotal);

        await page.getByRole('checkbox', { name: /Accounting/i }).check();
        await page.locator('#total_branches').fill('4');
        await page.locator('#total_users').fill('12');
        await expect(summary).toContainText('2 additional', {
            timeout: 15_000,
        });
        await expect(summary).toContainText('Accounting');

        await page.getByRole('button', { name: 'Reset' }).click();
        await expect(page.locator('#plan_broker')).toHaveValue('');
    });

    test('7. calculator total matches owner checkout quote', async ({
        browser,
    }) => {
        const admin = await sessionPage(browser, SUPER_ADMIN);
        await admin.goto('/admin/subscription-calculator');
        const planId = await admin
            .locator('#plan_broker')
            .evaluate(
                (el) =>
                    Array.from((el as HTMLSelectElement).options).find(
                        (o) => o.value !== '',
                    )?.value ?? '',
            );
        await admin.locator('#plan_broker').selectOption(planId);
        await expect(admin.getByTestId('calculator-total')).toBeVisible({
            timeout: 15_000,
        });
        const calculatorTotal = parseInr(
            (await admin.getByTestId('calculator-total').textContent()) ?? '',
        );
        const tenure = await admin.locator('#tenure').inputValue();

        const ownerContext = await browser.newContext();
        const owner = await ownerContext.newPage();
        await loginAs(owner, PARITY_OWNER, PASSWORD);
        const ownerQuote = await owner.evaluate(
            async ({ companyId, plan, period }) => {
                const token = decodeURIComponent(
                    document.cookie
                        .split('; ')
                        .find((c) => c.startsWith('XSRF-TOKEN='))
                        ?.split('=')[1] ?? '',
                );
                const response = await fetch(
                    `/management/companies/${companyId}/subscription/quote`,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-XSRF-TOKEN': token,
                        },
                        body: JSON.stringify({
                            plan_ids: [plan],
                            addon_ids: [],
                            addon_quantities: {},
                            tenure: period,
                            mode: 'initial',
                        }),
                    },
                );

                return { status: response.status, body: await response.json() };
            },
            { companyId: parityCompanyId, plan: planId, period: tenure },
        );
        await ownerContext.close();

        expect(ownerQuote.status).toBe(200);
        expect(Number(ownerQuote.body.total_payable)).toBeCloseTo(
            calculatorTotal,
            2,
        );
    });

    test('8. customer party shows only relevant sections', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, OWNER);
        await openPartyFromRegister(page, 'Alpha Retail Customer');
        await expect(page.getByLabel('Roles')).toContainText('Customer');
        await expect(page.getByText('Basic information')).toHaveCount(0);
        await expect(page.getByText('Business & tax')).toBeVisible();
        await expect(page.getByText('Driver details')).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Back' })).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Edit party' }),
        ).toBeVisible();
        await expect(page.getByText(/Assign role/i)).toHaveCount(0);
    });

    test('9. driver party shows driver details', async ({ browser }) => {
        const page = await sessionPage(browser, OWNER);
        await openPartyFromRegister(page, 'Alpha Primary Driver');
        await expect(page.getByLabel('Roles')).toContainText('Driver');
        await expect(page.getByText('Driver details')).toBeVisible();
        await expect(page.getByText('Business & tax')).toHaveCount(0);
    });

    test('10. multi-role party lists every role', async ({ browser }) => {
        const page = await sessionPage(browser, OWNER);
        await openPartyFromRegister(page, 'Demo Party 2 Demo Logistics Alpha');
        const roles = page.getByLabel('Roles');
        await expect(roles).toContainText('Consignor');
        await expect(roles).toContainText('Consignee');
    });

    test('11. a role is added through the Edit form', async ({ browser }) => {
        const page = await sessionPage(browser, OWNER);
        await openPartyFromRegister(page, 'Alpha Consignor Mills');
        await page.getByRole('link', { name: 'Edit party' }).click();
        await page.getByRole('checkbox', { name: 'Consignee' }).click();
        await page.locator('#remark').fill('E2E add consignee role');
        await page.getByRole('button', { name: 'Save' }).click();
        await page.getByRole('button', { name: 'Confirm save' }).click();
        await expect(page.getByTestId('party-name')).toHaveText(
            'Alpha Consignor Mills',
            { timeout: 15_000 },
        );
        await expect(page.getByLabel('Roles')).toContainText('Consignee');
    });

    test('12. register filters, columns and delete with remark', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, OWNER);
        await page.goto('/parties');
        for (const heading of [
            'Party Name',
            'Roles',
            'Contact Number',
            'Location',
            'GSTIN',
            'Status',
        ]) {
            await expect(
                page.getByRole('columnheader', { name: heading }),
            ).toBeVisible();
        }

        await page.locator('#party-role').selectOption('driver');
        await page.getByRole('button', { name: 'Filter' }).click();
        await expect(
            page
                .locator('table tbody tr')
                .filter({ hasText: 'Alpha Primary Driver' }),
        ).toBeVisible();
        await expect(
            page
                .locator('table tbody tr')
                .filter({ hasText: 'Alpha Retail Customer' }),
        ).toHaveCount(0);

        await page.goto('/parties?q=E2E%20Disposable');
        const row = page
            .locator('table tbody tr')
            .filter({ hasText: 'E2E Disposable Party' });
        await row.getByRole('button', { name: 'Delete party' }).click();
        await page.getByRole('button', { name: 'Delete party' }).last().click();
        await expect(page.getByText('Audit remark is required.')).toBeVisible();
        await page
            .getByRole('dialog')
            .getByLabel('Audit remark')
            .fill('E2E duplicate record');
        await page.getByRole('button', { name: 'Delete party' }).last().click();
        await expect(page.getByRole('status')).toHaveText('Party deleted.', {
            timeout: 15_000,
        });

        await page.goto('/parties?status=deleted&q=E2E%20Disposable');
        await expect(
            page
                .locator('table tbody tr')
                .filter({ hasText: 'E2E Disposable Party' }),
        ).toContainText('Deleted');
    });

    test('13. missing mandatory fields show inline errors and keep values', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, OWNER);
        await page.goto('/parties/create');
        await page.locator('#name').fill('E2E Incomplete Customer');
        await page
            .locator('#party-master-form')
            .evaluate((form) => ((form as HTMLFormElement).noValidate = true));
        await page.getByRole('button', { name: 'Save' }).click();
        await page.getByRole('button', { name: 'Confirm create' }).click();

        const contact = page.locator('#contact_number');
        await expect(contact).toHaveAttribute('aria-invalid', 'true', {
            timeout: 15_000,
        });
        await expect(
            page.getByText('Contact number is required.'),
        ).toBeVisible();
        await expect(page.locator('#name')).toHaveValue(
            'E2E Incomplete Customer',
        );
        await expect(page.getByRole('dialog')).toHaveCount(0);
    });

    test('14. server duplicate error appears under the field', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, OWNER);
        await page.goto('/goods-types');
        await page.getByRole('button', { name: /^Add goods type/i }).click();
        await page.locator('#catalogue-name').fill('General Cargo');
        await page.getByRole('button', { name: 'Review' }).click();
        await page.getByRole('button', { name: 'Create', exact: true }).click();
        await expect(
            page.getByText(/Catalogue name already exists/i),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('#catalogue-name')).toHaveValue(
            'General Cargo',
        );
    });

    test('15. nested Quick Add errors stay inside the sheet', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, OWNER);
        await page.goto('/lorry-receipts/create');
        await page
            .locator('div.space-y-1')
            .filter({ has: page.getByText('Goods Type', { exact: true }) })
            .getByRole('button', { name: '+ Add', exact: true })
            .first()
            .click();
        await page.locator('#catalogue-name').fill('General Cargo');
        await page.getByRole('button', { name: 'Review' }).click();
        await page.getByRole('button', { name: 'Create', exact: true }).click();
        await expect(
            page.getByText(/Catalogue name already exists/i),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('form-error-summary')).toHaveCount(0);
        await expect(page.locator('#catalogue-name')).toBeVisible();
    });

    test('16. errors after confirmation stay visible', async ({ browser }) => {
        const page = await sessionPage(browser, OWNER);
        await openPartyFromRegister(page, 'Alpha Retail Customer');
        await page.getByRole('link', { name: 'Edit party' }).click();
        await page.getByRole('checkbox', { name: 'Consignor' }).click();
        await page.getByRole('checkbox', { name: 'Customer' }).click();
        await page.locator('#remark').fill('E2E try removing customer');
        await page.getByRole('button', { name: 'Save' }).click();
        await page.getByRole('button', { name: 'Confirm save' }).click();

        await expect(
            page.getByText(/Customer role cannot be removed/i).first(),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(page.locator('#remark')).toHaveValue(
            'E2E try removing customer',
        );
    });

    test('17. goods rows and accounting lines show cell-level errors', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, OWNER);
        await page.goto('/lorry-receipts/create');
        const packageCount = page.locator('#goods_0_package_count');
        await expect(packageCount).toBeVisible({ timeout: 15_000 });
        await page
            .locator('#lorry-receipt-form')
            .evaluate((form) =>
                form
                    .querySelectorAll('[required]')
                    .forEach((el) => el.removeAttribute('required')),
            );
        await packageCount.fill('');
        await page.getByRole('button', { name: 'Save Bilty' }).first().click();
        await page.getByRole('button', { name: 'Save Bilty' }).last().click();
        await expect(packageCount).toHaveAttribute('aria-invalid', 'true', {
            timeout: 15_000,
        });

        await page.goto('/accounting/vouchers/create');
        await page.locator('#voucher_date').fill('2026-11-27');
        const rows = page.locator('table tbody tr');
        await rows
            .nth(0)
            .locator('input[name="lines[0][debit_amount]"]')
            .fill('100');
        await rows
            .nth(1)
            .locator('input[name="lines[1][credit_amount]"]')
            .fill('100');
        await page
            .locator('select[name^="lines["]')
            .evaluateAll((selects) =>
                selects.forEach((el) => el.removeAttribute('required')),
            );
        await page.getByRole('button', { name: 'Post Voucher' }).click();
        const firstLedger = page.locator('#voucher-line-ledger-0');
        await expect(firstLedger).toHaveAttribute('aria-invalid', 'true', {
            timeout: 15_000,
        });
        await expect(
            rows.nth(0).locator('[data-slot="input-error"]'),
        ).toBeVisible();
    });
});
