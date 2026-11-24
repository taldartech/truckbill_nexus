import { expect, test } from '@playwright/test';
import { demoCredentials, loginAs } from '../../fixtures/auth';
import {
    DEMO_VIEWER_EMAIL,
    enterDemoAlphaBranch,
} from '../../fixtures/demo-workspace';

/**
 * Viewer persona smoke — expands into full role suite in Batch K.
 * Requires LocalDemoWorkspaceSeeder with LOCAL_DEMO_PASSWORD / E2E_USER_PASSWORD.
 */
test.describe('Customer Viewer persona smoke', () => {
    test.skip(
        !process.env.E2E_REQUIRE_DEMO,
        'Set E2E_REQUIRE_DEMO=1 after seeding LocalDemoWorkspaceSeeder',
    );

    test('viewer can enter branch shell without freight create actions', async ({
        page,
    }) => {
        const { password } = demoCredentials();

        test.skip(
            !password,
            'Set E2E_USER_PASSWORD or LOCAL_DEMO_PASSWORD for demo login',
        );

        await loginAs(page, DEMO_VIEWER_EMAIL, password);

        await enterDemoAlphaBranch(page);
        await expect(page.getByRole('navigation').first()).toBeVisible({
            timeout: 20_000,
        });
        await expect(
            page.getByRole('link', { name: 'Create Trip' }),
        ).toHaveCount(0);
    });
});
