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
        test.setTimeout(180_000);
        await loginAndEnterBrokerBranch(page);
    });

    test('capture core broker registers and forms', async ({ page }) => {
        await openGoodHiresRegister(page);
        await page.screenshot({
            path: path.join(OUT_DIR, '05-good-hires-register-1440.png'),
            fullPage: true,
        });

        const firstShow = page
            .getByRole('link', { name: 'Open lorry hire' })
            .first();
        if (await firstShow.count()) {
            await firstShow.click();
            await page.waitForURL(/\/broker\/lorry-hires\//);
            await page.screenshot({
                path: path.join(OUT_DIR, '03-lorry-hire-view-1440.png'),
                fullPage: true,
            });
            const printHref = page.locator('a[href*="/print"]').first();
            if (await printHref.count()) {
                const href = await printHref.getAttribute('href');
                if (href) {
                    await page.goto(href);
                    await page.screenshot({
                        path: path.join(
                            OUT_DIR,
                            '11-lorry-hire-print-1440.png',
                        ),
                        fullPage: true,
                    });
                    await page.goBack();
                }
            }
        }

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
            const markDelivered = page.getByTestId(
                'lorry-hire-edit-mark-delivered',
            );
            if (await markDelivered.count()) {
                await page.screenshot({
                    path: path.join(
                        OUT_DIR,
                        '12-lorry-hire-edit-mark-delivered-1440.png',
                    ),
                    fullPage: false,
                });
            }
        }

        await page.goto('/broker/vouchers');
        await page
            .getByTestId('voucher-payments-register')
            .waitFor({ state: 'visible', timeout: 20_000 });
        await page.screenshot({
            path: path.join(OUT_DIR, '06-voucher-register-1440.png'),
            fullPage: true,
        });

        await page.goto('/broker/daily-payments');
        await page
            .getByTestId('daily-transporter-payments')
            .waitFor({ state: 'visible', timeout: 20_000 });
        await page.screenshot({
            path: path.join(OUT_DIR, '07-daily-payments-1440.png'),
            fullPage: true,
        });
    });

    test('capture voucher and memo modals', async ({ page }) => {
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
        const voucherModal = page.getByTestId('voucher-payment-modal');
        await voucherModal.waitFor({ state: 'visible', timeout: 20_000 });
        await page.screenshot({
            path: path.join(OUT_DIR, '08-voucher-payment-modal-1440.png'),
            fullPage: false,
        });
        await page
            .locator('#voucher_payment_mode')
            .selectOption({ label: 'Bank' });
        await page.getByLabel('Bank Account').click();
        await page.waitForTimeout(300);
        await page.screenshot({
            path: path.join(
                OUT_DIR,
                '09-voucher-bank-combobox-dropdown-1440.png',
            ),
            fullPage: false,
        });
        await page.keyboard.press('Escape');
        await voucherModal.waitFor({ state: 'hidden' });

        const memoCheckbox = page
            .getByRole('checkbox', { name: /^Select hire / })
            .filter({ hasNot: page.locator(':disabled') });
        const memoTarget = memoCheckbox.first();
        if (await memoTarget.count()) {
            await memoTarget.check();
            await page
                .getByRole('button', { name: 'Memo / POD', exact: true })
                .click();
            const memoModal = page.getByTestId('memo-pod-modal');
            await memoModal.waitFor({ state: 'visible', timeout: 20_000 });
            await page.screenshot({
                path: path.join(OUT_DIR, '10-memo-pod-modal-1440.png'),
                fullPage: false,
            });
        }
    });
});
