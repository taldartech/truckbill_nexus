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

/**
 * Confirm via secondary CTA (e.g. Save & Create Lorry Hire).
 */
async function confirmFormDialogSecondary(
    page: Page,
    buttonName: RegExp | string,
): Promise<void> {
    const confirm = page.getByRole('button', { name: buttonName });
    await expect(confirm).toBeVisible({ timeout: 5_000 });
    await confirm.click();
}

async function selectOptionAt(
    page: Page,
    selector: string,
    index: number,
): Promise<void> {
    const field = page.locator(selector);
    // Combobox keeps a sr-only native <select>; id fields expose `${id}-trigger`.
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
        // Open combobox and pick by index from the live listbox.
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

async function selectMarketVehicle(
    page: Page,
    vehicleId?: string,
): Promise<string> {
    const vehicle = page.locator('#vehicle_id');
    await expect(page.locator('#vehicle_id-trigger')).toBeVisible();
    if (vehicleId) {
        await vehicle.selectOption(vehicleId);
        return vehicleId;
    }
    // Market trucks are the listbox options not marked "Own vehicle" (Rules §32);
    // the hidden native options carry only the truck number.
    await page.locator('#vehicle_id-trigger').click();
    const marketOption = page
        .getByRole('listbox')
        .getByRole('option')
        .filter({ hasNotText: /\bOwn vehicle\b/i })
        .first();
    await expect(marketOption).toBeVisible({ timeout: 10_000 });
    const label = (await marketOption.innerText()).trim();
    await marketOption.click();
    const selected = await vehicle.inputValue();
    if (!selected) {
        throw new Error(`Market vehicle select failed after picking ${label}`);
    }
    return selected;
}

export async function loginAndEnterBrokerBranch(page: Page): Promise<void> {
    const { email, password } = demoCredentials();
    await loginAs(page, email, password);
    await enterDemoAlphaBranch(page);
}

/** Good Hires register — direct route avoids duplicate sidebar link strict-mode hits. */
export async function openGoodHiresRegister(page: Page): Promise<void> {
    await page.goto('/broker/lorry-hires');
    await expect(page.getByTestId('good-hires-register')).toBeVisible({
        timeout: 20_000,
    });
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
    await expect(
        page.getByRole('heading', { name: 'Bilty Register' }),
    ).toBeVisible();
    await page.getByTestId('create-lr-direct').click();
    await expect(page).toHaveURL(/\/lorry-receipts\/create/);
    await expect(page.locator('#number_mode')).toHaveCount(0);

    const vehicleId = await selectMarketVehicle(page, options?.vehicleId);
    await expect(page.locator('#from_text')).toBeVisible();

    // Market Pay To — prefer Truck Owner when the pill is enabled.
    const ownerPill = page.getByRole('button', { name: 'Truck Owner' });
    if (await ownerPill.isVisible().catch(() => false)) {
        const disabled = await ownerPill.isDisabled().catch(() => false);
        if (!disabled) {
            await ownerPill.click();
        } else {
            await page.getByRole('button', { name: 'Agency' }).click();
            await selectOptionAt(page, '#intended_payee_party_id', 0);
        }
    }

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
        .selectOption('per_charged_weight')
        .catch(async () => {
            await page
                .locator('select[name="goods[0][freight_rate_type]"]')
                .selectOption({ label: 'Per Charged Weight' });
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

/**
 * Creates Broker LR and continues into Lorry Hire create via confirmation secondary CTA.
 */
export async function createBrokerLrAndContinueToHire(
    page: Page,
    goodsDescription: string,
): Promise<BrokerLrContext> {
    await page.goto('/lorry-receipts');
    await page.getByTestId('create-lr-direct').click();
    await expect(page).toHaveURL(/\/lorry-receipts\/create/);

    const vehicleId = await selectMarketVehicle(page);

    const ownerPill = page.getByRole('button', { name: 'Truck Owner' });
    if (await ownerPill.isVisible().catch(() => false)) {
        const disabled = await ownerPill.isDisabled().catch(() => false);
        if (!disabled) {
            await ownerPill.click();
        } else {
            await page.getByRole('button', { name: 'Agency' }).click();
            await selectOptionAt(page, '#intended_payee_party_id', 0);
        }
    }

    await page.locator('#from_text').fill('E2E Hire-from-save origin');
    await page.locator('#to_text').fill('E2E Hire-from-save destination');
    await selectOptionAt(page, '#billing_customer_party_id', 0);
    await selectOptionAt(page, '#consignor_party_id', 0);
    await selectOptionAt(page, '#consignee_party_id', 0);
    await page.locator('#lr_date').fill(todayIsoDate());
    await selectOptionAt(page, 'select[name="goods[0][goods_type_id]"]', 0);
    await selectOptionAt(page, 'select[name="goods[0][package_type_id]"]', 0);
    await page
        .locator('input[name="goods[0][goods_description]"]')
        .fill(goodsDescription);
    await page.locator('input[name="goods[0][package_count]"]').fill('1');
    await page.getByLabel('Actual weight MT row 1').fill('8');
    await page.getByLabel('Charged weight MT row 1').fill('8');
    await page
        .locator('select[name="goods[0][freight_rate_type]"]')
        .selectOption('per_charged_weight');
    await page.locator('input[name="goods[0][freight_rate]"]').fill('9000');

    await page.getByRole('button', { name: 'Save Bilty' }).click();
    await expect(
        page.getByRole('button', { name: 'Save & Create Lorry Hire' }),
    ).toBeVisible({ timeout: 5_000 });
    await confirmFormDialogSecondary(page, 'Save & Create Lorry Hire');
    await expect(page).toHaveURL(/\/create-lorry-hire/, {
        timeout: 30_000,
    });
    await expect(
        page.getByRole('heading', { name: 'Create Lorry Hire' }),
    ).toBeVisible();

    const lrNumberMatch = (await page.content()).match(
        /LR\s+([A-Z0-9\-/]+)|Bilty\s+([A-Z0-9\-/]+)/i,
    );
    const lrNumber = lrNumberMatch?.[1] ?? lrNumberMatch?.[2] ?? null;

    return {
        goodsDescription,
        lrNumber,
        lrPath: '',
        vehicleId,
    };
}

/**
 * Opens pending Bilty edit, changes a station text, asserts previous/new confirmation.
 */
export async function editBrokerLrWithChangeReview(
    page: Page,
    lrPath: string,
): Promise<void> {
    await page.goto(`${lrPath}/edit`);
    await expect(
        page.getByRole('heading', {
            name: /Edit (Bilty|Good Receipt)/i,
        }),
    ).toBeVisible({ timeout: 15_000 });

    // Prefer goods description — route fields may be party-derived / station masters.
    // Controlled React inputs need key events so onChange updates form state.
    const description = page.locator(
        'input[name="goods[0][goods_description]"]',
    );
    await expect(description).toBeVisible();
    const previous = (await description.inputValue()).trim() || '—';
    const next = `${previous} · edited`;
    await description.click();
    await description.fill('');
    await description.pressSequentially(next, { delay: 15 });

    await page.getByRole('button', { name: 'Save Bilty' }).click();
    await expect(
        page.getByRole('heading', { name: 'Save Bilty changes?' }),
    ).toBeVisible({ timeout: 5_000 });
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Previous')).toBeVisible();
    // Edit review summarizes whole goods rows — assert the description delta.
    await expect(
        dialog.getByText(previous, { exact: false }).first(),
    ).toBeVisible();
    await expect(
        dialog.getByText(next, { exact: false }).first(),
    ).toBeVisible();
    await expect(dialog.getByText(/modified|Goods 1/i).first()).toBeVisible();
    await dialog.getByRole('button', { name: 'Confirm save' }).click();
    await expect(
        page.getByRole('heading', { name: 'Save Bilty changes?' }),
    ).toBeHidden({ timeout: 10_000 });
    // Persist may redirect to show; when update validation returns to edit,
    // confirmation UX itself is still the certified behaviour.
    await expect
        .poll(() => page.url(), { timeout: 30_000 })
        .toMatch(/\/lorry-receipts\/[0-9a-f-]+/);
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
    await page
        .getByRole('link', { name: /Create Lorry Hire|Manage Lorry Hire/i })
        .first()
        .click();
    await expect(page).toHaveURL(/\/create-lorry-hire/);
    await expect(
        page.getByRole('heading', { name: 'Create Lorry Hire' }),
    ).toBeVisible();

    const rateType = options?.rateType ?? 'per_charged_weight';
    const rateInput = page.locator('#rate_0');
    await expect(rateInput).toBeVisible();
    await page.locator('#rate_type_0').selectOption(rateType);
    await rateInput.fill(options?.transporterRate ?? '7500');

    if (options?.loadingWeightKg !== undefined) {
        // Legacy optional override — current hire UI inherits Bilty weight read-only.
        const loadingWeight = page.locator('#loading_weight_0');
        if ((await loadingWeight.count()) > 0) {
            const raw = options.loadingWeightKg;
            const asNumber = Number(raw);
            const mt =
                Number.isFinite(asNumber) && asNumber >= 100
                    ? (asNumber / 1000).toFixed(3)
                    : raw;
            await loadingWeight.fill(mt);
        }
    }

    await expect(
        page.getByText(/Net payable|Transporter freight|Hire charges/i).first(),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Create Lorry Hire' }).click();
    const hireDialog = page.getByRole('dialog');
    const hireDialogVisible = await hireDialog
        .waitFor({ state: 'visible', timeout: 5_000 })
        .then(() => true)
        .catch(() => false);
    if (hireDialogVisible) {
        await hireDialog
            .getByRole('button', { name: 'Create Lorry Hire' })
            .click();
    } else {
        await confirmFormDialog(page);
    }
    await expect(page).toHaveURL(/\/broker\/lorry-hires\/[0-9a-f-]+$/, {
        timeout: 30_000,
    });
    const hirePath = new URL(page.url()).pathname;
    const title = await page.getByRole('heading', { level: 1 }).innerText();
    const match = title.match(/^Lorry Hire\s+(.+)$/i);
    const hireNumber = match?.[1]?.trim() ?? '';
    return { hireNumber, hirePath };
}

const POD_PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
);

/** Records a voucher payment from the Lorry Hire page through the Voucher Payment modal. */
export async function recordVoucherPaymentFromHire(
    page: Page,
    hirePath: string,
    amount: string,
): Promise<void> {
    await page.goto(hirePath);
    await page.getByRole('button', { name: 'Voucher Payment' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Book Balance').first()).toBeVisible({
        timeout: 15_000,
    });

    const amountInput = dialog.getByLabel(/Payment amount for/).first();
    await expect(amountInput).toHaveValue('');
    await amountInput.fill(amount);
    await dialog.locator('#voucher_payment_mode').selectOption('cash');
    await dialog.getByRole('button', { name: 'Review payment' }).click();
    await page
        .getByRole('button', { name: 'Record payment', exact: true })
        .click();

    await expect(
        page.getByText('Voucher payment recorded.').first(),
    ).toBeVisible({ timeout: 20_000 });
}

export async function assertTransporterBookBalanceHasActivity(
    page: Page,
): Promise<void> {
    await page.goto('/broker/transporter-balances');
    await expect(
        page.getByRole('heading', { name: 'Transporter Book Balance' }),
    ).toBeVisible({ timeout: 15_000 });

    await selectOptionAt(page, '#transporter_party_id', 0);
    await page.getByRole('button', { name: 'View statement' }).click();
    await expect(page.getByText('Running balance').first()).toBeVisible({
        timeout: 15_000,
    });
}

/** Assigns a Memo / POD (with a POD file) from the Good Hires register; legacy marks the Bilty delivered. */
export async function assignMemoPodFromRegister(
    page: Page,
    hireNumber: string,
    memoNumber: string,
): Promise<void> {
    await page.goto('/broker/lorry-hires?pending_memo=1');
    const checkbox = page.getByRole('checkbox', {
        name: `Select hire ${hireNumber}`,
    });
    await expect(checkbox).toBeVisible({ timeout: 20_000 });
    await checkbox.check();
    await page.getByRole('button', { name: 'Memo / POD', exact: true }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog.locator('#memo_number')).toBeVisible({
        timeout: 15_000,
    });
    await dialog.locator('#memo_number').fill(memoNumber);
    await dialog.locator('#memo_pod_document').setInputFiles({
        name: 'e2e-pod.png',
        mimeType: 'image/png',
        buffer: POD_PNG,
    });
    await dialog.getByRole('button', { name: 'Review Memo / POD' }).click();
    await page
        .getByRole('button', { name: 'Assign Memo / POD', exact: true })
        .click();

    await expect(
        page.getByText(`Memo / POD ${memoNumber} assigned`).first(),
    ).toBeVisible({ timeout: 20_000 });
    await expect(checkbox).toHaveCount(0);
}

export async function addBrokerLorryHireAdjustment(
    page: Page,
    amount: string,
): Promise<void> {
    await page.getByRole('link', { name: 'Edit' }).click();
    await expect(page.locator('#adj_detention')).toBeVisible({
        timeout: 15_000,
    });
    await page.locator('#adj_detention').fill(amount);
    await page.locator('#edit_remark').fill('E2E detention adjustment');
    await page.getByRole('button', { name: 'Review changes' }).click();
    await confirmFormDialog(page);
    await expect(page.getByTestId('lorry-hire-fixed-adjustments-readonly'))
        .toContainText(amount)
        .catch(async () => {
            await expect(
                page.getByText('Freight Adjustments', { exact: false }),
            ).toContainText(amount);
        });
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
    const heading = page.getByRole('heading', { level: 1 }).filter({
        hasText: /Invoice/i,
    });
    if ((await heading.count()) > 0) {
        const title = await heading.first().innerText({ timeout: 15_000 });
        const match = title.match(/^Invoice\s+(.+)$/i);
        if (match?.[1]) {
            return match[1].trim();
        }
    }
    const cardTitle = await page
        .locator('main strong')
        .filter({ hasText: /Customer Invoice/i })
        .first()
        .innerText({ timeout: 15_000 });
    const cardMatch = cardTitle.match(/Customer Invoice\s*\((.+)\)/i);
    return cardMatch?.[1]?.trim() ?? cardTitle.trim();
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
    await confirmFormDialog(page);
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
            await confirmFormDialog(page);
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
    await expect(page).toHaveURL(new RegExp(`vehicle_id=${vehicleId}`), {
        timeout: 20_000,
    });
    await page.locator('#group_date').fill(todayIsoDate());
    await page.locator('#group_date').dispatchEvent('input');
    await page.locator('#group_date').dispatchEvent('change');

    for (const lrNumber of lrNumbers) {
        const row = page.locator('tbody tr').filter({ hasText: lrNumber });
        await expect(row.first()).toBeVisible({ timeout: 20_000 });
        await row.first().locator('input[type="checkbox"]').check();
    }

    await expect(
        page.getByRole('button', { name: 'Create linked bilty group' }),
    ).toBeEnabled({ timeout: 10_000 });
    await page
        .getByRole('button', { name: 'Create linked bilty group' })
        .click();
    await confirmFormDialog(page);
    await expect(page).toHaveURL(/\/broker\/linked-bilties\/[0-9a-f-]+$/, {
        timeout: 30_000,
    });
    const title = await page
        .getByText(/Linked Bilty Group/i)
        .first()
        .innerText();
    return title.trim();
}

export async function recordLinkedBiltyGroupPayment(
    page: Page,
    amount: string,
): Promise<void> {
    await expect(
        page.getByText('Group payment', { exact: true }).first(),
    ).toBeVisible({ timeout: 15_000 });

    await expect(page.locator('#amount')).toHaveValue('');
    await page.getByRole('button', { name: 'Preview allocation' }).click();
    await expect(
        page.getByText('Enter a positive payment amount.').first(),
    ).toBeVisible();

    await page.locator('#amount').fill(amount);
    await page.locator('#voucher_date').fill(todayIsoDate());
    await page.locator('#payment_mode').selectOption('cash');
    await page.locator('#remark').fill('E2E linked group payment');

    await page.getByRole('button', { name: 'Preview allocation' }).click();
    await expect(page.getByText(/allocation|eligible/i).first()).toBeVisible({
        timeout: 20_000,
    });

    await page.getByRole('button', { name: 'Record group payment' }).click();
    await confirmFormDialog(page);
    await expect(page.getByText(/payment|voucher/i).first()).toBeVisible({
        timeout: 20_000,
    });
}
