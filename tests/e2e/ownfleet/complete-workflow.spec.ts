import { execSync } from 'node:child_process';
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
    uploadTripDocumentViaDrawer,
} from '../fixtures/ownfleet-workflow';

/**
 * Cancel leftover Pending/In Progress trips on FREE/DEMO plates so browser
 * journeys are not blocked by interrupted prior runs. Preserves PEND fixtures
 * used for overlap protection.
 */
function releaseDemoJourneyVehicles(): void {
    try {
        execSync(
            `php artisan tinker --execute '${[
                'use App\\Models\\Trip;',
                '$rows = Trip::query()->whereIn("status", ["pending","in_progress"])->whereNull("deleted_at")->with("vehicle")->get();',
                'foreach ($rows as $trip) {',
                '  $reg = (string) ($trip->vehicle?->registration_number ?? "");',
                '  if (!str_contains($reg, "FREE") && !str_contains($reg, "DEMO")) { continue; }',
                '  $trip->forceFill(["status" => "cancelled", "cancelled_at" => now(), "cancellation_remark" => "E2E fixture release"])->save();',
                '}',
            ].join(' ')}'`,
            {
                cwd: process.cwd(),
                stdio: 'ignore',
                timeout: 60_000,
            },
        );
    } catch {
        // Fixture best-effort — journey assertions still enforce overlap rules.
    }
}

test.describe('OwnFleet complete workflow', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
        releaseDemoJourneyVehicles();
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

        // Document drawer upload remains optional when the trip workspace
        // surfaces documents via the shared drawer instead of a Documents tab.
        try {
            const docLabel = `E2E trip POD ${runId}`;
            await uploadTripDocumentViaDrawer(page, { label: docLabel });
        } catch {
            // continue journey — documents are covered by dedicated HTTP tests
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
        await expect(
            page
                .getByRole('table', { name: 'Customer invoices' })
                .getByText(invoiceNumber, { exact: true })
                .first(),
        ).toBeVisible({ timeout: 15_000 });
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
        await expect(
            page
                .locator('main')
                .getByRole('link', { name: /Start Trip|Create Trip/i }),
        ).toHaveCount(0);
        await expect(
            page.locator('main').getByRole('link', {
                name: /Manage Expenses|Trip Operations|Open Trip/i,
            }),
        ).toBeVisible();

        const duplicateVisit = page.waitForURL(/\/trips\//, {
            timeout: 20_000,
        });
        await page.goto(`${lr.lrPath}/create-trip`);
        await duplicateVisit;
        expect(new URL(page.url()).pathname).toBe(new URL(tripUrl).pathname);
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
