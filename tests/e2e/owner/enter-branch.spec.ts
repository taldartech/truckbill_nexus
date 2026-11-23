import { expect, test } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { e2eEmails, e2ePassword } from '../fixtures/company-create';
import { loadOwnerGlobalPanelFixture } from '../fixtures/owner-global-panel';

test.describe('Enter Branch Panel', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_COMPANY_CREATE !== '1',
            'Set E2E_REQUIRE_COMPANY_CREATE=1 after e2e:seed-company-create.',
        );
    });

    test('Owner Branches → Enter Branch Panel opens selected Branch Dashboard', async ({
        page,
    }) => {
        const consoleErrors: string[] = [];
        page.on('console', (message) => {
            if (message.type() === 'error') {
                consoleErrors.push(message.text());
            }
        });

        await loginAs(page, e2eEmails.owner, e2ePassword());
        const fixture = loadOwnerGlobalPanelFixture();

        await page.goto('/management/branches');
        await expect(
            page.getByRole('heading', { name: 'Branches' }),
        ).toBeVisible();
        await page.locator('#company').selectOption(fixture.shared.company_id);
        await page.getByRole('button', { name: /apply/i }).click();

        const enterButtons = page.getByRole('button', {
            name: 'Enter Branch Panel',
        });
        await expect(enterButtons.first()).toBeVisible({ timeout: 15_000 });
        await expect(enterButtons).toHaveCount(2);

        const contextResponse = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                response.url().includes('/workspace/context/companies/') &&
                response.url().includes('/branches/'),
        );
        await enterButtons.first().click();
        const posted = await contextResponse;
        expect(posted.status()).toBeLessThan(400);

        await expect(page).toHaveURL(/\/branch\/?$/);
        const heading = page.getByRole('heading', { level: 1 });
        await expect(heading).toBeVisible();
        const firstBranchName = (await heading.textContent())?.trim() ?? '';
        expect(firstBranchName.length).toBeGreaterThan(0);
        await expect(
            page
                .getByText(fixture.shared.company_name, { exact: true })
                .first(),
        ).toBeVisible();
        await expect(page.getByText(/^Owner Panel$/i)).toHaveCount(0);
        await expect(
            page.getByRole('link', { name: 'Return to Owner Portal' }),
        ).toHaveCount(1);
        await expect(
            page.getByRole('link', { name: 'Return to Owner Portal' }),
        ).toBeVisible();
        await expect(page.getByTestId('company-switcher')).toHaveCount(0);
        await expect(page.getByTestId('branch-context-readonly')).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Party Master' }),
        ).toBeVisible();

        // Return and enter the other shared branch.
        await page
            .getByRole('link', { name: 'Return to Owner Portal' })
            .click();
        await expect(page).toHaveURL(/\/management\/?$/);

        await page.goto('/management/branches');
        await page.locator('#company').selectOption(fixture.shared.company_id);
        await page.getByRole('button', { name: /apply/i }).click();
        await expect(enterButtons.nth(1)).toBeVisible();
        await enterButtons.nth(1).click();
        await expect(page).toHaveURL(/\/branch\/?$/);
        const secondBranchName =
            (
                await page.getByRole('heading', { level: 1 }).textContent()
            )?.trim() ?? '';
        expect(secondBranchName.length).toBeGreaterThan(0);
        expect(secondBranchName).not.toBe(firstBranchName);

        // Mobile viewport — Enter Branch icon still reachable from Branches list.
        await page.goto('/management/branches');
        await page.setViewportSize({ width: 390, height: 844 });
        await page.locator('#company').selectOption(fixture.shared.company_id);
        await page.getByRole('button', { name: /apply/i }).click();
        await expect(
            page.getByRole('button', { name: 'Enter Branch Panel' }).first(),
        ).toBeVisible();

        const inertiaErrors = consoleErrors.filter(
            (text) =>
                !text.includes('Download the React DevTools') &&
                !text.includes('Browser logger active'),
        );
        expect(inertiaErrors).toEqual([]);
    });
});
