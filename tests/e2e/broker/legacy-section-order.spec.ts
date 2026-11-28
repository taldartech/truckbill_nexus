import { expect, test } from '@playwright/test';
import { loginAndEnterBrokerBranch } from '../fixtures/broker-workflow';

/**
 * Nexus-only legacy section order certification (legacy broker app not run in CI/local).
 * Mirrors freight_bookings create/edit panel sequence from legacy/truckbill_broker.
 */
test.describe('Broker legacy section order (Nexus)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAndEnterBrokerBranch(page);
    });

    test('lorry hire edit exposes legacy section order and custom amounts table', async ({
        page,
    }) => {
        await page
            .getByRole('link', { name: /Good Hires|Lorry Hires/i })
            .click();
        await page.getByRole('link', { name: /Edit/i }).first().click();

        const linked = page.getByTestId('lorry-hire-section-linked-bilty');
        const charges = page.getByTestId('lorry-hire-section-hire-charges');
        const custom = page.getByTestId('lorry-hire-section-custom-amounts');
        const optional = page.getByTestId(
            'lorry-hire-section-optional-details',
        );
        const financial = page.getByTestId(
            'lorry-hire-section-financial-summary',
        );

        await expect(linked).toBeVisible();
        await expect(charges).toBeVisible();
        await expect(custom).toBeVisible();
        await expect(optional).toBeVisible();
        await expect(financial).toBeVisible();

        const order = await page.evaluate(() => {
            const ids = [
                'lorry-hire-section-linked-bilty',
                'lorry-hire-section-hire-charges',
                'lorry-hire-section-custom-amounts',
                'lorry-hire-section-optional-details',
                'lorry-hire-section-financial-summary',
            ];
            const tops = ids.map((id) => {
                const el = document.querySelector(`[data-testid="${id}"]`);
                return el
                    ? el.getBoundingClientRect().top
                    : Number.POSITIVE_INFINITY;
            });
            for (let i = 1; i < tops.length; i++) {
                if (tops[i] < tops[i - 1] - 2) {
                    return false;
                }
            }
            return true;
        });
        expect(order).toBe(true);
    });

    test('good hires register opens voucher modal in legacy panel order', async ({
        page,
    }) => {
        await page
            .getByRole('link', { name: /Good Hires|Lorry Hires/i })
            .click();
        await expect(page.getByTestId('good-hires-register')).toBeVisible();

        const checkbox = page
            .getByRole('checkbox', { name: /^Select hire / })
            .first();
        await checkbox.check();
        await page
            .getByRole('button', { name: 'Voucher Payment', exact: true })
            .click();

        const modal = page.getByTestId('voucher-payment-modal');
        await expect(modal).toBeVisible({ timeout: 15_000 });

        const panelOrder = await modal.evaluate((root) => {
            const ids = [
                'voucher-panel-previous-payments',
                'voucher-panel-transporter',
                'voucher-panel-allocation',
                'voucher-panel-payment-details',
            ];
            const tops = ids.map((id) => {
                const el = root.querySelector(`[data-testid="${id}"]`);
                return el
                    ? el.getBoundingClientRect().top
                    : Number.POSITIVE_INFINITY;
            });
            for (let i = 1; i < tops.length; i++) {
                if (tops[i] < tops[i - 1] - 2) {
                    return false;
                }
            }
            return true;
        });
        expect(panelOrder).toBe(true);

        const amountInput = modal.getByLabel(/Payment amount for/).first();
        await expect(amountInput).toHaveValue('');
    });
});
