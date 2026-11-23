import { expect, test } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { e2eEmails, e2ePassword } from '../fixtures/company-create';
import {
    loadOwnerGlobalPanelFixture,
    partyCreateUrl,
} from '../fixtures/owner-global-panel';

test.describe('Party Master transporter TDS panel', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_COMPANY_CREATE !== '1',
            'Set E2E_REQUIRE_COMPANY_CREATE=1 after e2e:seed-company-create.',
        );
    });

    test('selecting transporter shows TDS panel and live preview region', async ({
        page,
    }) => {
        await loginAs(page, e2eEmails.owner, e2ePassword());
        const fixture = loadOwnerGlobalPanelFixture();

        await page.goto('/management/branches');
        await page.locator('#company').selectOption(fixture.shared.company_id);
        await page.getByRole('button', { name: /apply/i }).click();
        await page
            .getByRole('button', { name: 'Enter Branch Panel' })
            .first()
            .click();
        await expect(page).toHaveURL(/\/operations\/?$/);

        await page.goto(partyCreateUrl());
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(
            'Create party',
        );

        await expect(page.getByTestId('transporter-tds-panel')).toHaveCount(0);

        await page.getByRole('checkbox', { name: /transporter/i }).click();

        const tdsPanel = page.getByTestId('transporter-tds-panel');
        await expect(tdsPanel).toBeVisible();
        await expect(page.getByLabel(/declaration type/i)).toBeVisible();
        await expect(page.getByLabel(/^pan status/i)).toBeVisible();
        await expect(page.getByLabel(/goods vehicles count/i)).toBeVisible();
        await expect(page.getByText(/declaration affirmed/i)).toBeVisible();

        const preview = page.getByTestId('tds-preview-panel');
        await expect(preview).toBeVisible();
        await expect(preview.getByText(/live tds preview/i)).toBeVisible();

        await page.getByLabel(/override applicable tds/i).check();
        await expect(page.getByLabel(/^tds %/i)).toBeVisible();
        await expect(page.getByLabel(/tds adjustment remark/i)).toBeVisible();
    });
});
