import { expect, test } from '@playwright/test';
import {
    addDieselExpense,
    createOwnFleetLrViaUi,
    createTripInvoiceForLr,
    dispatchAndCompleteTrip,
    loginAndEnterOwnFleetBranch,
    openLrCreateTrip,
    reconcileTripMovement,
    recordTripInvoicePayments,
    submitTripCreateFromLr,
} from '../fixtures/ownfleet-workflow';

test.describe('OwnFleet complete workflow', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('journey — LR → trip → dispatch → invoice → payments', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        const runId = Date.now().toString(36);
        const goodsDescription = `E2E OwnFleet workflow ${runId}`;

        await loginAndEnterOwnFleetBranch(page);

        const lr = await createOwnFleetLrViaUi(page, goodsDescription);
        expect(lr.lrNumber).toBeTruthy();

        await openLrCreateTrip(page);
        await submitTripCreateFromLr(page);

        await reconcileTripMovement(page, {
            weight: '1000',
            freight: '15000',
        });
        await addDieselExpense(page);

        await page.getByRole('tab', { name: 'Documents' }).click();
        const docsButton = page.getByRole('button', {
            name: /Manage trip documents|Trip documents/i,
        });
        if ((await docsButton.count()) > 0) {
            await expect(docsButton.first()).toBeVisible();
        } else {
            await expect(
                page.getByRole('link', { name: /LR \/ POD documents/i }),
            ).toBeVisible();
        }

        await dispatchAndCompleteTrip(page, '8800', '9120');

        const invoiceNumber = await createTripInvoiceForLr(
            page,
            lr.lrNumber!,
            goodsDescription,
        );
        expect(invoiceNumber.length).toBeGreaterThan(0);

        await recordTripInvoicePayments(page, '5000');

        await page.goto('/invoices');
        await expect(
            page.getByRole('heading', { name: 'Invoices' }),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText(invoiceNumber).first()).toBeVisible();
    });

    test('edge — duplicate trip link hidden after first trip', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const runId = Date.now().toString(36);

        await loginAndEnterOwnFleetBranch(page);
        const lr = await createOwnFleetLrViaUi(
            page,
            `E2E duplicate trip ${runId}`,
        );
        expect(lr.lrNumber).toBeTruthy();

        await openLrCreateTrip(page);
        await submitTripCreateFromLr(page);
        const tripUrl = page.url();

        await page.goto(lr.lrPath);
        await page.getByRole('tab', { name: 'Actions' }).click();
        await expect(
            page.getByRole('link', { name: 'Create Trip' }),
        ).toHaveCount(0);
        await expect(
            page.getByRole('link', { name: 'Open Trip' }),
        ).toBeVisible();

        const duplicateVisit = page.waitForURL(/\/trips\//, {
            timeout: 20_000,
        });
        await page.goto(`${lr.lrPath}/create-trip`);
        await duplicateVisit;
        expect(page.url()).toBe(tripUrl);
    });

    test('edge — trip create locks vehicle to LR (no vehicle picker)', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        const runId = Date.now().toString(36);

        await loginAndEnterOwnFleetBranch(page);
        await createOwnFleetLrViaUi(page, `E2E vehicle lock ${runId}`);
        await openLrCreateTrip(page);

        await expect(page.locator('select#vehicle_id')).toHaveCount(0);
        await expect(
            page.locator('input[name="vehicle_id"][type="hidden"]'),
        ).toHaveCount(1);
        await expect(page.locator('#vehicle_display')).toHaveAttribute(
            'readonly',
        );
        await expect(
            page.getByText(/Trip must use the Own vehicle already assigned/i),
        ).toBeVisible();
    });
});
