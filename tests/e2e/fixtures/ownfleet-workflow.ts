import { expect, type Page } from '@playwright/test';
import { demoCredentials, loginAs } from './auth';
import { enterDemoAlphaBranch } from './demo-workspace';

export const ownFleetDemoPassword = (): string => demoCredentials().password;

export async function loginAndEnterOwnFleetBranch(page: Page): Promise<void> {
    const { email, password } = demoCredentials();
    await loginAs(page, email, password);
    await enterDemoAlphaBranch(page);
}

function todayIsoDate(): string {
    return new Date().toISOString().slice(0, 10);
}

async function selectFirstOwnVehicle(page: Page): Promise<void> {
    const vehicle = page.locator('#vehicle_id');
    await expect(vehicle).toBeVisible();
    const options = vehicle.locator('option[value]:not([value=""])');
    const count = await options.count();
    let ownValue: string | null = null;
    for (let i = 0; i < count; i += 1) {
        const text = (await options.nth(i).textContent()) ?? '';
        if (/\bOwn\b/i.test(text) && !/\bMarket\b/i.test(text)) {
            ownValue = await options.nth(i).getAttribute('value');
            break;
        }
    }
    if (!ownValue) {
        throw new Error('No Own vehicle option in LR create form.');
    }
    const value = ownValue;
    await vehicle.selectOption(value);
}

async function selectOptionAt(
    page: Page,
    selector: string,
    index: number,
): Promise<void> {
    const field = page.locator(selector);
    await expect(field).toBeVisible();
    const option = field.locator('option[value]:not([value=""])').nth(index);
    const value = await option.getAttribute('value');
    if (!value) {
        throw new Error(`No option at index ${index} for ${selector}`);
    }
    await field.selectOption(value);
}

export type OwnFleetLrContext = {
    goodsDescription: string;
    lrNumber: string | null;
    lrPath: string;
};

/**
 * Creates a pending OwnFleet LR with billing customer, stations, and fixed freight.
 */
export async function createOwnFleetLrViaUi(
    page: Page,
    goodsDescription: string,
): Promise<OwnFleetLrContext> {
    await page.goto('/lorry-receipts');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'Lorry Receipts',
    );
    await page.getByTestId('create-lr-direct').click();
    await expect(page).toHaveURL(/\/lorry-receipts\/create/);

    await selectFirstOwnVehicle(page);
    await selectOptionAt(page, '#billing_customer_party_id', 0);
    await selectOptionAt(page, '#from_station_id', 0);
    await selectOptionAt(page, '#to_station_id', 1);
    await page.locator('#lr_date').fill(todayIsoDate());

    await selectOptionAt(page, 'select[name="goods[0][goods_type_id]"]', 0);
    await selectOptionAt(page, 'select[name="goods[0][package_type_id]"]', 0);
    await page
        .locator('input[name="goods[0][goods_description]"]')
        .fill(goodsDescription);
    await page.locator('input[name="goods[0][package_count]"]').fill('1');
    await page.locator('input[name="goods[0][actual_weight]"]').fill('1000');
    await page.locator('input[name="goods[0][charged_weight]"]').fill('1000');
    await page.locator('input[name="goods[0][freight_rate]"]').fill('15000');

    await page.getByRole('button', { name: 'Create LR' }).click();
    await expect(page).toHaveURL(/\/lorry-receipts\/[0-9a-f-]+$/, {
        timeout: 30_000,
    });

    const lrPath = new URL(page.url()).pathname;
    const heading = await page.getByRole('heading', { level: 1 }).innerText();
    const lrMatch = heading.match(/^LR\s+(.+)$/i);
    const lrNumber = lrMatch?.[1]?.trim() ?? null;

    return { goodsDescription, lrNumber, lrPath };
}

export async function openLrCreateTrip(page: Page): Promise<void> {
    await page.getByRole('tab', { name: 'Actions' }).click();
    await page.getByRole('link', { name: 'Create Trip' }).click();
    await expect(page).toHaveURL(/\/create-trip/);
    await expect(
        page.getByRole('heading', { name: 'Create OwnFleet Trip' }),
    ).toBeVisible();
}

