import { expect, type Page } from '@playwright/test';

export const e2ePassword = (): string =>
    process.env.E2E_PASSWORD ?? process.env.LOCAL_DEMO_PASSWORD ?? 'password';

export const e2eEmails = {
    owner: 'e2e.owner@truckbill.test',
    noCompany: 'e2e.nocompany@truckbill.test',
    internal: 'e2e.internal@truckbill.test',
} as const;

async function selectGeography(
    page: Page,
    fieldId: string,
    search: string,
    optionLabel: RegExp,
): Promise<void> {
    await page.locator(`#${fieldId}-trigger`).click();
    const searchBox = page.getByPlaceholder(new RegExp(`Search`, 'i')).last();
    await searchBox.fill(search);
    const option = page
        .getByRole('listbox')
        .getByRole('option')
        .filter({ hasText: optionLabel })
        .first();
    await expect(option).toBeVisible({ timeout: 10_000 });
    await option.click();
}

export async function fillCompanyCreateForm(
    page: Page,
    opts: { name: string; pan: string; gstin: string },
): Promise<void> {
    await page.locator('#name').fill(opts.name);
    await page.locator('#pan').fill(opts.pan);
    await page
        .locator('#registered_address')
        .fill('12 E2E Logistics Park, Mumbai');
    await selectGeography(page, 'state_id', 'Maharashtra', /^Maharashtra$/i);
    await selectGeography(page, 'city_id', 'Mumbai', /Mumbai/i);
    await page.locator('#pin').fill('400001');
    await page.locator('#mobile').fill('9876543210');
    await page.locator('#gstin').fill(opts.gstin);

    const createBranch = page.locator('input[name="create_initial_branch"]');
    if ((await createBranch.count()) > 0) {
        await createBranch.check({ force: true });
    }

    await page.getByRole('button', { name: /^create company$/i }).click();
}

export async function expectOwnerDashboard(page: Page): Promise<void> {
    await expect(page).toHaveURL(
        /\/(management|owner)\/companies\/(?!create)[^/]+\/?$/,
        {
            timeout: 20_000,
        },
    );
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({
        timeout: 15_000,
    });
}

export function uniqueCompanyIds(prefix: string): {
    name: string;
    pan: string;
    gstin: string;
} {
    const stamp = Date.now().toString().slice(-4).padStart(4, '0');
    // PAN must be AAAAA9999A (five letters — no digits in the letter block).
    const pan = `ABCDE${stamp}F`;
    const gstin = `27${pan}1Z5`;

    return {
        name: `${prefix} ${stamp}`,
        pan,
        gstin,
    };
}

export function companySwitcherTrigger(page: Page) {
    return page.getByRole('button', { name: /open company menu/i }).first();
}
