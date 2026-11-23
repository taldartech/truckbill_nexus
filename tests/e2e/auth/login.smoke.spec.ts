import { expect, test } from '@playwright/test';
import { demoCredentials, loginAs } from '../fixtures/auth';

/**
 * Smoke: login page renders and rejects invalid credentials.
 * Full authenticated journeys require seeded demo personas.
 */
test.describe('authentication smoke', () => {
    test('login page is reachable', async ({ page }) => {
        await page.goto('/login');
        await expect(page.locator('#email')).toBeVisible();
        await expect(page.locator('#password')).toBeVisible();
    });

    test('invalid credentials stay on login', async ({ page }) => {
        await page.goto('/login');
        await page.locator('#email').fill('nobody@example.com');
        await page.locator('#password').fill('definitely-wrong');
        await page.getByRole('button', { name: /log in|sign in/i }).click();
        await expect(page).toHaveURL(/\/login/);
    });

    test('demo owner can authenticate when seeded', async ({ page }) => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder for authenticated journeys.',
        );

        const { email, password } = demoCredentials();
        await loginAs(page, email, password);
        await expect(page).toHaveURL(/\/(workspace|owner|dashboard)/);
    });
});