export async function submitTripCreateFromLr(page: Page): Promise<void> {
    await expect(page.locator('#vehicle_display')).toHaveAttribute('readonly');
    await expect(page.locator('select#vehicle_id')).toHaveCount(0);
    await page.getByRole('button', { name: 'Create Trip' }).click();
    await expect(page).toHaveURL(/\/trips\/[0-9a-f-]+$/, { timeout: 30_000 });
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Trip');
}

export async function reconcileTripMovement(
    page: Page,
    options: { freight: string; weight: string },
): Promise<void> {
    await page.getByRole('tab', { name: 'Movement' }).click();

    await selectOptionAt(page, '#pickup_station_id', 0);
    await page
        .locator('form[action*="/pickups"] input[name="goods_description"]')
        .fill('E2E pickup');
    await page
        .locator('form[action*="/pickups"] input[name="weight"]')
        .fill(options.weight);
    await page
        .locator('form[action*="/pickups"] input[name="pickup_date"]')
        .fill(todayIsoDate());
    await page
        .locator('form[action*="/pickups"]')
        .getByRole('button', { name: 'Add pickup' })
        .click();
    await expect(page.getByText('E2E pickup')).toBeVisible({ timeout: 15_000 });

    await selectOptionAt(page, '#drop_station_id', 1);
    await page
        .locator('form[action*="/drops"]')
        .getByRole('button', { name: 'Add drop' })
        .click();
    await expect(page.locator('form[action*="/drops"]')).toBeVisible();

    await page
        .locator('form[action*="/allocations"] select[name="trip_pickup_id"]')
        .selectOption({ index: 1 });
    await page
        .locator('form[action*="/allocations"] select[name="trip_drop_id"]')
        .selectOption({ index: 1 });
    await page
        .locator('form[action*="/allocations"] input[name="allocation_weight"]')
        .fill(options.weight);
    await page
        .locator('form[action*="/allocations"] input[name="freight"]')
        .fill(options.freight);
    await page
        .locator('form[action*="/allocations"]')
        .getByRole('button', { name: 'Save allocation' })
        .click();
}

export async function dispatchAndCompleteTrip(
    page: Page,
    startingOdometer: string,
    endingOdometer: string,
): Promise<void> {
    await page.getByRole('tab', { name: 'Activity' }).click();
    await page.locator('#starting_odometer').fill(startingOdometer);
    await page.getByRole('button', { name: 'Dispatch trip' }).click();
    await expect(
        page.getByRole('button', { name: 'Complete trip' }),
    ).toBeVisible({ timeout: 20_000 });

    await page.locator('#ending_odometer').fill(endingOdometer);
    await page.getByRole('button', { name: 'Complete trip' }).click();
    await expect(
        page
            .locator('[data-status="completed"]')
            .or(page.getByText('Completed')),
    ).toBeVisible({ timeout: 20_000 });
}

const e2eTinyPng = {
    name: 'e2e-trip-doc.png',
    mimeType: 'image/png',
    buffer: Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        'base64',
    ),
};

/**
 * Uploads a file through the trip show DocumentDrawer (Documents tab).
 */
export async function uploadTripDocumentViaDrawer(
    page: Page,
    options: { label: string },
): Promise<void> {
    await page.getByRole('tab', { name: 'Documents' }).click();

    const manageDocs = page.getByRole('button', {
        name: 'Manage trip documents',
    });
    await expect(manageDocs).toBeVisible({ timeout: 15_000 });
    await manageDocs.click();

    const drawer = page.getByRole('dialog', { name: 'Trip documents' });
    await expect(drawer).toBeVisible({ timeout: 15_000 });

    await drawer.locator('#drawer-file').setInputFiles(e2eTinyPng);
    await drawer.locator('#drawer-label').fill(options.label);
    await drawer.locator('form:has(#drawer-file) button[type="submit"]').click();

    await expect(drawer.getByText(options.label).first()).toBeVisible({
        timeout: 30_000,
    });

    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden({ timeout: 10_000 });

    const documentsPanel = page
        .getByRole('tabpanel')
        .filter({ has: page.getByRole('button', { name: 'Manage trip documents' }) });
    await expect(documentsPanel.getByText(options.label).first()).toBeVisible({
        timeout: 15_000,
    });
}

