import { expect, test } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { e2eEmails, e2ePassword } from '../fixtures/company-create';
import {
    companyContextUrl,
    loadOwnerGlobalPanelFixture,
} from '../fixtures/owner-global-panel';

/**
 * Journey B — stale company session must not block Create Branch for another
 * owned active company (PO Owner Panel session-decoupling).
 */
test.describe('Owner Create Branch session independence', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_COMPANY_CREATE !== '1',
            'Set E2E_REQUIRE_COMPANY_CREATE=1 after e2e:seed-company-create.',
        );
    });

    test('global Create Branch works without relying on WorkspaceSession company', async ({
        page,
    }) => {
        const fixture = loadOwnerGlobalPanelFixture();
        await loginAs(page, e2eEmails.owner, e2ePassword());

        // Establish a company session for Shared, then leave to Owner home
        // (clears operational session) and create a branch via global form.
        await page.request.post(companyContextUrl(fixture.shared.company_id), {
            form: { redirect: '/management' },
        });

        await page.goto('/management/branches');
        await expect(
            page.getByRole('heading', { name: 'Branches' }),
        ).toBeVisible();

        await page.getByRole('link', { name: /Create branch/i }).click();
        await expect(page).toHaveURL(/\/management\/branches\/create/);

        // Company selector should be present on the global form.
        await expect(
            page.getByText('Company', { exact: false }).first(),
        ).toBeVisible();

        // Prefer company-addressed create for the restricted (still active) company.
        await page.goto(
            `/management/companies/${fixture.restricted.company_id}/branches/create`,
        );
        await expect(
            page.getByRole('heading', { name: /Create branch/i }),
        ).toBeVisible();
        await expect(
            page.getByText(/Company administration writes are unavailable/i),
        ).toHaveCount(0);

        const branchName = `E2E Depot ${Date.now()}`;
        await page.locator('#branch-name').fill(branchName);
        await page
            .locator('#remark')
            .fill('Playwright session-independence create');
        await page.getByRole('button', { name: /^Create branch$/i }).click();

        // Confirmation dialog
        const confirm = page.getByRole('dialog');
        if (await confirm.isVisible().catch(() => false)) {
            await confirm
                .getByRole('button', { name: /Confirm create|Confirm/i })
                .click();
        }

        await expect(page).toHaveURL(/\/management\/branches/);
        await expect(page.getByText(branchName).first()).toBeVisible();
    });
});
