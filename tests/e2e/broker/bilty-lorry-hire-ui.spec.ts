import { expect, test, type Page } from '@playwright/test';
import {
    createBrokerLrViaUi,
    loginAndEnterBrokerBranch,
} from '../fixtures/broker-workflow';

const VIEWPORTS = [
    { name: '1920x1080', width: 1920, height: 1080 },
    { name: '1440x900', width: 1440, height: 900 },
    { name: '1366x768', width: 1366, height: 768 },
    { name: '1280x800', width: 1280, height: 800 },
    { name: '1024x768', width: 1024, height: 768 },
    { name: '768x1024', width: 768, height: 1024 },
    { name: '390x844', width: 390, height: 844 },
] as const;

async function capture(
    page: Page,
    label: string,
    viewport: (typeof VIEWPORTS)[number],
): Promise<void> {
    await page.setViewportSize({
        width: viewport.width,
        height: viewport.height,
    });
    await page.waitForTimeout(250);
    await page.screenshot({
        path: `storage/visual-certification/bilty-lorry-hire/${label}-${viewport.name}.png`,
        fullPage: true,
    });
}

async function assertNoPageHorizontalOverflow(page: Page): Promise<void> {
    const overflow = await page.evaluate(() => {
        const doc = document.documentElement;
        return {
            scrollWidth: doc.scrollWidth,
            clientWidth: doc.clientWidth,
        };
    });
    expect(
        overflow.scrollWidth,
        `page scrollWidth ${overflow.scrollWidth} vs clientWidth ${overflow.clientWidth}`,
    ).toBeLessThanOrEqual(overflow.clientWidth + 2);
}

test.describe('Bilty UI + Lorry Hire financial workflow', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('journey A — readable Bilty Register and action buttons', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await loginAndEnterBrokerBranch(page);
        await page.goto('/lorry-receipts');
        await expect(
            page.getByRole('heading', { name: 'Bilty Register' }),
        ).toBeVisible();
        await expect(
            page.getByRole('columnheader', { name: 'Bilty No' }),
        ).toBeVisible();
        await expect(
            page.getByRole('columnheader', { name: 'Truck No' }),
        ).toBeVisible();
        await expect(
            page.getByRole('columnheader', { name: 'Actions' }),
        ).toBeVisible();
        await assertNoPageHorizontalOverflow(page);

        for (const viewport of VIEWPORTS) {
            await capture(page, 'bilty-register', viewport);
            await assertNoPageHorizontalOverflow(page);
        }
    });

    test('journey B/C — optional goods description + independent transporter freight', async ({
        page,
    }) => {
        test.setTimeout(360_000);
        await loginAndEnterBrokerBranch(page);

        await page.goto('/lorry-receipts/create');
        await expect(
            page.getByRole('heading', { name: 'Create Bilty' }),
        ).toBeVisible();
        await expect(page.getByText('Goods Description').first()).toBeVisible();
        await expect(page.getByText('(optional)').first()).toBeVisible();
        await expect(page.getByText('Wt./Unit (KG)').first()).toBeVisible();
        await expect(
            page.getByText('Actual Weight (MT)').first(),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: 'Remove' })).toHaveCount(
            0,
        );
        await capture(page, 'bilty-create-goods', VIEWPORTS[0]);
        await capture(page, 'bilty-create-goods', VIEWPORTS[6]);

        const lr = await createBrokerLrViaUi(page, '');
        expect(lr.lrNumber).toBeTruthy();

        await page.goto(lr.lrPath);
        await expect(
            page.getByText('Loading Station', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText('Unloading Station', { exact: true }),
        ).toBeVisible();
        await capture(page, 'bilty-parties-route', VIEWPORTS[0]);

        await page.getByRole('tab', { name: 'Actions' }).click();
        await page.getByRole('link', { name: 'Manage Lorry Hire' }).click();
        await expect(
            page.getByRole('heading', { name: 'Create Lorry Hire' }),
        ).toBeVisible();
        await page.locator('#rate_type_0').selectOption('per_mt');
        // Loading weight UI is MT (8.000 MT ≡ 8000 KG).
        await page.locator('#loading_weight_0').fill('8');
        await page.locator('#rate_0').fill('7500');
        await expect(page.getByText('Financial summary')).toBeVisible();
        await expect(page.getByText(/60000\.00|₹60000/).first()).toBeVisible({
            timeout: 5_000,
        });
        await capture(page, 'lorry-hire-create', VIEWPORTS[0]);
        await capture(page, 'lorry-hire-create', VIEWPORTS[6]);

        await page.getByRole('button', { name: 'Create Lorry Hire' }).click();
        await expect(page).toHaveURL(/\/broker\/lorry-hires\/[0-9a-f-]+$/, {
            timeout: 30_000,
        });

        await expect(page.getByText('60000.00').first()).toBeVisible();
        await expect(page.getByText('58800.00').first()).toBeVisible();
        await page.getByRole('tab', { name: 'Freight lines' }).click();
        await expect(page.getByText(/Per MT|Per mt/i).first()).toBeVisible();
        await capture(page, 'lorry-hire-detail', VIEWPORTS[0]);
        await capture(page, 'lorry-hire-detail', VIEWPORTS[3]);

        await page.goto('/lorry-receipts');
        await expect(page.getByText(lr.lrNumber!).first()).toBeVisible();
    });
});
