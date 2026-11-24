import { expect, test } from '@playwright/test';
import {
    createBrokerLrViaUi,
    createLinkedBiltyGroup,
    createLorryHireFromLr,
    loginAndEnterBrokerBranch,
    recordLinkedBiltyGroupPayment,
} from '../fixtures/broker-workflow';

test.describe('Broker linked bilty payment', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('journey — two LRs → hires → linked group → group payment', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        const runId = Date.now().toString(36);

        await loginAndEnterBrokerBranch(page);

        const lrOne = await createBrokerLrViaUi(
            page,
            `E2E linked A ${runId}`,
        );
        const hireOne = await createLorryHireFromLr(page);
        expect(lrOne.lrNumber).toBeTruthy();
        expect(hireOne.hireNumber).toBeTruthy();

        const lrTwo = await createBrokerLrViaUi(
            page,
            `E2E linked B ${runId}`,
            { vehicleId: lrOne.vehicleId ?? undefined },
        );
        expect(lrTwo.lrNumber).toBeTruthy();
        expect(lrTwo.vehicleId).toBe(lrOne.vehicleId);

        await page.goto(lrTwo.lrPath);
        const hireTwo = await createLorryHireFromLr(page);
        expect(hireTwo.hireNumber).toBeTruthy();

        const groupTitle = await createLinkedBiltyGroup(
            page,
            lrOne.vehicleId!,
            [lrOne.lrNumber!, lrTwo.lrNumber!],
        );
        expect(groupTitle.length).toBeGreaterThan(0);

        await recordLinkedBiltyGroupPayment(page, '1500');
    });
});