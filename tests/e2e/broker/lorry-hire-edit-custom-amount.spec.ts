import { expect, test, type Page } from '@playwright/test';
import {
    createBrokerLrViaUi,
    loginAndEnterBrokerBranch,
} from '../fixtures/broker-workflow';

async function capture(page: Page, label: string): Promise<void> {
    await page.screenshot({
        path: `storage/visual-certification/lorry-hire-edit/${label}.png`,
        fullPage: true,
    });
}

test.describe('Lorry Hire Custom Amount and Edit', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('create with Custom Amount, then edit it with a remark and change review', async ({
        page,
    }) => {
        test.setTimeout(360_000);
        await loginAndEnterBrokerBranch(page);

        const lr = await createBrokerLrViaUi(page, 'E2E custom amount goods');
        expect(lr.lrNumber).toBeTruthy();

        await page
            .getByRole('link', { name: /Create Lorry Hire|Manage Lorry Hire/i })
            .first()
            .click();
        await expect(
            page.getByRole('heading', { name: 'Create Lorry Hire' }),
        ).toBeVisible({ timeout: 15_000 });

        // ₹7500/MT × inherited 8 MT charged weight = ₹60,000 calculated freight.
        await page.locator('#rate_type_0').selectOption('per_charged_weight');
        await page.locator('#rate_0').fill('7500');
        const calculated = page.getByTestId('calculated_freight_0');
        const effective = page.getByTestId('effective_freight_0');
        await expect(calculated).toHaveText(/60,000\.00/);
        await expect(effective).toHaveText(/60,000\.00/);

        await page.locator('#custom_enabled_0').click();
        await page.locator('#custom_amount_0').fill('55000');
        await expect(effective).toHaveText(/55,000\.00/);
        await expect(calculated).toHaveText(/60,000\.00/);

        await page.locator('#custom_enabled_0').click();
        await expect(page.locator('#custom_amount_0')).toHaveCount(0);
        await expect(effective).toHaveText(/60,000\.00/);

        await page.locator('#custom_enabled_0').click();
        await page.locator('#custom_amount_0').fill('55000');
        await expect(effective).toHaveText(/55,000\.00/);
        await capture(page, 'create-custom-amount');

        await page.getByRole('button', { name: 'Create Lorry Hire' }).click();
        const createDialog = page.getByRole('dialog');
        await expect(
            createDialog.getByRole('heading', {
                name: 'Create this Lorry Hire?',
            }),
        ).toBeVisible({ timeout: 5_000 });
        await createDialog
            .getByRole('button', { name: 'Create Lorry Hire' })
            .click();
        await expect(page).toHaveURL(/\/broker\/lorry-hires\/[0-9a-f-]+$/, {
            timeout: 30_000,
        });
        const hirePath = new URL(page.url()).pathname;
        const title = await page.getByRole('heading', { level: 1 }).innerText();
        const hireNumber = title.replace(/^Lorry Hire\s+/i, '').trim();
        await expect(page.getByText(/55,?000\.00/).first()).toBeVisible();

        await page.getByRole('link', { name: 'Edit Lorry Hire' }).click();
        await expect(
            page.getByRole('heading', { name: /Edit Lorry Hire/ }),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('#custom_amount_0')).toHaveValue(
            /^55000(\.00)?$/,
        );

        await page.locator('#custom_amount_0').fill('57000');
        await expect(effective).toHaveText(/57,000\.00/);
        await capture(page, 'edit-form');

        await page.getByRole('button', { name: 'Review changes' }).click();
        await expect(
            page.getByText('Enter the reason for editing this Lorry Hire.'),
        ).toBeVisible();

        await page
            .locator('#edit_remark')
            .fill('E2E transporter agreed revised lump sum');
        await page.getByRole('button', { name: 'Review changes' }).click();
        const editDialog = page.getByRole('dialog');
        await expect(
            editDialog.getByRole('heading', {
                name: 'Save Lorry Hire changes?',
            }),
        ).toBeVisible({ timeout: 15_000 });
        await expect(editDialog.getByText(/55,?000\.00/).first()).toBeVisible();
        await expect(editDialog.getByText(/57,?000\.00/).first()).toBeVisible();
        await expect(
            editDialog.getByText('E2E transporter agreed revised lump sum'),
        ).toBeVisible();
        await capture(page, 'edit-confirmation');
        await editDialog.getByRole('button', { name: 'Save changes' }).click();

        await expect(page).toHaveURL(new RegExp(`${hirePath}$`), {
            timeout: 30_000,
        });
        await expect(page.getByText('Lorry Hire updated.').first()).toBeVisible(
            { timeout: 15_000 },
        );
        await expect(page.getByText('Edit History').first()).toBeVisible();
        await expect(
            page.getByText('E2E transporter agreed revised lump sum').first(),
        ).toBeVisible();
        await capture(page, 'show-after-edit');

        await page.goto('/broker/lorry-hires');
        await expect(
            page.getByRole('link', { name: `Edit lorry hire ${hireNumber}` }),
        ).toBeVisible({ timeout: 20_000 });

        await page.goto(lr.lrPath);
        await expect(
            page.getByRole('link', { name: `Edit Lorry Hire ${hireNumber}` }),
        ).toBeVisible({ timeout: 20_000 });
    });
});
