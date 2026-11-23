import { expect, test } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import {
    companySwitcherTrigger,
    e2eEmails,
    e2ePassword,
    expectOwnerDashboard,
    fillCompanyCreateForm,
    uniqueCompanyIds,
} from '../fixtures/company-create';

/**
 * Company creation discoverability + security journeys (D7).
 * Requires: `php artisan e2e:seed-company-create` and a running app.
 */
test.describe('company creation accessibility', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_COMPANY_CREATE !== '1',
            'Set E2E_REQUIRE_COMPANY_CREATE=1 after e2e:seed-company-create.',
        );
    });

    test('Scenario A — existing owner creates company from Owner Dashboard', async ({
        page,
    }) => {
        const password = e2ePassword();
        await loginAs(page, e2eEmails.owner, password);

        await page.goto('/workspace');
        await page.waitForURL(/\/(owner|workspace)/);

        const createLink = page.getByRole('link', {
            name: /create (a new )?company/i,
        });
        await expect(createLink.first()).toBeVisible({ timeout: 15_000 });
        await createLink.first().click();

        await expect(page).toHaveURL(/\/owner\/companies\/create/);
        const ids = uniqueCompanyIds('E2E Dash Co');
        await fillCompanyCreateForm(page, ids);
        await expectOwnerDashboard(page);
        await expect(page.getByRole('heading', { level: 1 })).toContainText(
            ids.name,
        );

        await companySwitcherTrigger(page).click();
        await expect(page.getByText(ids.name).first()).toBeVisible();
    });

    test('Scenario B — customer without company creates from no-access', async ({
        page,
    }) => {
        const password = e2ePassword();
        await loginAs(page, e2eEmails.noCompany, password);

        await page.goto('/workspace');
        await page.waitForURL(/no-access|companies\/create|owner|workspace/);

        if (!page.url().includes('/owner/companies/create')) {
            if (!page.url().includes('no-access')) {
                await page.goto('/workspace/no-access');
            }
            const createCta = page.getByRole('link', {
                name: /create your company/i,
            });
            await expect(createCta).toBeVisible({ timeout: 15_000 });
            await createCta.click();
        }

        await expect(page).toHaveURL(/\/owner\/companies\/create/);
        const ids = uniqueCompanyIds('E2E First Co');
        await fillCompanyCreateForm(page, ids);
        await expectOwnerDashboard(page);
        await expect(page.getByRole('heading', { level: 1 })).toContainText(
            ids.name,
        );
    });

    test('Scenario C — create new company from company switcher', async ({
        page,
    }) => {
        const password = e2ePassword();
        await loginAs(page, e2eEmails.owner, password);

        await page.goto('/workspace');
        await page.waitForURL(/\/owner\//);

        await companySwitcherTrigger(page).click();
        const switcherCreate = page
            .locator('[data-slot="dropdown-menu-content"]')
            .filter({ hasText: 'Create new company' })
            .getByRole('link', { name: /create new company from switcher/i })
            .first();
        await expect(switcherCreate).toBeAttached({ timeout: 10_000 });
        const createHref = await switcherCreate.getAttribute('href');
        expect(createHref).toMatch(/\/owner\/companies\/create/);
        // Long company lists can push the menu item outside the viewport; navigate
        // via the switcher-exposed href after proving the menu item is present.
        await page.goto(createHref!);

        await expect(page).toHaveURL(/\/owner\/companies\/create/);
        const ids = uniqueCompanyIds('E2E Switcher Co');
        await fillCompanyCreateForm(page, ids);
        await expectOwnerDashboard(page);

        await companySwitcherTrigger(page).click();
        await expect(
            page
                .locator('[data-slot="dropdown-menu-content"]')
                .getByText(ids.name)
                .first(),
        ).toBeAttached();
    });

    test('Scenario D — internal identity cannot access customer company create', async ({
        page,
    }) => {
        const password = e2ePassword();
        await loginAs(page, e2eEmails.internal, password);

        const response = await page.goto('/owner/companies/create');
        expect(response?.status()).toBe(403);
        await expect(
            page.getByText(
                /only active customer identities may create companies/i,
            ),
        ).toBeVisible();
    });
});
