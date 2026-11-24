import { expect, test } from '@playwright/test';
import {
    addBrokerLorryHireAdjustment,
    assignMemoAndDeliverLr,
    assertTransporterBookBalanceHasActivity,
    createAdjustInAdvanceVoucher,
    createBrokerCustomerInvoice,
    createBrokerLrViaUi,
    createLorryHireFromLr,
    loginAndEnterBrokerBranch,
    openBrokerInvoicePrintPreview,
    recordBrokerInvoicePayments,
    uploadPodIfAvailable,
} from '../fixtures/broker-workflow';

test.describe('Broker complete workflow', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('journey — LR → lorry hire → voucher → book balance → delivery → invoice', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        const runId = Date.now().toString(36);
        const goodsDescription = `E2E Broker workflow ${runId}`;
        const memoNumber = `MEMO-E2E-${runId}`;

        await loginAndEnterBrokerBranch(page);

        const lr = await createBrokerLrViaUi(page, goodsDescription);
        expect(lr.lrNumber).toBeTruthy();

        const hire = await createLorryHireFromLr(page);
        expect(hire.hireNumber.length).toBeGreaterThan(0);

        const voucherNumber = await createAdjustInAdvanceVoucher(
            page,
            hire.hireNumber,
            '2000',
        );
        expect(voucherNumber.length).toBeGreaterThan(0);

        await assertTransporterBookBalanceHasActivity(page);

        await assignMemoAndDeliverLr(page, lr.lrNumber!, memoNumber);
        await uploadPodIfAvailable(page, lr.lrNumber!);

        await page.goto(hire.hirePath);
        await addBrokerLorryHireAdjustment(page, '500');

        const invoiceNumber = await createBrokerCustomerInvoice(
            page,
            lr.lrNumber!,
        );
        expect(invoiceNumber.length).toBeGreaterThan(0);

        await openBrokerInvoicePrintPreview(page);
        await page.goBack();

        await recordBrokerInvoicePayments(page, '5000');

        await page.goto('/broker/invoices');
        await expect(
            page.getByRole('heading', { name: 'Invoices' }),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page
                .getByRole('table', { name: 'Customer invoices' })
                .getByText(invoiceNumber, { exact: true }),
        ).toBeVisible();
    });
});
