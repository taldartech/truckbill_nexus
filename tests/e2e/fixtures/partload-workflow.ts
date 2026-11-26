import { expect, type Page } from '@playwright/test';
import { demoCredentials, loginAs } from './auth';
import { enterDemoAlphaBranch } from './demo-workspace';

export type PartloadLrContext = {
    goodsDescription: string;
    lrNumber: string | null;
    lrPath: string;
};

function todayIsoDate(): string {
    return new Date().toISOString().slice(0, 10);
}

async function confirmFormDialog(page: Page): Promise<void> {
    const confirm = page.getByRole('button', {
        name: /Confirm (create|save|delete)|Save Bilty/i,
    });
    const visible = await confirm
        .waitFor({ state: 'visible', timeout: 5_000 })
        .then(() => true)
        .catch(() => false);
    if (!visible) {
        return;
    }
    await confirm.click();
}

async function selectOptionAt(
    page: Page,
    selector: string,
    index: number,
): Promise<void> {
    const field = page.locator(selector);
    const trigger =
        selector.startsWith('#') && !selector.includes('[')
            ? page.locator(`${selector}-trigger`)
            : null;
    if (trigger && (await trigger.count()) > 0) {
        await expect(trigger).toBeVisible();
    }
    const option = field.locator('option[value]:not([value=""])').nth(index);
    const value = await option.getAttribute('value');
    if (!value) {
        if (trigger && (await trigger.count()) > 0) {
            await trigger.click();
            const live = page
                .getByRole('listbox')
                .getByRole('option')
                .nth(index);
            await expect(live).toBeVisible({ timeout: 10_000 });
            await live.click();
            return;
        }
        throw new Error(`No option at index ${index} for ${selector}`);
    }
    await field.selectOption(value);
}

export async function loginAndEnterPartloadBranch(page: Page): Promise<void> {
    const { email, password } = demoCredentials();
    await loginAs(page, email, password);
    await enterDemoAlphaBranch(page);
}

export async function createPartloadLrViaUi(
    page: Page,
    goodsDescription: string,
): Promise<PartloadLrContext> {
    await page.goto('/lorry-receipts/create?context=partload');
    await expect(page.getByText(/Partload/i).first()).toBeVisible({
        timeout: 20_000,
    });

    const date = page.locator('#lr_date');
    if ((await date.count()) > 0) {
        await date.fill(todayIsoDate());
    }

    for (const selector of [
        '#billing_customer_party_id',
        '#consignor_party_id',
        '#consignee_party_id',
    ]) {
        if ((await page.locator(selector).count()) > 0) {
            await selectOptionAt(page, selector, 0);
        }
    }

    if ((await page.locator('#from_station_id').count()) > 0) {
        await selectOptionAt(page, '#from_station_id', 0);
    }
    if ((await page.locator('#to_station_id').count()) > 0) {
        await selectOptionAt(page, '#to_station_id', 1).catch(async () => {
            await selectOptionAt(page, '#to_station_id', 0);
        });
    }

    await selectOptionAt(page, 'select[name="goods[0][goods_type_id]"]', 0);
    await selectOptionAt(page, 'select[name="goods[0][package_type_id]"]', 0);
    await page
        .locator('input[name="goods[0][goods_description]"]')
        .fill(goodsDescription);
    await page
        .locator('input[name="goods[0][package_count]"]')
        .locator('visible=true')
        .fill('5');
    await page.getByLabel('Actual weight MT row 1').fill('1');
    await page.getByLabel('Charged weight MT row 1').fill('1');
    await page
        .locator('select[name="goods[0][freight_rate_type]"]')
        .selectOption('fixed_amount')
        .catch(async () => {
            await page
                .locator('select[name="goods[0][freight_rate_type]"]')
                .selectOption({ label: /Fixed/i });
        });
    await page
        .locator('input[name="goods[0][freight_rate]"]')
        .locator('visible=true')
        .fill('1500');

    await page.getByRole('button', { name: 'Save Bilty' }).click();
    await confirmFormDialog(page);

    await expect(page).toHaveURL(/\/lorry-receipts\/[^/]+$/, {
        timeout: 30_000,
    });
    await expect(page.getByText(/Partload/i).first()).toBeVisible({
        timeout: 15_000,
    });
    const lrPath = page.url();
    const heading = await page.locator('h1, h2').first().textContent();
    const bodyMatch = await page
        .getByText(/LR[-\s]?\w[\w-]*/i)
        .first()
        .textContent()
        .catch(() => null);
    const lrNumber =
        heading?.match(/LR[-\s]?\w[\w-]*/i)?.[0] ??
        bodyMatch?.match(/LR[-\s]?\w[\w-]*/i)?.[0] ??
        lrPath.split('/').pop() ??
        null;

    return { goodsDescription, lrNumber, lrPath };
}