export async function addDieselExpense(page: Page): Promise<void> {
    await page.getByRole('tab', { name: 'Expenses' }).click();
    await selectOptionAt(
        page,
        'form[action*="/expenses"] select[name="expense_category_id"]',
        0,
    );
    await page
        .locator('form[action*="/expenses"] input[name="expense_date"]')
        .fill(todayIsoDate());
    await page
        .locator('form[action*="/expenses"] input[name="is_diesel"]')
        .check();
    await page
        .locator('form[action*="/expenses"] input[name="diesel_litres"]')
        .fill('40');
    await page
        .locator(
            'form[action*="/expenses"] input[name="diesel_rate_per_litre"]',
        )
        .fill('95');
    await page
        .locator('form[action*="/expenses"] input[name="diesel_odometer"]')
        .fill('8800');
    await page
        .locator('form[action*="/expenses"]')
        .getByRole('button', { name: 'Add expense' })
        .click();
    await expect(page.getByText(/Diesel 40(?:\.000)? L/i)).toBeVisible({
        timeout: 15_000,
    });
}

export async function createTripInvoiceForLr(
    page: Page,
    lrNumber: string,
    goodsDescription?: string,
): Promise<string> {
    await page.goto('/trip-invoices/create');
    await expect(
        page.getByRole('heading', { name: 'Individual customer invoice' }),
    ).toBeVisible();
    const select = page.locator('select[name="lorry_receipt_id"]');
    const needle = goodsDescription ?? lrNumber;
    const option = select
        .locator('option[value]:not([value=""])')
        .filter({ hasText: needle })
        .first();
    const optionCount = await option.count();
    if (optionCount === 0) {
        const byLr = select
            .locator('option[value]:not([value=""])')
            .filter({ hasText: lrNumber })
            .first();
        const lrId = await byLr.getAttribute('value');
        if (!lrId) {
            throw new Error(
                `LR ${lrNumber} missing from invoice eligible list.`,
            );
        }
        await select.selectOption(lrId);
    } else {
        const lrId = await option.getAttribute('value');
        if (!lrId) {
            throw new Error(
                `LR matching ${needle} missing from invoice eligible list.`,
            );
        }
        await select.selectOption(lrId);
    }
    await page.locator('input[name="invoice_date"]').fill(todayIsoDate());
    await page.getByRole('button', { name: 'Create invoice' }).click();
    await expect(page).toHaveURL(/\/trip-invoices\/[0-9a-f-]+$/, {
        timeout: 30_000,
    });
    const title = await page.getByRole('heading', { level: 1 }).textContent();
    return title?.replace(/^Invoice\s*/i, '').trim() ?? '';
}

async function openPaymentForm(page: Page): Promise<void> {
    const record = page.getByRole('button', { name: 'Record payment' });
    if (await record.isVisible()) {
        await record.click();
    }
}

export async function recordTripInvoicePayments(
    page: Page,
    partialAmount: string,
): Promise<void> {
    await page.getByRole('tab', { name: 'Payments' }).click();
    await openPaymentForm(page);
    await page.locator('#amount_received').fill(partialAmount);
    await page.locator('#tds_amount').fill('0');
    await page.getByRole('button', { name: 'Save payment' }).click();
    await expect(page.getByText(partialAmount).first()).toBeVisible({
        timeout: 15_000,
    });

    await openPaymentForm(page);
    await page.locator('input[name="fill_net"]').check();
    await page.getByRole('button', { name: 'Save payment' }).click();

    await expect(page.getByText('partial', { exact: false })).toBeHidden({
        timeout: 30_000,
    });
}
