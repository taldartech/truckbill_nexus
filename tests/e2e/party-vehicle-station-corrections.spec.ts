import { execFileSync } from 'node:child_process';
import {
    type Browser,
    type BrowserContext,
    expect,
    type Locator,
    type Page,
    test,
} from '@playwright/test';
import { loginAs } from './fixtures/auth';
import { enterDemoAlphaBranch } from './fixtures/demo-workspace';

const PASSWORD = 'e2e-corrections-123';
const OWNER = 'owner.alpha@demo.test';

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
    await enterDemoAlphaBranch(page);

    return page;
}

async function pickComboboxOption(
    scope: Page | Locator,
    page: Page,
    triggerId: string,
    search: string,
    optionLabel: string,
): Promise<void> {
    await scope.locator(`#${triggerId}-trigger`).click();
    const searchInput = page.locator(
        '[data-searchable-combobox-panel] input[type="search"]',
    );
    await expect(searchInput).toBeFocused();
    await page.keyboard.type(search, { delay: 30 });
    await expect(searchInput).toHaveValue(search);
    await page
        .locator('[data-searchable-combobox-panel]')
        .getByRole('option', { name: new RegExp(`^${optionLabel}$`, 'i') })
        .click();
    await expect(scope.locator(`#${triggerId}-trigger`)).toContainText(
        optionLabel,
    );
}

test.describe.configure({ mode: 'serial' });

