import { expect, test } from '@playwright/test';
import { demoCredentials, loginAs } from '../../fixtures/auth';
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
        const { password } = demoCredentials();

        test.skip(
            !password,
            'Set E2E_USER_PASSWORD or LOCAL_DEMO_PASSWORD for demo login',
        );

        await loginAs(page, DEMO_OPERATOR_EMAIL, password);

        await enterDemoAlphaBranch(page);
        await expect(page.getByRole('navigation').first()).toBeVisible({
            timeout: 20_000,
        });
    });
});
