import { expect, test } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { e2eEmails, e2ePassword } from '../fixtures/company-create';
import {
    branchContextUrl,
    loadOwnerGlobalPanelFixture,
    partyCreateUrl,
} from '../fixtures/owner-global-panel';

test.describe('LR context resolution and Party Master', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_COMPANY_CREATE !== '1',
            'Set E2E_REQUIRE_COMPANY_CREATE=1 after e2e:seed-company-create.',
        );
    });

    test('create LR has no Workflow Context dropdown after branch entry', async ({
        page,
    }) => {
        await loginAs(page, e2eEmails.owner, e2ePassword());
        const fixture = loadOwnerGlobalPanelFixture();

        await page.goto(branchContextUrl(fixture.shared, 'a'), {
            waitUntil: 'networkidle',
        });
        // Context POST via goto may 405 — use Branches enter when needed.
        await page.goto('/management/branches');
        await page.locator('#company').selectOption(fixture.shared.company_id);
        await page.getByRole('button', { name: /apply/i }).click();
        await page
            .getByRole('button', { name: 'Enter Branch Panel' })
            .first()
            .click();
        await expect(page).toHaveURL(/\/operations\/?$/);

        await page.goto('/lorry-receipts');
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(
            'Lorry Receipts',
        );
        // Never a full-page chooser.
        await expect(
            page.getByRole('heading', { name: /choose workflow context/i }),
        ).toHaveCount(0);

        // Never a Broker/OwnFleet/Partload Create LR dropdown.
        await expect(page.getByTestId('create-lr-menu')).toHaveCount(0);
        await expect(page.getByTestId('create-lr-broker')).toHaveCount(0);
        await expect(page.getByTestId('create-lr-own_fleet')).toHaveCount(0);
        await expect(page.getByTestId('create-lr-partload')).toHaveCount(0);

        const direct = page.getByTestId('create-lr-direct');
        await expect(direct).toBeVisible();
        await direct.click();

        await expect(page).toHaveURL(/\/lorry-receipts\/create/);
        await expect(page.getByLabel(/^workflow context$/i)).toHaveCount(0);
        await expect(
            page.locator('select[name="workflow_context"]'),
        ).toHaveCount(0);
        await expect(
            page.getByRole('heading', { name: /choose workflow context/i }),
        ).toHaveCount(0);
        // Shared truck form exposes vehicle; classification is not a module chooser.
        await expect(page.locator('select[name="vehicle_id"]')).toBeVisible();

        // Deep link without origin must open the shared form (no chooser page).
        await page.goto('/lorry-receipts/create');
        await expect(
            page.getByRole('heading', { name: /choose workflow context/i }),
        ).toHaveCount(0);
        await expect(page.locator('select[name="vehicle_id"]')).toBeVisible();
    });

    test('party create page loads without duplicated company badges', async ({
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
        await expect(page.getByText(/shared data/i)).toHaveCount(0);
        await expect(page.getByLabel(/^Name$/i)).toBeVisible();
        await expect(page.getByText(/Party roles/i)).toBeVisible();
    });
});
