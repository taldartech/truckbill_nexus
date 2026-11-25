import { expect, test } from '@playwright/test';
import { demoCredentials, loginAs } from '../fixtures/auth';
import { enterDemoAlphaBranch } from '../fixtures/demo-workspace';

test.describe('Partload workspace smoke', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('registers and create surfaces load for authorized operator', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const { email, password } = demoCredentials();
        await loginAs(page, email, password);
        await enterDemoAlphaBranch(page);

        await page.goto('/partload/challans');
        await expect(page.getByText(/Partload Challans/i).first()).toBeVisible({
            timeout: 20_000,
        });

        await page.goto('/partload/challans/create');
        await expect(page.getByText(/Create Challan/i).first()).toBeVisible({
            timeout: 20_000,
        });
        await expect(page.getByText(/\+ Add/i).first()).toBeVisible({
            timeout: 10_000,
        });

        await page.goto('/partload/pending-loading');
        await expect(page.getByText(/Pending Loading/i).first()).toBeVisible({
            timeout: 20_000,
        });

        await page.goto('/partload/in-transit');
        await expect(page.getByText(/In Transit/i).first()).toBeVisible({
            timeout: 20_000,
        });

        await page.goto('/partload/pending-delivery');
        await expect(page.getByText(/Pending Delivery/i).first()).toBeVisible({
            timeout: 20_000,
        });

        await page.goto('/partload/main-challans');
        await expect(page.getByText(/Main Challans/i).first()).toBeVisible({
            timeout: 20_000,
        });
    });
});
