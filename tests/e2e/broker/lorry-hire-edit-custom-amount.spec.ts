import { expect, test } from '@playwright/test';
import {
    loginAndEnterBrokerBranch,
    openGoodHiresRegister,
} from '../fixtures/broker-workflow';

test.describe('Lorry Hire Custom Amounts (legacy)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAndEnterBrokerBranch(page);
    });

    test('edit adds signed custom amount rows without overriding freight', async ({
        page,
    }) => {
        await openGoodHiresRegister(page);
        await page.getByRole('link', { name: /Edit/i }).first().click();

        await expect(page.locator('#lorry-hire-form')).toBeVisible();

        await page.getByRole('button', { name: 'Add amount' }).click();
        await page.locator('#custom_description_0').fill('Loading surcharge');
        await page.locator('#custom_amount_row_0').fill('500');

        await expect(page.getByText('Custom Amounts').first()).toBeVisible();
        await expect(page.getByText('₹500.00').first()).toBeVisible();
    });
});
