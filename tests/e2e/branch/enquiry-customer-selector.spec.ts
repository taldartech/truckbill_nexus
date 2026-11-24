import { expect, test } from '@playwright/test';
import { loginAndEnterBrokerBranch } from '../fixtures/broker-workflow';

test.describe('Enquiry Customer selector eligibility', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('create enquiry offers Customer label and searchable eligible parties only', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await loginAndEnterBrokerBranch(page);

        await page.goto('/enquiries/create');
        await expect(
            page.getByRole('heading', { name: 'Create enquiry' }),
        ).toBeVisible({ timeout: 20_000 });

        await expect(
            page.getByText('Customer', { exact: true }).first(),
        ).toBeVisible();
        await expect(page.getByLabel('Search Customer')).toBeVisible();

        const customerSelect = page.locator('#customer_party_id');
        await expect(customerSelect).toBeVisible();

        const optionLabels = await customerSelect
            .locator('option')
            .allTextContents();
        const normalized = optionLabels.map((label) =>
            label.trim().toLowerCase(),
        );

        for (const label of normalized) {
            if (label === '' || label.startsWith('select ')) {
                continue;
            }
            expect(label).not.toMatch(/^transporter only/);
            expect(label).not.toMatch(/^driver only/);
            expect(label).not.toMatch(/^pump vendor/);
            expect(label).not.toMatch(/^general vendor/);
        }

        await page.screenshot({
            path: 'storage/visual-certification/enquiry/customer-selector-1920x1080.png',
            fullPage: true,
        });
    });
});
