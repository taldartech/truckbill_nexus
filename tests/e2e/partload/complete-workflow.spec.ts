import { expect, test } from '@playwright/test';
import {
    createAndDispatchPartloadChallan,
    createDeliveryReceiptFromPending,
    createInwardFromChallan,
    createPartloadLrViaUi,
    loginAndEnterPartloadBranch,
} from '../fixtures/partload-workflow';

test.describe('Partload complete workflow', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('journey — booking → challan → dispatch → inward → DR → reports', async ({
        page,
    }) => {
        test.setTimeout(420_000);
        const runId = Date.now().toString(36);

        await loginAndEnterPartloadBranch(page);

        const first = await createPartloadLrViaUi(
            page,
            `E2E Partload A ${runId}`,
        );
        expect(first.lrPath).toContain('/lorry-receipts/');

        const second = await createPartloadLrViaUi(
            page,
            `E2E Partload B ${runId}`,
        );
        expect(second.lrPath).toContain('/lorry-receipts/');

        await page.goto('/partload/pending-loading');
        await expect(page.getByText(/Pending Loading/i).first()).toBeVisible({
            timeout: 20_000,
        });

        const lrLabels = [first.lrNumber, second.lrNumber].filter(
            (value): value is string => Boolean(value),
        );
        const challanPath = await createAndDispatchPartloadChallan(
            page,
            lrLabels,
        );
        expect(challanPath).toContain('/partload/challans/');

        await page.goto('/partload/in-transit');
        await expect(page.getByText(/In Transit/i).first()).toBeVisible({
            timeout: 20_000,
        });

        const inwardPath = await createInwardFromChallan(page, challanPath);
        expect(inwardPath).toContain('/partload/inwards/');

        await createDeliveryReceiptFromPending(page, first.lrNumber);

        await page.goto('/partload/pending-delivery');
        await expect(page.getByText(/Pending Delivery/i).first()).toBeVisible({
            timeout: 20_000,
        });

        await page.goto('/broker/invoices');
        await expect(
            page.getByRole('heading', { name: /Invoices/i }).first(),
        ).toBeVisible({ timeout: 20_000 });
    });
});
