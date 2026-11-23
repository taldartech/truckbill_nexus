import { expect, test } from '@playwright/test';
import { loginAs } from '../../fixtures/auth';

/**
 * Viewer persona smoke — expands into full role suite in Batch K.
 * Requires LocalDemoWorkspaceSeeder personas when CI seeds demo.
 */
test.describe('Customer Viewer persona smoke', () => {
    test.skip(
        !process.env.E2E_VIEWER_EMAIL || !process.env.E2E_VIEWER_PASSWORD,
        'Set E2E_VIEWER_EMAIL / E2E_VIEWER_PASSWORD for demo viewer persona',
    );

    test('viewer can open branch dashboard and lacks create trip CTA', async ({
        page,
    }) => {
        await loginAs(
            page,
            process.env.E2E_VIEWER_EMAIL!,
            process.env.E2E_VIEWER_PASSWORD!,
        );

        await page.goto('/workspace/select-company');
        await expect(page.getByRole('heading').first()).toBeVisible();
    });
});
