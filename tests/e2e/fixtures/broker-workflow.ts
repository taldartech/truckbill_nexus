import { expect, type Page } from '@playwright/test';
import { demoCredentials, loginAs } from './auth';
import { enterDemoAlphaBranch } from './demo-workspace';

export type BrokerLrContext = {
    goodsDescription: string;
    lrNumber: string | null;
    lrPath: string;
    vehicleId: string | null;
};

function todayIsoDate(): string {
    return new Date().toISOString().slice(0, 10);
}

/**
 * Shared FormConfirmationDialog — click Confirm create/save when present.
 * If navigation already completed (dialog skipped), return without failing.
 */
async function confirmFormDialog(page: Page): Promise<void> {
    const confirm = page.getByRole('button', {
        name: /Confirm (create|save|delete)/i,
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
    await expect(field).toBeVisible();
    const option = field.locator('option[value]:not([value=""])').nth(index);
    const value = await option.getAttribute('value');
    if (!value) {
        throw new Error(`No option at index ${index} for ${selector}`);
    }
    await field.selectOption(value);
}

async function selectMarketVehicle(
    page: Page,
    vehicleId?: string,
): Promise<string> {
    const vehicle = page.locator('#vehicle_id');
    await expect(vehicle).toBeVisible();
    if (vehicleId) {
        await vehicle.selectOption(vehicleId);
        return vehicleId;
    }
    const options = vehicle.locator('option[value]:not([value=""])');
    const count = await options.count();
    for (let i = 0; i < count; i += 1) {
        const text = (await options.nth(i).textContent()) ?? '';
        if (/\bMarket\b/i.test(text) && !/\bOwn\b/i.test(text)) {
            const value = await options.nth(i).getAttribute('value');
            if (value) {
                await vehicle.selectOption(value);
                return value;
            }
        }
    }
    throw new Error('No Market vehicle option in LR create form.');
}

export async function loginAndEnterBrokerBranch(page: Page): Promise<void> {
    const { email, password } = demoCredentials();
    await loginAs(page, email, password);
    await enterDemoAlphaBranch(page);
}

/**
 * Creates a pending Broker LR (market vehicle, text route, parties, fixed freight).
 */
export async function createBrokerLrViaUi(
    page: Page,
    goodsDescription: string,
    options?: { vehicleId?: string },
): Promise<BrokerLrContext> {
    await page.goto('/lorry-receipts');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'Bilty Register',
    );
    await page.getByTestId('create-lr-direct').click();
    await expect(page).toHaveURL(/\/lorry-receipts\/create/);
    await expect(page.locator('#number_mode')).toHaveCount(0);

    const vehicleId = await selectMarketVehicle(page, options?.vehicleId);
    await expect(page.locator('#from_text')).toBeVisible();

    await page.locator('#from_text').fill('E2E Broker origin');
    await page.locator('#to_text').fill('E2E Broker destination');
    await selectOptionAt(page, '#billing_customer_party_id', 0);
    await selectOptionAt(page, '#consignor_party_id', 0);
    await selectOptionAt(page, '#consignee_party_id', 0);
    await page.locator('#lr_date').fill(todayIsoDate());

    await selectOptionAt(page, 'select[name="goods[0][goods_type_id]"]', 0);
    await selectOptionAt(page, 'select[name="goods[0][package_type_id]"]', 0);
    const descriptionInput = page.locator(
        'input[name="goods[0][goods_description]"]',
    );
    if (goodsDescription.trim() !== '') {
        await descriptionInput.fill(goodsDescription);
    } else {
        await descriptionInput.fill('');
    }
    await page.locator('input[name="goods[0][package_count]"]').fill('1');
    // Operational entry is MT; 8.000 MT → 8000 KG canonical storage.
    await page.getByLabel('Actual weight MT row 1').fill('8');
    await page.getByLabel('Charged weight MT row 1').fill('8');
    await page
        .locator('select[name="goods[0][freight_rate_type]"]')
        .selectOption('per_mt')
        .catch(async () => {
            await page
                .locator('select[name="goods[0][freight_rate_type]"]')
                .selectOption({ label: /Per MT/i });
        });
    await page.locator('input[name="goods[0][freight_rate]"]').fill('9000');

    await page.getByRole('button', { name: 'Save Bilty' }).click();
    await confirmFormDialog(page);
    await expect(page).toHaveURL(/\/lorry-receipts\/[0-9a-f-]+$/, {
        timeout: 30_000,
    });

    const lrPath = new URL(page.url()).pathname;
    const heading = await page.getByRole('heading', { level: 1 }).innerText();
    const lrMatch = heading.match(/^(?:LR|Bilty)\s+(.+)$/i);
    const lrNumber = lrMatch?.[1]?.trim() ?? null;

    return { goodsDescription, lrNumber, lrPath, vehicleId };
}

export type LorryHireContext = {
    hireNumber: string;
    hirePath: string;
};

export async function createLorryHireFromLr(
    page: Page,
    options?: {
        transporterRate?: string;
        loadingWeightKg?: string;
        rateType?: string;
    },
): Promise<LorryHireContext> {
    await page.getByRole('tab', { name: 'Actions' }).click();
    await page.getByRole('link', { name: 'Manage Lorry Hire' }).click();
    await expect(page).toHaveURL(/\/create-lorry-hire/);
    await expect(
        page.getByRole('heading', { name: 'Create Lorry Hire' }),
    ).toBeVisible();

    const rateType = options?.rateType ?? 'per_mt';
    const rateInput = page.locator('#rate_0');
    await expect(rateInput).toBeVisible();
    await page.locator('#rate_type_0').selectOption(rateType);
    await rateInput.fill(options?.transporterRate ?? '7500');

    if (options?.loadingWeightKg !== undefined) {
        // UI expects MT; callers may still pass KG strings for backward compatibility.
        const raw = options.loadingWeightKg;
        const asNumber = Number(raw);
        const mt =
            Number.isFinite(asNumber) && asNumber >= 100
                ? (asNumber / 1000).toFixed(3)
                : raw;
        await page.locator('#loading_weight_0').fill(mt);
    }

    await expect(page.getByText('Financial summary')).toBeVisible();

    await page.getByRole('button', { name: 'Create Lorry Hire' }).click();
    await confirmFormDialog(page);
    await expect(page).toHaveURL(/\/broker\/lorry-hires\/[0-9a-f-]+$/, {
        timeout: 30_000,
    });
    const hirePath = new URL(page.url()).pathname;
    const title = await page.getByRole('heading', { level: 1 }).innerText();
    const match = title.match(/^Lorry Hire\s+(.+)$/i);
    const hireNumber = match?.[1]?.trim() ?? '';
    return { hireNumber, hirePath };
}

export async function createAdjustInAdvanceVoucher(
    page: Page,
    hireNumber: string,
    amount: string,
): Promise<string> {
    await page.goto('/broker/vouchers/create');
    await expect(
        page.getByRole('heading', { name: 'Create Transporter Voucher' }),
    ).toBeVisible();

    const hireSelect = page.locator('#lorry_hire_id');
    const option = hireSelect
        .locator('option[value]:not([value=""])')
        .filter({ hasText: hireNumber })
        .first();
    const hireId = await option.getAttribute('value');
    if (!hireId) {
        throw new Error(
            `Lorry hire ${hireNumber} not in voucher eligible list.`,
        );
    }
    await hireSelect.selectOption(hireId);

    await page.locator('#amount').fill(amount);
    await page.locator('#payment_mode').selectOption('adjust_in_advance');
    await page.locator('#remark').fill('E2E adjust in advance voucher');

    await page.getByRole('button', { name: 'Save voucher' }).click();
    await confirmFormDialog(page);
    await expect(page).toHaveURL(/\/broker\/vouchers\/[0-9a-f-]+$/, {
        timeout: 30_000,
    });
    const heading = await page.getByRole('heading', { level: 1 }).innerText();
    const match = heading.match(/^Voucher\s+(.+)$/i);
    return match?.[1]?.trim() ?? '';
}

export async function assertTransporterBookBalanceHasActivity(
    page: Page,
): Promise<void> {
    await page.goto('/broker/transporter-balances');
    await expect(
        page.getByRole('heading', { name: 'Transporter Book Balance' }),
    ).toBeVisible({ timeout: 15_000 });

    const transporterSelect = page.locator('#transporter_party_id');
    const firstTransporter = transporterSelect
        .locator('option[value]:not([value=""])')
        .first();
    const transporterId = await firstTransporter.getAttribute('value');
    if (!transporterId) {
        throw new Error('No transporter options for book balance.');
    }
    await transporterSelect.selectOption(transporterId);
    await page.getByRole('button', { name: 'View statement' }).click();
    await expect(page.getByText('Running balance').first()).toBeVisible({
        timeout: 15_000,
    });
}

export async function assignMemoAndDeliverLr(
    page: Page,
    lrNumber: string,
    memoNumber: string,
): Promise<void> {
    await page.goto('/broker/deliveries');
    await expect(
        page.getByRole('heading', { name: 'Delivery, Memo & POD' }),
    ).toBeVisible({ timeout: 15_000 });

    const checkbox = page.getByRole('checkbox', {
        name: `Select ${lrNumber}`,
    });
    await expect(checkbox).toBeVisible({ timeout: 20_000 });
    await checkbox.check();

    await page.locator('#memo_number').fill(memoNumber);
    await page.locator('#pod_number').fill(`POD-${memoNumber}`);
    await page.getByRole('button', { name: 'Assign memo to selected' }).click();
    await confirmFormDialog(page);

    const eligibleRow = page
        .locator('table tbody tr')
        .filter({
            has: page.getByRole('cell', { name: lrNumber, exact: true }),
        })
        .first();
    await expect(eligibleRow.getByText(memoNumber)).toBeVisible({
        timeout: 20_000,
    });

    await eligibleRow.getByRole('button', { name: 'Deliver' }).click();

    const recentItem = page
        .locator('li')
        .filter({ hasText: lrNumber })
        .filter({ hasText: memoNumber })
        .first();
    await expect(recentItem.getByText(/delivered/i).first()).toBeVisible({
        timeout: 20_000,
    });
}

export async function uploadPodIfAvailable(
    page: Page,
    lrNumber: string,
): Promise<void> {
    const recentBlock = page
        .locator('li')
        .filter({ hasText: lrNumber })
        .first();
    if ((await recentBlock.count()) === 0) {
        return;
    }
    const uploadButton = recentBlock.getByRole('button', {
        name: 'Upload POD file',
    });
    if ((await uploadButton.count()) === 0) {
        return;
    }
    const fileChooserPromise = page.waitForEvent('filechooser');
    await uploadButton.click();
    const fileChooser = await fileChooserPromise;
    await fileChooser.setFiles({
        name: 'e2e-pod.png',
        mimeType: 'image/png',
        buffer: Buffer.from(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            'base64',
        ),
    });
    await recentBlock
        .locator('form')
        .getByRole('button', { name: 'Upload POD', exact: true })
        .click();
    await expect(
        recentBlock.getByRole('button', { name: /Documents/i }),
    ).toBeVisible({
        timeout: 20_000,
    });
}

export async function addBrokerLorryHireAdjustment(
    page: Page,
    amount: string,
): Promise<void> {
    await page.getByRole('tab', { name: 'Adjustments' }).click();
    await page.locator('#adj_type').selectOption('detention');
    await page.locator('#adj_amount').fill(amount);
    await page.locator('#adj_reason').fill('E2E detention adjustment');
    await page.getByRole('button', { name: 'Save adjustment' }).click();
    await confirmFormDialog(page);
    await expect(
        page.getByRole('cell', { name: 'detention', exact: true }),
    ).toBeVisible({ timeout: 15_000 });
}

export async function createBrokerCustomerInvoice(
    page: Page,
    lrNumber: string,
): Promise<string> {
    await page.goto('/broker/invoices/create');
    await expect(
        page.getByRole('heading', { name: 'Create Customer Invoice' }),
    ).toBeVisible({ timeout: 15_000 });

    const lrLabel = page.locator('label').filter({ hasText: lrNumber }).first();
    await expect(lrLabel).toBeVisible({ timeout: 20_000 });
    await lrLabel.locator('input[type="checkbox"]').check();

    const customerSelect = page.locator('#billing_customer_party_id');
    const firstCustomer = customerSelect
        .locator('option[value]:not([value=""])')
        .first();
    const customerId = await firstCustomer.getAttribute('value');
    if (!customerId) {
        throw new Error('No billing customer for broker invoice.');
    }
    await customerSelect.selectOption(customerId);

    await page.locator('input[name="invoice_date"]').fill(todayIsoDate());
    await page.getByRole('button', { name: 'Issue invoice' }).click();
    await confirmFormDialog(page);
    await expect(page).toHaveURL(/\/broker\/invoices\/[0-9a-f-]+$/, {
        timeout: 30_000,
    });
    const title = await page.getByRole('heading', { level: 1 }).innerText();
    const match = title.match(/^Invoice\s+(.+)$/i);
    return match?.[1]?.trim() ?? '';
}

export async function openBrokerInvoicePrintPreview(page: Page): Promise<void> {
    await page.getByRole('link', { name: 'Print / Preview' }).click();
    await expect(page).toHaveURL(/\/broker\/invoices\/[0-9a-f-]+\/print/, {
        timeout: 15_000,
    });
    await expect(page.getByText(/sample|invoice/i).first()).toBeVisible();
}

export async function recordBrokerInvoicePayments(
    page: Page,
    partialAmount: string,
): Promise<void> {
    await page.getByRole('tab', { name: 'Payments' }).click();
    await page.locator('input[name="amount_received"]').fill(partialAmount);
    await page.locator('input[name="tds_amount"]').fill('0');
    await page.locator('input[name="deduction_amount"]').fill('0');
    await page.getByRole('button', { name: 'Save payment' }).click();
    await expect(page.getByText(partialAmount).first()).toBeVisible({
        timeout: 15_000,
    });

    const balanceDue = page.getByText('Balance due', { exact: true });
    if (await balanceDue.isVisible()) {
        const balanceText =
            (await balanceDue.locator('xpath=../..').textContent()) ?? '';
        const numeric = balanceText.match(/[\d,.]+/)?.[0];
        if (numeric && numeric !== '0' && numeric !== '0.00') {
            await page.locator('input[name="amount_received"]').fill(numeric);
            await page.getByRole('button', { name: 'Save payment' }).click();
        }
    }
}

export async function createLinkedBiltyGroup(
    page: Page,
    vehicleId: string,
    lrNumbers: [string, string],
): Promise<string> {
    await page.goto('/broker/linked-bilties/create');
    await expect(
        page.getByRole('heading', { name: 'Create Linked Bilty Group' }),
    ).toBeVisible({ timeout: 15_000 });

    await page.locator('#vehicle_id').selectOption(vehicleId);
    await page.locator('#group_date').fill(todayIsoDate());

    for (const lrNumber of lrNumbers) {
        const row = page.locator('tbody tr').filter({ hasText: lrNumber });
        await expect(row.first()).toBeVisible({ timeout: 20_000 });
        await row.first().locator('input[type="checkbox"]').check();
    }

    await page
        .getByRole('button', { name: 'Create linked bilty group' })
        .click();
    await confirmFormDialog(page);
    await expect(page).toHaveURL(/\/broker\/linked-bilties\/[0-9a-f-]+$/, {
        timeout: 30_000,
    });
    const title = await page.getByRole('heading', { level: 1 }).innerText();
    return title.trim();
}

export async function recordLinkedBiltyGroupPayment(
    page: Page,
    amount: string,
): Promise<void> {
    await expect(
        page.getByRole('heading', { name: 'Group payment' }),
    ).toBeVisible({ timeout: 15_000 });

    await page.locator('#amount').fill(amount);
    await page.locator('#voucher_date').fill(todayIsoDate());
    await page.locator('#payment_mode').selectOption('cash');
    await page.locator('#remark').fill('E2E linked group payment');

    await page.getByRole('button', { name: 'Preview allocation' }).click();
    await expect(page.getByText(/allocation|eligible/i).first()).toBeVisible({
        timeout: 20_000,
    });

    await page.getByRole('button', { name: 'Record group payment' }).click();
    await expect(page.getByText(/payment|voucher/i).first()).toBeVisible({
        timeout: 20_000,
    });
}
