import { expect, test, type Page } from '@playwright/test';
import { demoCredentials, loginAs } from '../fixtures/auth';

async function openStatePanel(page: Page) {
    await page.locator('#state_id-trigger').click();
    const search = page.getByPlaceholder(/search state/i);
    await expect(search).toBeVisible();

    return search;
}

async function expectNoCustomValue(page: Page) {
    const panel = page.locator('[data-searchable-combobox-panel]');
    await expect(panel).toBeVisible();
    await expect(panel.getByText(/use custom value/i)).toHaveCount(0);
}

/**
 * Geographic SearchableCombobox regressions.
 *
 * Runs with the standard demo workspace gate used by other authenticated e2e
 * journeys (E2E_REQUIRE_DEMO) — not a combobox-specific optional flag.
 */
test.describe('geographic searchable combobox', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('company create: raj shows Rajasthan without custom value', async ({
        page,
    }) => {
        const { email, password } = demoCredentials();
        await loginAs(page, email, password);
        await page.goto('/management/companies/create');
        await expect(
            page.getByRole('heading', { name: /new company/i }),
        ).toBeVisible({ timeout: 15_000 });

        const search = await openStatePanel(page);
        const panel = page.locator('[data-searchable-combobox-panel]');

        await search.fill('raj');
        await expect(
            panel.getByRole('option', { name: /^Rajasthan$/i }),
        ).toBeVisible({ timeout: 10_000 });
        await expectNoCustomValue(page);

        await search.fill('Rajasthan');
        await expect(
            panel.getByRole('option', { name: /^Rajasthan$/i }),
        ).toBeVisible({ timeout: 10_000 });
        await expectNoCustomValue(page);

        await search.press('Enter');
        await expect(page.locator('#state_id-trigger')).toContainText(
            /Rajasthan/i,
        );

        await openStatePanel(page);
        await search.fill('Fantasyland');
        await expect(panel.getByText(/no matching states found/i)).toBeVisible({
            timeout: 10_000,
        });
        await expectNoCustomValue(page);
    });

    test('crm lead: custom value only after empty settled search', async ({
        page,
    }) => {
        test.skip(
            !process.env.INTERNAL_SUPER_ADMIN_EMAIL ||
                !process.env.INTERNAL_SUPER_ADMIN_PASSWORD,
            'Internal Super Admin credentials required for CRM Lead UI.',
        );

        await loginAs(
            page,
            process.env.INTERNAL_SUPER_ADMIN_EMAIL as string,
            process.env.INTERNAL_SUPER_ADMIN_PASSWORD as string,
        );
        await page.goto('/admin/crm/leads/create');
        await expect(
            page.getByRole('heading', { name: /create lead/i }),
        ).toBeVisible({ timeout: 15_000 });

        const search = await openStatePanel(page);
        const panel = page.locator('[data-searchable-combobox-panel]');

        await search.fill('raj');
        await expect(
            panel.getByRole('option', { name: /^Rajasthan$/i }),
        ).toBeVisible({ timeout: 10_000 });
        await expectNoCustomValue(page);

        await search.fill('Fantasyland');
        await expect(
            panel.getByRole('option', { name: /^Fantasyland$/i }),
        ).toBeVisible({ timeout: 10_000 });
        await expect(panel.getByText(/use custom value/i)).toHaveCount(1);
    });
});
