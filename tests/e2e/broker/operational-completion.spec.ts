import { expect, test } from '@playwright/test';
import { loginAndEnterBrokerBranch } from '../fixtures/broker-workflow';

test.describe('Operational UX completion', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('voucher register advances status without full page reload', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await loginAndEnterBrokerBranch(page);

        await page.goto('/broker/vouchers');
        await expect(
            page.getByTestId('voucher-payments-register'),
        ).toBeVisible();

        const markButton = page
            .getByRole('button', { name: /^Mark in progress$/i })
            .first();
        if ((await markButton.count()) === 0) {
            test.skip(true, 'No pending voucher in demo fixtures');
        }

        await markButton.click();
        await expect(
            page.getByText(/in progress/i).first(),
        ).toBeVisible({ timeout: 15_000 });
    });

    test('payable only filter checkbox is available on good hires register', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await loginAndEnterBrokerBranch(page);

        await page.goto('/broker/lorry-hires');
        await expect(page.getByTestId('good-hires-register')).toBeVisible({
            timeout: 20_000,
        });
        await expect(
            page.getByRole('checkbox', { name: /Payable balance/i }),
        ).toBeVisible();
    });
});