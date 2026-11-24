import { expect, test } from '@playwright/test';
import {
    DEMO_OPERATOR_EMAIL,
    enterDemoAlphaBranch,
} from '../../fixtures/demo-workspace';

/**
 * Operator persona smoke — credentials via env only (no committed secrets).
 * Requires LocalDemoWorkspaceSeeder with LOCAL_DEMO_PASSWORD / E2E_USER_PASSWORD.
 */
test.describe('Customer Operator persona smoke', () => {
    test.skip(
        !process.env.E2E_REQUIRE_DEMO,
        'Set E2E_REQUIRE_DEMO=1 after seeding LocalDemoWorkspaceSeeder',
    );

    test('operator can open branch operations shell', async ({ page }) => {
        const password =
            process.env.E2E_USER_PASSWORD ??
            process.env.LOCAL_DEMO_PASSWORD ??
            '';

        test.skip(
            !password,
            'Set E2E_USER_PASSWORD or LOCAL_DEMO_PASSWORD for demo login',
        );

        await page.goto('/login', { waitUntil: 'networkidle' });
        await page.locator('#email').fill(DEMO_OPERATOR_EMAIL);
        await page.locator('#password').fill(password);
        await page.getByRole('button', { name: /log in|sign in/i }).click();
        await expect(page).not.toHaveURL(/\/login$/, { timeout: 15_000 });

        await enterDemoAlphaBranch(page);
        await expect(page.getByRole('navigation').first()).toBeVisible({
            timeout: 20_000,
        });
    });
});
