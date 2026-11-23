import { expect, test } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { e2eEmails, e2ePassword } from '../fixtures/company-create';
import {
    loadOwnerGlobalPanelFixture,
    partiesIndexUrl,
    partyCreateUrl,
} from '../fixtures/owner-global-panel';

test.describe('Owner global panel', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_COMPANY_CREATE !== '1',
            'Set E2E_REQUIRE_COMPANY_CREATE=1 after e2e:seed-company-create.',
        );
    });

    test('Scenarios A–E — global lists, subscription, and master visibility', async ({
        page,
    }) => {
        await loginAs(page, e2eEmails.owner, e2ePassword());

        // A — Companies → Settings → Companies (+ mobile smoke)
        await page.goto('/workspace');
        await expect(page).toHaveURL(/\/owner\/?$/);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.getByTestId('company-switcher')).toHaveCount(0);
        await page.setViewportSize({ width: 390, height: 844 });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.setViewportSize({ width: 1280, height: 720 });
        await page.getByRole('link', { name: 'All companies' }).first().click();
        await expect(page).toHaveURL(/\/owner\/companies\/?$/);
        await expect(page.getByTestId('company-switcher')).toHaveCount(0);
        await page.getByRole('link', { name: 'Settings' }).first().click();
        await expect(page).toHaveURL(/\/owner\/companies\/.+\/settings/);
        await expect(page.getByTestId('company-switcher')).toHaveCount(0);
        await page.getByRole('link', { name: 'Companies' }).first().click();
        await expect(page).toHaveURL(/\/owner\/companies\/?$/);

        // B — Branches filter + enter panel
        const fixture = loadOwnerGlobalPanelFixture();
        await page.goto('/owner/branches');
        await expect(
            page.getByRole('heading', { name: 'Branches' }),
        ).toBeVisible();
        await page.locator('#company').selectOption(fixture.shared.company_id);
        await page.getByRole('button', { name: /apply filters/i }).click();
        await expect(page.getByText('Shared HO').first()).toBeVisible();
        await page
            .getByRole('link', { name: /Enter Branch Panel/i })
            .first()
            .click();
        await expect(page).toHaveURL(/\/branch\/companies\/.+\/branches\/.+/);
        await expect(
            page.getByRole('link', { name: 'Return to Owner Panel' }),
        ).toBeVisible();
        await expect(page.getByRole('link', { name: 'Companies' })).toHaveCount(
            0,
        );
        await page.getByRole('link', { name: 'Return to Owner Panel' }).click();
        await expect(page).toHaveURL(/\/owner\/?$/);

        // C — Subscriptions list → detail → list
        await page.goto('/owner/subscriptions');
        await expect(
            page.getByRole('heading', { name: 'Subscriptions' }),
        ).toBeVisible();
        await expect(page.getByTestId('company-switcher')).toHaveCount(0);
        await page
            .getByRole('link', { name: /^Manage$/i })
            .first()
            .click();
        await expect(page).toHaveURL(/\/owner\/companies\/.+\/subscription/);
        await expect(page.getByTestId('company-switcher')).toHaveCount(0);
        await page
            .getByRole('link', { name: 'Back to Subscriptions' })
            .first()
            .click();
        await expect(page).toHaveURL(/\/owner\/subscriptions\/?$/);

        // D — Shared party, no Scope control, visible on other branch
        const sharedParty = `Shared Party ${Date.now()}`;
        await page.goto(partyCreateUrl(fixture.shared));
        await expect(
            page.getByRole('heading', { name: /Create party/i }),
        ).toBeVisible();
        await expect(
            page.getByText(/Shared across company branches/i),
        ).toBeVisible();
        await expect(page.locator('select[name="scope"]')).toHaveCount(0);
        await page.locator('#name').fill(sharedParty);
        await page.getByRole('button', { name: /^save$/i }).click();
        await expect(page).toHaveURL(/\/parties\/.+/);
        await page.goto(partiesIndexUrl(fixture.shared, 'b'));
        await expect(
            page.getByRole('link', { name: sharedParty }),
        ).toBeVisible();

        // E — Restricted party stays on creating branch
        const restrictedParty = `Restricted Party ${Date.now()}`;
        await page.goto(partyCreateUrl(fixture.restricted));
        await expect(
            page.getByText(/Restricted to this branch/i),
        ).toBeVisible();
        await expect(page.locator('select[name="scope"]')).toHaveCount(0);
        await page.locator('#name').fill(restrictedParty);
        await page.getByRole('button', { name: /^save$/i }).click();
        await expect(page).toHaveURL(/\/parties\/.+/);
        await page.goto(partiesIndexUrl(fixture.restricted, 'a'));
        await expect(
            page.getByRole('link', { name: restrictedParty }),
        ).toBeVisible();
        await page.goto(partiesIndexUrl(fixture.restricted, 'b'));
        await expect(
            page.getByRole('link', { name: restrictedParty }),
        ).toHaveCount(0);
    });

    test('Scenario F — non-owner cannot open global Owner URLs', async ({
        page,
    }) => {
        await loginAs(page, e2eEmails.internal, e2ePassword());
        const response = await page.goto('/owner/companies');
        expect(response?.status()).toBe(403);
    });
});
