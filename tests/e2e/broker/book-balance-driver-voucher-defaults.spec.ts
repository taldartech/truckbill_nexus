import { expect, test, type Page } from '@playwright/test';
import { demoCredentials, loginAs } from '../fixtures/auth';
import {
    createBrokerLrAndContinueToHire,
    createBrokerLrViaUi,
    createLorryHireFromLr,
    loginAndEnterBrokerBranch,
} from '../fixtures/broker-workflow';

const SCREENSHOT_DIR =
    'storage/visual-certification/broker-book-balance-driver-voucher';

async function capture(page: Page, name: string): Promise<void> {
    await page.screenshot({
        path: `${SCREENSHOT_DIR}/${name}.png`,
        fullPage: true,
    });
}

async function enterBrokerOnlyWorkspace(page: Page): Promise<void> {
    const { password } = demoCredentials();
    await loginAs(page, 'owner.broker@demo.test', password);
    await page.goto('/management/branches');
    await expect(page.getByRole('heading', { name: 'Branches' })).toBeVisible({
        timeout: 20_000,
    });

    await page
        .locator('#company')
        .selectOption({ label: 'Demo Broker Express' });
    await page.getByRole('button', { name: /apply/i }).click();

    await page
        .locator('table tbody tr')
        .filter({ hasText: 'Demo Broker Express' })
        .first()
        .getByRole('button', { name: 'Enter Branch Panel' })
        .click();

    await expect(page).toHaveURL(/\/operations\/?$/, { timeout: 20_000 });
    await expect(page.getByText(/Demo Broker Express/i).first()).toBeVisible({
        timeout: 20_000,
    });
}

