import { expect, test } from '@playwright/test';
import { demoCredentials, loginAs } from '../fixtures/auth';
import { enterDemoAlphaBranch } from '../fixtures/demo-workspace';

test.describe('Accounting workspace smoke', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('Day Book, Ledgers, and core reports load', async ({ page }) => {
        test.setTimeout(180_000);
        const { email, password } = demoCredentials();
        await loginAs(page, email, password);
        await enterDemoAlphaBranch(page);

        await page.goto('/accounting/vouchers');
        await expect(
            page.getByRole('heading', { name: /Day Book/i }),
        ).toBeVisible({ timeout: 20_000 });

        await page.goto('/accounting/vouchers/create');
        await expect(
            page.getByRole('heading', { name: /Voucher|Create/i }).first(),
        ).toBeVisible({ timeout: 20_000 });

        await page.goto('/accounting/ledgers');
        await expect(
            page.getByRole('heading', { name: /Ledger/i }).first(),
        ).toBeVisible({ timeout: 20_000 });

        await page.goto('/accounting/reports/trial-balance');
        await expect(
            page.getByRole('heading', { name: /Trial Balance/i }),
        ).toBeVisible({ timeout: 20_000 });

        await page.goto('/accounting/reports/profit-and-loss');
        await expect(
            page.getByRole('heading', { name: /Profit|Loss|P&L/i }).first(),
        ).toBeVisible({ timeout: 20_000 });

        await page.goto('/accounting/reports/balance-sheet');
        await expect(
            page.getByRole('heading', { name: /Balance Sheet/i }),
        ).toBeVisible({ timeout: 20_000 });

        await page.goto('/accounting/reports/cash-book');
        await expect(
            page.getByRole('heading', { name: /Cash Book/i }),
        ).toBeVisible({ timeout: 20_000 });

        await page.goto('/accounting/reports/outstanding');
        await expect(
            page.getByRole('heading', { name: /Outstanding/i }).first(),
        ).toBeVisible({ timeout: 20_000 });
    });
});
