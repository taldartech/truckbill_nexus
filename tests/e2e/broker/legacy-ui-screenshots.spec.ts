import { test } from '@playwright/test';
import {
    loginAndEnterBrokerBranch,
    openGoodHiresRegister,
} from '../fixtures/broker-workflow';
import path from 'node:path';

const OUT_DIR = path.resolve(
    process.cwd(),
    'storage/visual-certification/broker-legacy-alignment-2026-11-28',
);

test.describe('Broker legacy UI screenshots (Nexus)', () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test.beforeEach(async ({ page }) => {
        await loginAndEnterBrokerBranch(page);
    });

    test('capture Good Hires register and edit layout', async ({ page }) => {
        await openGoodHiresRegister(page);
        await page.screenshot({
            path: path.join(OUT_DIR, '05-good-hires-register-1440.png'),
            fullPage: true,
        });

        const edit = page.getByRole('link', { name: /Edit/i }).first();
        if (await edit.count()) {
            await edit.click();
            await page
                .locator('#lorry-hire-form')
                .waitFor({ state: 'visible' });
            await page.screenshot({
                path: path.join(OUT_DIR, '02-lorry-hire-edit-1440.png'),
                fullPage: true,
            });
        }
    });

    test('capture voucher payment modal', async ({ page }) => {
        await openGoodHiresRegister(page);
        const checkbox = page
            .getByRole('checkbox', { name: /^Select hire / })
            .first();
        if ((await checkbox.count()) === 0) {
            test.skip(true, 'No payable hire on register');
        }
        await checkbox.check();
        await page
            .getByRole('button', { name: 'Voucher Payment', exact: true })
            .click();
        const modal = page.getByTestId('voucher-payment-modal');
        await modal.waitFor({ state: 'visible', timeout: 20_000 });
        await page.screenshot({
            path: path.join(OUT_DIR, '06-voucher-payment-modal-1440.png'),
            fullPage: false,
        });
    });
});
