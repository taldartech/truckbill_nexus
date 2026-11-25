import { expect, test } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { e2eEmails, e2ePassword } from '../fixtures/company-create';

/**
 * Master-only State/City combobox regression.
 * Requires a running app and an owner who can open company create.
 */
test.describe('geographic searchable combobox', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_GEO_COMBOBOX !== '1',
            'Set E2E_REQUIRE_GEO_COMBOBOX=1 to run against a live app.',
        );
    });

    test('company create state search never offers custom values', async ({
        page,
    }) => {
        await loginAs(page, e2eEmails.owner, e2ePassword());
        await page.goto('/management/companies/create');
        await expect(
            page.getByRole('heading', { name: /new company/i }),
        ).toBeVisible({
            timeout: 15_000,
        });

        await page.locator('#state_id-trigger').click();
        const search = page.getByPlaceholder(/search state/i);
        await expect(search).toBeVisible();

        await search.fill('ra');
        const panel = page.locator('[data-searchable-combobox-panel]');
        await expect(panel).toBeVisible();
        await expect(panel.getByText(/use custom value/i)).toHaveCount(0);

        await search.fill('raj');
        await expect(
            panel.getByRole('option', { name: /^Rajasthan$/i }),
        ).toBeVisible({ timeout: 10_000 });
        await expect(panel.getByText(/use custom value/i)).toHaveCount(0);

        // Panel is portaled to body — not clipped inside FormSection overflow.
        const box = await panel.boundingBox();
        expect(box).not.toBeNull();
        if (box) {
            expect(box.height).toBeGreaterThan(40);
        }

        await search.fill('Fantasyland');
        await expect(panel.getByText(/no matching states found/i)).toBeVisible({
            timeout: 10_000,
        });
        await expect(panel.getByText(/use custom value/i)).toHaveCount(0);
    });
});
