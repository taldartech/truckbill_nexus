import { expect, test } from '@playwright/test';
import {
    createBrokerLrAndContinueToHire,
    createBrokerLrViaUi,
    createLorryHireFromLr,
    editBrokerLrWithChangeReview,
    loginAndEnterBrokerBranch,
} from '../fixtures/broker-workflow';

test.describe('Broker operational-flow parity', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('Save & Create Lorry Hire continues without register search', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        const runId = Date.now().toString(36);
        await loginAndEnterBrokerBranch(page);

        await createBrokerLrAndContinueToHire(page, `E2E Save&Hire ${runId}`);

        // Landed on hire create with linked bilty context — no register search.
        await expect(
            page.getByText(/From bilty|Linked bilty/i).first(),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Create Lorry Hire' }),
        ).toBeVisible();

        await page.locator('#rate_type_0').selectOption('per_charged_weight');
        // Charged/loading weight is inherited read-only from the Bilty.
        await expect(page.locator('#loading_weight_0')).toHaveCount(0);
        await page.locator('#rate_0').fill('7500');
        await page.getByRole('button', { name: 'Create Lorry Hire' }).click();
        const hireDialog = page.getByRole('dialog');
        await expect(
            page.getByRole('heading', { name: 'Create this Lorry Hire?' }),
        ).toBeVisible({ timeout: 5_000 });
        await expect(
            hireDialog.getByRole('button', {
                name: 'Create & Record Voucher',
            }),
        ).toBeVisible();
        await hireDialog
            .getByRole('button', { name: 'Create Lorry Hire' })
            .click();

        await expect(page).toHaveURL(/\/broker\/lorry-hires\/[0-9a-f-]+$/, {
            timeout: 30_000,
        });
        await expect(
            page.getByRole('button', { name: 'Voucher Payment' }),
        ).toBeVisible();
    });

    test('Bilty edit confirmation shows previous and new values', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        const runId = Date.now().toString(36);
        await loginAndEnterBrokerBranch(page);

        const lr = await createBrokerLrViaUi(page, `E2E Edit confirm ${runId}`);
        expect(lr.lrPath).toBeTruthy();

        await editBrokerLrWithChangeReview(page, lr.lrPath);
    });

    test('Voucher Payment modal from hire show preselects hire with blank initial amount', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        const runId = Date.now().toString(36);
        await loginAndEnterBrokerBranch(page);

        await createBrokerLrViaUi(page, `E2E Hire voucher ${runId}`);
        const hire = await createLorryHireFromLr(page);
        expect(hire.hirePath.length).toBeGreaterThan(0);

        await page.goto(hire.hirePath);
        const voucherButton = page.getByRole('button', {
            name: 'Voucher Payment',
        });
        await expect(voucherButton).toBeVisible({ timeout: 15_000 });
        await voucherButton.click();

        const dialog = page.getByRole('dialog');
        await expect(
            dialog.getByRole('heading', { name: /Voucher Payment/i }),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            dialog.getByText(hire.hireNumber, { exact: false }).first(),
        ).toBeVisible({ timeout: 10_000 });
        await expect(
            dialog.getByLabel(/Payment amount for/).first(),
        ).toHaveValue('');
        await dialog.getByRole('button', { name: 'Review payment' }).click();
        await expect(
            dialog.getByText('Enter the payment amount.').first(),
        ).toBeVisible();
    });
});