test.describe('Party Master, Market Vehicle, Station and header corrections', () => {
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

    test('station register Add station sheet selects State and City', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, OWNER);
        await page.goto('/stations');
        await page.getByRole('button', { name: 'Add station' }).click();
        const sheet = page.getByRole('dialog');
        await sheet.locator('#station-name').fill('E2E Chakan Hub');
        await pickComboboxOption(
            sheet,
            page,
            'state_id',
            'Mahar',
            'Maharashtra',
        );
        await pickComboboxOption(sheet, page, 'city_id', 'Pune', 'Pune');

        await sheet.locator('#state_id-trigger').click();
        await page.keyboard.press('Escape');
        await expect(
            page.locator('[data-searchable-combobox-panel]'),
        ).toHaveCount(0);
        await expect(sheet).toBeVisible();

        await sheet.getByRole('button', { name: 'Review' }).click();
        const confirm = page.getByRole('dialog', { name: 'Confirm Station' });
        await expect(confirm).toContainText('Maharashtra');
        await confirm.getByRole('button', { name: 'Create' }).click();
        await expect(
            page
                .locator('table tbody tr')
                .filter({ hasText: 'E2E Chakan Hub' }),
        ).toContainText('Pune, Maharashtra');
    });

    test('party create keeps values on server error, then reviews sections and saves', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, OWNER);
        await page.goto('/parties/create');
        await page
            .getByRole('group', { name: 'Party roles' })
            .getByRole('checkbox', { name: 'General vendor' })
            .click();
        await page.locator('#name').fill('E2E Diesel Traders');
        await expect(page.locator('#short_name')).toHaveValue(
            'E2E Diesel Traders',
        );
        await page.locator('#short_name').fill('DIESELT');
        await page.locator('#name').fill('E2E Diesel Traders Pvt Ltd');
        await expect(page.locator('#short_name')).toHaveValue('DIESELT');
        await page.locator('#contact_person').fill('Mahesh');
        await page.locator('#contact_number').fill('9000000077');
        await pickComboboxOption(
            page,
            page,
            'state_id',
            'Mahar',
            'Maharashtra',
        );
        await pickComboboxOption(page, page, 'city_id', 'Pune', 'Pune');
        await page.locator('#bank_name').fill('HDFC Bank');
        await page.locator('#bank_account_number').fill('50100012345678');
        await page.locator('#bank_ifsc').fill('bad1');

        await page.getByRole('button', { name: 'Save', exact: true }).click();
        const confirm = page.getByRole('dialog', {
            name: 'Create this party?',
        });
        await expect(confirm).toContainText('General Information');
        await expect(confirm).toContainText('Bank Details');
        await expect(confirm).toContainText('**********5678');
        await expect(confirm).not.toContainText('Driver Information');
        await expect(confirm.locator('#remark')).toHaveCount(0);
        await confirm.getByRole('button', { name: /create|confirm/i }).click();

        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(page.locator('#bank_ifsc')).toBeFocused();
        await expect(page.getByText(/IFSC/i).first()).toBeVisible();
        await expect(page.locator('#name')).toHaveValue(
            'E2E Diesel Traders Pvt Ltd',
        );
        await expect(page.locator('#short_name')).toHaveValue('DIESELT');

        await page.locator('#bank_ifsc').fill('hdfc0001234');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await confirm.getByRole('button', { name: /create|confirm/i }).click();
        await page.waitForURL(/\/parties\/[0-9a-f-]{36}$/);

        await expect(page.getByTestId('party-name')).toHaveText(
            'E2E Diesel Traders Pvt Ltd',
        );
        const editLink = page.getByRole('link', { name: 'Edit party' });
        await expect(editLink).toBeVisible();
        const titleBox = await page.getByTestId('party-name').boundingBox();
        const editBox = await editLink.boundingBox();
        expect(editBox!.x).toBeGreaterThan(titleBox!.x + titleBox!.width);
        await expect(page.getByText('DIESELT')).toBeVisible();
        await expect(page.getByText('HDFC0001234')).toBeVisible();
        await expect(page.getByText('50100012345678')).toHaveCount(0);

        await editLink.click();
        await expect(page.locator('#bank_account_number')).toHaveValue('');
        await page.locator('#contact_person').fill('Mahesh Patil');
        await page.locator('#remark').fill('Contact person corrected');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        const editConfirm = page.getByRole('dialog', {
            name: 'Save party changes?',
        });
        await expect(editConfirm).toContainText('Contact person corrected');
        const changedRow = editConfirm
            .locator('tr')
            .filter({ hasText: 'Mahesh Patil' });
        await expect(changedRow).toContainText('Mahesh');
        await expect(changedRow).toHaveClass(/bg-warning/);
        await expect(editConfirm).not.toContainText('Bank Name');
        await editConfirm
            .getByRole('button', { name: /save|confirm/i })
            .click();
        await page.waitForURL(/\/parties\/[0-9a-f-]{36}$/);
        await expect(page.getByText('Mahesh Patil')).toBeVisible();
    });

    test('market vehicle saves with optional owner and a quick-added driver', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, OWNER);
        await page.goto('/vehicles/create');
        await page.locator('select#ownership').selectOption('market');

        await expect(page.locator('#tyre_layout_id')).toHaveCount(0);
        await expect(page.locator('#make')).toHaveCount(0);
        await expect(page.locator('#chassis_number')).toHaveCount(0);
        await expect(page.locator('#truck_type_id')).not.toHaveAttribute(
            'required',
            '',
        );

        await page.locator('#registration_number').fill('MH12E2E9001');
        await page.locator('#registration_date').fill('2024-01-10');

        await page
            .locator('div.md\\:col-span-2', {
                has: page.locator('#primary_driver_party_id-trigger'),
            })
            .getByRole('button', { name: 'Add', exact: true })
            .click();
        const sheet = page.getByRole('dialog');
        await sheet.locator('#quick-party-name').fill('E2E Market Driver');
        await sheet.locator('#quick-party-contact_number').fill('9000000088');
        await sheet.locator('#quick-party-driver_type').selectOption('market');
        await sheet.getByRole('button', { name: 'Review & create' }).click();
        await page
            .getByRole('dialog', { name: /Confirm create/ })
            .getByRole('button', { name: 'Confirm create' })
            .click();
        await expect(
            page.locator('#primary_driver_party_id-trigger'),
        ).toContainText('E2E Market Driver');

        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await page.waitForURL(/\/vehicles\/[0-9a-f-]{36}$/);
        await expect(page.getByText('MH12E2E9001').first()).toBeVisible();
        await expect(page.getByText('E2E Market Driver')).toBeVisible();
        await expect(page.getByText('Registration Date')).toBeVisible();
        await expect(
            page.getByText('Tyre Layout', { exact: true }),
        ).toHaveCount(0);
    });

    test('combobox inside a centred dialog is positioned under its trigger', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, OWNER);
        await page.goto('/vehicles');
        await page
            .locator('table tbody tr')
            .filter({ hasText: /Own/ })
            .first()
            .getByRole('link')
            .first()
            .click();
        await page
            .getByRole('button', { name: /Assign Driver|Change Driver/ })
            .click();
        const dialog = page.getByRole('dialog');
        const trigger = dialog.locator('#assign_driver_party_id-trigger');
        await trigger.click();

        const panel = page.locator('[data-searchable-combobox-panel]');
        const searchInput = panel.locator('input[type="search"]');
        await expect(searchInput).toBeFocused();
        const triggerBox = await trigger.boundingBox();
        const panelBox = await panel.boundingBox();
        expect(Math.abs(panelBox!.x - triggerBox!.x)).toBeLessThan(2);
        expect(
            Math.abs(panelBox!.y - (triggerBox!.y + triggerBox!.height + 4)),
        ).toBeLessThan(2);

        await page.keyboard.type('dri', { delay: 30 });
        await expect(searchInput).toHaveValue('dri');
        await page.keyboard.press('Escape');
        await expect(panel).toHaveCount(0);
        await expect(dialog).toBeVisible();
    });

    test('nested station quick add from the Partload challan selects State', async ({
        browser,
    }) => {
        const page = await sessionPage(browser, OWNER);
        await page.goto('/partload/challans/create');
        await page
            .locator('label[for="from_station_id"]')
            .locator('..')
            .getByRole('button', { name: '+ Add' })
            .click();
        const sheet = page.getByRole('dialog');
        await sheet.locator('#station-name').fill('E2E Vapi Point');
        await pickComboboxOption(sheet, page, 'state_id', 'Gujar', 'Gujarat');
        await pickComboboxOption(sheet, page, 'city_id', 'Surat', 'Surat');
        await sheet.getByRole('button', { name: 'Review' }).click();
        await page
            .getByRole('dialog', { name: 'Confirm Station' })
            .getByRole('button', { name: 'Create' })
            .click();
        await expect(
            page.locator('#from_station_id option:checked'),
        ).toHaveText('E2E Vapi Point');
    });

    test('station edit page selects State and City', async ({ browser }) => {
        const page = await sessionPage(browser, OWNER);
        await page.goto('/stations');
        await page
            .locator('table tbody tr')
            .filter({ hasText: 'Bhiwandi Hub' })
            .getByRole('link')
            .nth(1)
            .click();
        await expect(page.locator('#name')).toBeVisible();
        await pickComboboxOption(page, page, 'state_id', 'Gujar', 'Gujarat');
        await pickComboboxOption(page, page, 'city_id', 'Surat', 'Surat');
    });
});