test.describe('Book Balance, Broker Driver Master, and Voucher Blank Amount Defaults', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('Book Balance from Party Master -> independent advance -> statement -> Voucher Adjust in Advance', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        const runId = Date.now().toString(36);
        const refNumber = `E2E-ADV-${runId}`;

        await loginAndEnterBrokerBranch(page);

        // 1. Party Master Register -> Book Balance modal -> blank initial amount -> independent advance
        await page.goto('/parties?role=transporter');
        await expect(
            page.getByRole('heading', { name: 'Parties' }),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.getByRole('columnheader', { name: 'Book Balance' }),
        ).toBeVisible();

        const transporterRow = page
            .locator('tbody tr')
            .filter({ hasText: 'Demo Broker Owner Transporter' })
            .first();
        await expect(transporterRow).toBeVisible({ timeout: 15_000 });

        await transporterRow
            .getByRole('button', { name: 'Book Balance' })
            .click();

        const bbDialog = page.getByRole('dialog');
        await expect(
            bbDialog.getByRole('heading', { name: 'Book Balance' }),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            bbDialog.getByTestId('book-balance-available'),
        ).toBeVisible({ timeout: 15_000 });

        // Blank initial amount + validation on empty submit
        await expect(bbDialog.locator('#bb_amount')).toHaveValue('');
        await bbDialog
            .getByRole('button', { name: 'Review transaction' })
            .click();
        await expect(
            bbDialog.getByText('Enter the transaction amount.'),
        ).toBeVisible();

        await bbDialog.locator('#bb_transaction_type').selectOption('advance');
        await bbDialog.locator('#bb_amount').fill('2500');
        await bbDialog.locator('#bb_payment_mode').selectOption('cash');
        await bbDialog.locator('#bb_reference_number').fill(refNumber);
        await bbDialog
            .locator('#bb_remark')
            .fill('E2E independent advance from Party Master');
        await capture(page, '01-book-balance-modal');

        await bbDialog
            .getByRole('button', { name: 'Review transaction' })
            .click();
        await expect(
            page.getByRole('heading', {
                name: 'Confirm Book Balance transaction?',
            }),
        ).toBeVisible({ timeout: 10_000 });
        await page.getByRole('button', { name: 'Save transaction' }).click();

        await expect(page.getByText(/Book Balance/i).first()).toBeVisible({
            timeout: 20_000,
        });

        // 2. Party Details -> Book Balance card & View Statement
        await transporterRow.getByRole('link', { name: 'View party' }).click();
        await expect(page).toHaveURL(/\/parties\/[0-9a-f-]+$/, {
            timeout: 15_000,
        });
        await expect(
            page.getByText('Transporter Book Balance').first(),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.getByText('Available Book Balance').first(),
        ).toBeVisible();
        await capture(page, '02-party-details-book-balance');

        await page.getByRole('link', { name: 'View Statement' }).click();
        await expect(page).toHaveURL(/\/broker\/transporter-balances/, {
            timeout: 15_000,
        });

        await expect(page.getByText('Opening Balance').first()).toBeVisible({
            timeout: 15_000,
        });
        await expect(page.getByText('Closing Balance').first()).toBeVisible();
        for (const col of [
            'Transaction Date',
            'Transaction Type',
            'Particulars',
            'Reference Number',
            'Debit',
            'Credit',
            'Running Balance',
        ]) {
            await expect(
                page.getByRole('columnheader', { name: col }),
            ).toBeVisible();
        }
        await expect(page.getByText(refNumber).first()).toBeVisible();
        await capture(page, '03-book-balance-statement');

        // 3. Create Lorry Hire -> Create & Record Voucher (?pay=1) -> blank amount -> Adjust in Advance
        await createBrokerLrAndContinueToHire(
            page,
            `E2E Book Balance Adjust ${runId}`,
        );
        await page.locator('#rate_type_0').selectOption('per_charged_weight');
        await page.locator('#rate_0').fill('7500');
        await page.getByRole('button', { name: 'Create Lorry Hire' }).click();

        const createConfirm = page.getByRole('dialog');
        await expect(
            createConfirm.getByRole('heading', {
                name: 'Create this Lorry Hire?',
            }),
        ).toBeVisible({ timeout: 10_000 });
        await createConfirm
            .getByRole('button', { name: 'Create & Record Voucher' })
            .click();

        await expect(page).toHaveURL(
            /\/broker\/lorry-hires\/[0-9a-f-]+\?pay=1$/,
            { timeout: 30_000 },
        );

        const payDialog = page.getByRole('dialog');
        await expect(
            payDialog.getByRole('heading', { name: /Voucher Payment/i }),
        ).toBeVisible({ timeout: 15_000 });
        const lineAmount = payDialog.getByLabel(/Payment amount for/).first();
        await expect(lineAmount).toHaveValue('');

        await lineAmount.fill('500');
        await payDialog
            .locator('#voucher_payment_mode')
            .selectOption('adjust_in_advance');
        await payDialog
            .locator('#voucher_remark')
            .fill('E2E Adjust in Advance');
        await payDialog.getByRole('button', { name: 'Review payment' }).click();
        await page
            .getByRole('button', { name: 'Record payment', exact: true })
            .click();

        await expect(
            page.getByText('Voucher payment recorded.').first(),
        ).toBeVisible({ timeout: 20_000 });
    });

    test('Broker-only Driver Master, Market Vehicle Quick Add, OwnFleet isolation, and Book Balance without Accounting', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        const runId = Date.now().toString(36);
        const driverName = `E2E Broker Driver ${runId}`;
        const updatedDriverName = `E2E Broker Driver Ed ${runId}`;
        const quickDriverName = `E2E Quick Driver ${runId}`;

        await enterBrokerOnlyWorkspace(page);

        // 1. Sidebar shows Driver Master and hides OwnFleet Vehicle Drivers / Accounting
        await expect(
            page.getByRole('link', { name: 'Driver Master', exact: true }),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.getByRole('link', { name: 'Vehicle Drivers', exact: true }),
        ).toHaveCount(0);
        await expect(
            page.getByRole('link', { name: 'Accounting', exact: true }),
        ).toHaveCount(0);

        // Direct route to OwnFleet Vehicle Drivers returns capability-blocked (403)
        await page.goto('/vehicle-drivers');
        await expect(
            page.getByText(/Subscription|Upgrade|Vehicle Drivers/i).first(),
        ).toBeVisible({ timeout: 15_000 });

        // 2. Driver Master register columns + Add Driver (no GST/tax/declaration fields) + Edit + Delete
        await page.goto('/drivers');
        await expect(
            page.getByRole('heading', { name: 'Driver Master' }),
        ).toBeVisible({ timeout: 15_000 });
        for (const col of [
            'Driver Name',
            'Short Name',
            'Mobile Number',
            'Driving Licence Number',
            'Licence Expiry',
            'City and State',
            'Status',
            'Actions',
        ]) {
            await expect(
                page.getByRole('columnheader', { name: col }),
            ).toBeVisible();
        }
        await capture(page, '04-broker-only-driver-master');

        await page.getByRole('link', { name: 'Add Driver' }).click();
        await expect(page).toHaveURL(/\/drivers\/create/, { timeout: 15_000 });
        await expect(page.locator('#gstin')).toHaveCount(0);
        await expect(page.locator('#pan')).toHaveCount(0);

        await page.locator('#name').fill(driverName);
        await page.locator('#contact_number').fill('9811100222');
        await page.locator('#license_number').fill('MH14E2E2026');
        await page.locator('#license_expiry').fill('2031-11-30');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await page.getByRole('button', { name: /Confirm/i }).click();

        await expect(page).toHaveURL(/\/drivers\/[0-9a-f-]+$/, {
            timeout: 20_000,
        });
        await expect(page.getByTestId('party-name')).toHaveText(driverName, {
            timeout: 15_000,
        });

        await page.getByRole('link', { name: 'Edit driver' }).click();
        await expect(page).toHaveURL(/\/drivers\/[0-9a-f-]+\/edit$/, {
            timeout: 15_000,
        });
        await page.locator('#name').fill(updatedDriverName);
        await page.locator('#remark').fill('E2E driver name update');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await page.getByRole('button', { name: /Confirm/i }).click();
        await expect(page).toHaveURL(/\/drivers\/[0-9a-f-]+$/, {
            timeout: 20_000,
        });
        await expect(page.getByTestId('party-name')).toHaveText(
            updatedDriverName,
            { timeout: 20_000 },
        );

        await page.goto(`/drivers?q=${encodeURIComponent(updatedDriverName)}`);
        const row = page
            .locator('tbody tr')
            .filter({ hasText: updatedDriverName })
            .first();
        await expect(row).toBeVisible({ timeout: 15_000 });
        await expect(row.getByText('30-11-2031')).toBeVisible();

        await row.getByRole('button', { name: 'Delete driver' }).click();
        const delDialog = page.getByRole('dialog');
        await expect(
            delDialog.getByRole('heading', { name: 'Delete driver?' }),
        ).toBeVisible({ timeout: 10_000 });
        await delDialog.locator('textarea, input').first().fill('E2E cleanup');
        await delDialog.getByRole('button', { name: 'Delete driver' }).click();
        await expect(row).toHaveCount(0, { timeout: 15_000 });

        // 3. Market Vehicle create -> Quick Add Driver inline
        await page.goto('/vehicles/create');
        await expect(
            page.getByRole('heading', { name: /Create Vehicle|Add Vehicle/i }),
        ).toBeVisible({ timeout: 15_000 });
        const driverSelectGroup = page
            .locator('#primary_driver_party_id-trigger')
            .locator('xpath=ancestor::div[contains(@class,"space-y-2")]')
            .first();
        await driverSelectGroup
            .getByRole('button', { name: 'Add', exact: true })
            .click();

        const sheet = page.getByRole('dialog');
        await expect(
            sheet.getByRole('heading', { name: 'Add Driver' }),
        ).toBeVisible({ timeout: 10_000 });
        await sheet.locator('#quick-party-name').fill(quickDriverName);
        await sheet.locator('#quick-party-contact_number').fill('9811100333');
        await sheet.locator('#quick-party-license_number').fill('MH14QK2026');
        await sheet.getByRole('button', { name: 'Review & create' }).click();
        await page.getByRole('button', { name: /Confirm/i }).click();

        await expect(sheet).toBeHidden({ timeout: 15_000 });
        await expect(
            page.locator('#primary_driver_party_id-trigger'),
        ).toContainText(quickDriverName, { timeout: 15_000 });
        await capture(page, '05-market-vehicle-quick-add-driver');

        // 4. Book Balance without Accounting add-on works end to end
        await page.goto('/broker/transporter-balances');
        await expect(
            page.getByRole('heading', { name: 'Transporter Book Balance' }),
        ).toBeVisible({ timeout: 15_000 });
        await page
            .locator('tbody tr')
            .filter({ hasText: 'Broker Express Transporter' })
            .first()
            .getByRole('button', { name: 'Book Balance' })
            .click();
        const noAccDialog = page.getByRole('dialog');
        await expect(noAccDialog.locator('#bb_amount')).toHaveValue('');
        await noAccDialog.locator('#bb_amount').fill('400');
        await noAccDialog.locator('#bb_payment_mode').selectOption('cash');
        await noAccDialog
            .locator('#bb_remark')
            .fill('Broker-only advance without Accounting');
        await noAccDialog
            .getByRole('button', { name: 'Review transaction' })
            .click();
        await page.getByRole('button', { name: 'Save transaction' }).click();
        await expect(page.getByText(/Book Balance/i).first()).toBeVisible({
            timeout: 15_000,
        });
    });

    test('Voucher Payment bulk modal starts all rows blank, rejects partial blank rows, and resets on reopen', async ({
        page,
    }) => {
        test.setTimeout(360_000);
        const runId = Date.now().toString(36);

        await loginAndEnterBrokerBranch(page);

        const lr1 = await createBrokerLrViaUi(
            page,
            `E2E Bulk Blank A ${runId}`,
        );
        const hire1 = await createLorryHireFromLr(page);
        const lr2 = await createBrokerLrViaUi(
            page,
            `E2E Bulk Blank B ${runId}`,
            { vehicleId: lr1.vehicleId ?? undefined },
        );
        await page.goto(lr2.lrPath);
        const hire2 = await createLorryHireFromLr(page);

        await page.goto('/broker/lorry-hires');
        const box1 = page.getByRole('checkbox', {
            name: `Select hire ${hire1.hireNumber}`,
        });
        const box2 = page.getByRole('checkbox', {
            name: `Select hire ${hire2.hireNumber}`,
        });
        await expect(box1).toBeVisible({ timeout: 20_000 });
        await box1.check();
        await box2.check();

        await page.getByRole('button', { name: 'Voucher Payment' }).click();
        const dialog = page.getByRole('dialog');
        await expect(
            dialog.getByRole('heading', {
                name: /Voucher Payment — 2 Lorry Hires/,
            }),
        ).toBeVisible({ timeout: 15_000 });

        const input1 = dialog.getByLabel(
            `Payment amount for ${hire1.hireNumber}`,
        );
        const input2 = dialog.getByLabel(
            `Payment amount for ${hire2.hireNumber}`,
        );
        await expect(input1).toHaveValue('');
        await expect(input2).toHaveValue('');
        await expect(dialog.getByText('₹0.00').last()).toBeVisible();

        // Fill only row 1; leave row 2 blank -> reject with explicit per-row error
        await input1.fill('1000');
        await expect(dialog.getByText('₹1,000.00').last()).toBeVisible();
        await dialog.getByRole('button', { name: 'Review payment' }).click();
        await expect(
            dialog.getByText('Enter the payment amount.').first(),
        ).toBeVisible();
        await capture(page, '06-bulk-voucher-blank-row-error');

        // Close and reopen -> row 1 resets to blank
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toBeHidden({ timeout: 10_000 });
        await page.getByRole('button', { name: 'Voucher Payment' }).click();
        await expect(input1).toHaveValue('');
        await expect(input2).toHaveValue('');

        // Enter explicit valid amounts on both rows -> succeeds
        await input1.fill('1000');
        await input2.fill('500');
        await expect(dialog.getByText('₹1,500.00').last()).toBeVisible();
        await dialog.locator('#voucher_payment_mode').selectOption('cash');
        await dialog.getByRole('button', { name: 'Review payment' }).click();
        await page
            .getByRole('button', { name: 'Record payment', exact: true })
            .click();

        await expect(
            page.getByText(/2 voucher payments recorded/i).first(),
        ).toBeVisible({ timeout: 20_000 });
    });
});
