import { expect, test, type Page } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import {
    DEMO_COMPANY_NAME,
    DEMO_OWNER_EMAIL,
    enterDemoAlphaBranch,
} from '../fixtures/demo-workspace';

async function assertInBranchPanel(page: Page): Promise<void> {
    await expect(page).toHaveURL(
        /\/(operations|lorry-receipts|parties|vehicles)/,
    );
    await expect(
        page.getByRole('button', { name: /Return to Owner Portal/i }),
    ).toBeVisible({ timeout: 15_000 });
}

async function assertInOwnerPanel(page: Page): Promise<void> {
    await expect(page).toHaveURL(/\/management/);
    await expect(
        page
            .getByRole('heading', { name: /Owner|Companies|Dashboard/i })
            .first(),
    ).toBeVisible({ timeout: 15_000 });
}

test.describe('Branch workspace session persistence', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('owner stays in branch after owner-route style navigation and refresh', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const password = process.env.E2E_USER_PASSWORD ?? 'password';

        await loginAs(page, DEMO_OWNER_EMAIL, password);
        await enterDemoAlphaBranch(page);
        await assertInBranchPanel(page);

        await page.goto('/lorry-receipts');
        await expect(
            page.getByRole('heading', { name: 'Bilty Register' }),
        ).toBeVisible({ timeout: 20_000 });
        await assertInBranchPanel(page);

        // Hover Owner-adjacent chrome that previously prefetched /management.
        const returnBtn = page.getByRole('button', {
            name: /Return to Owner Portal/i,
        });
        await returnBtn.hover();
        await page.waitForTimeout(750);

        await page.goto('/operations');
        await expect(page).toHaveURL(/\/operations/);
        await assertInBranchPanel(page);

        await page.reload();
        await assertInBranchPanel(page);

        await page.screenshot({
            path: 'storage/visual-certification/session/branch-persist-after-refresh.png',
            fullPage: true,
        });

        await returnBtn.click();
        await assertInOwnerPanel(page);
        await expect(page.getByText(DEMO_COMPANY_NAME).first()).toBeVisible();
    });

    test('multiple tabs retain branch context without false revocation', async ({
        context,
        page,
    }) => {
        test.setTimeout(180_000);
        const password = process.env.E2E_USER_PASSWORD ?? 'password';

        await loginAs(page, DEMO_OWNER_EMAIL, password);
        await enterDemoAlphaBranch(page);
        await page.goto('/lorry-receipts');
        await expect(
            page.getByRole('heading', { name: 'Bilty Register' }),
        ).toBeVisible({ timeout: 20_000 });

        const tab2 = await context.newPage();
        await tab2.goto('/operations');
        await expect(tab2).toHaveURL(/\/operations/, { timeout: 20_000 });
        await expect(
            tab2.getByRole('button', { name: /Return to Owner Portal/i }),
        ).toBeVisible({ timeout: 15_000 });

        await page.goto('/parties');
        await expect(page).toHaveURL(/\/parties/, { timeout: 20_000 });
        await expect(
            page.getByRole('button', { name: /Return to Owner Portal/i }),
        ).toBeVisible();

        await tab2.close();
    });
});