export async function createAndDispatchPartloadChallan(
    page: Page,
    lrNumbers: string[],
): Promise<string> {
    // Load unfiltered eligible list first so Partload bookings are visible.
    await page.goto('/partload/challans/create');
    await expect(page.getByText(/Create Challan/i).first()).toBeVisible({
        timeout: 20_000,
    });

    const date = page.locator('#challan_date');
    if ((await date.count()) > 0) {
        await date.fill(todayIsoDate());
    }

    const eligibleRows = page.locator(
        'table tbody tr input[type="checkbox"][aria-label^="Select"]',
    );
    await expect(eligibleRows.first()).toBeVisible({ timeout: 20_000 });

    const firstRow = page.locator('table tbody tr').first();
    const routeText = (
        (await firstRow.locator('td').nth(3).textContent()) ?? ''
    )
        .replace(/\s+/g, ' ')
        .trim();
    const [fromName, toName] = routeText
        .split('→')
        .map((part) => part.trim())
        .filter(Boolean);

    if (fromName) {
        await page
            .locator('#from_station_id')
            .selectOption({ label: fromName });
    }
    if (toName && toName !== fromName) {
        await page.locator('#to_station_id').selectOption({ label: toName });
    }

    await expect(eligibleRows.first()).toBeVisible({ timeout: 20_000 });

    if ((await page.locator('#vehicle_id').count()) > 0) {
        await selectOptionAt(page, '#vehicle_id', 0);
    }
    if ((await page.locator('#driver_party_id').count()) > 0) {
        await selectOptionAt(page, '#driver_party_id', 0);
    }

    const rowCount = await eligibleRows.count();
    const selectCount = Math.min(Math.max(lrNumbers.length, 1), rowCount);
    for (let index = 0; index < selectCount; index++) {
        await eligibleRows.nth(index).check();
    }

    const truckFreight = page.locator(
        '#truck_freight, input[name="truck_freight"]',
    );
    if ((await truckFreight.count()) > 0) {
        await truckFreight.first().fill('2500');
    }

    const dispatchSave = page.getByRole('button', {
        name: /Save & Dispatch/i,
    });
    if ((await dispatchSave.count()) > 0 && (await dispatchSave.isEnabled())) {
        await dispatchSave.click();
    } else {
        await page.getByRole('button', { name: /Save Draft/i }).click();
    }
    await confirmFormDialog(page);

    await expect(page).toHaveURL(/\/partload\/challans\/[^/]+$/, {
        timeout: 30_000,
    });
    const challanPath = page.url();

    const dispatch = page.getByRole('button', { name: /^Dispatch$/i });
    if ((await dispatch.count()) > 0 && (await dispatch.isEnabled())) {
        await dispatch.first().click();
        await confirmFormDialog(page);
        await expect(
            page.getByText(/Dispatched|In Transit/i).first(),
        ).toBeVisible({
            timeout: 20_000,
        });
    }

    return challanPath;
}

export async function createInwardFromChallan(
    page: Page,
    challanPath: string,
): Promise<string> {
    await page.goto(challanPath);
    const inward = page.getByRole('button', {
        name: /Create Inward|Inward Register/i,
    });
    if ((await inward.count()) > 0) {
        await inward.first().click();
        await confirmFormDialog(page);
    } else {
        await page.goto('/partload/in-transit');
        await expect(page.getByText(/In Transit/i).first()).toBeVisible({
            timeout: 20_000,
        });
        const open = page
            .getByRole('link', { name: /View|Open|Inward/i })
            .first();
        if ((await open.count()) > 0) {
            await open.click();
        }
        const createInward = page.getByRole('button', {
            name: /Create Inward|Receive/i,
        });
        if ((await createInward.count()) > 0) {
            await createInward.first().click();
            await confirmFormDialog(page);
        }
    }

    await expect(page).toHaveURL(/\/partload\/inwards\/[^/]+$/, {
        timeout: 30_000,
    });
    return page.url();
}

export async function createDeliveryReceiptFromPending(
    page: Page,
    lrNumber: string | null,
): Promise<void> {
    await page.goto('/partload/pending-delivery');
    await expect(page.getByText(/Pending Delivery/i).first()).toBeVisible({
        timeout: 20_000,
    });

    const row = lrNumber
        ? page.locator('tr').filter({ hasText: lrNumber }).first()
        : page.locator('table tbody tr').first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await row
        .getByRole('link', { name: /Delivery|DR|Create/i })
        .first()
        .click();

    await expect(page.getByText(/Delivery Receipt/i).first()).toBeVisible({
        timeout: 20_000,
    });

    const mode = page.locator('#payment_mode, select[name="payment_mode"]');
    if ((await mode.count()) > 0) {
        await mode
            .first()
            .selectOption({ label: /Due/i })
            .catch(async () => {
                await mode.first().selectOption('due');
            });
    }

    if ((await page.locator('#payment_due_from_party_id').count()) > 0) {
        await selectOptionAt(page, '#payment_due_from_party_id', 0);
    }

    const save = page
        .getByRole('button', { name: /Create|Save|Post/i })
        .first();
    await save.click();
    await confirmFormDialog(page);
}
