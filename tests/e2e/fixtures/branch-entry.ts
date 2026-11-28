import { expect, type Page } from '@playwright/test';

/**
 * Confirms the operational branch workspace switch (legacy parity dialog).
 */
export async function confirmBranchEntryDialog(page: Page): Promise<void> {
    const dialog = page.getByRole('dialog');
    await expect(
        dialog.getByRole('heading', { name: /Enter this branch/i }),
    ).toBeVisible({ timeout: 10_000 });
    await dialog.getByRole('button', { name: /^Enter branch$/i }).click();
}

/**
 * Owner Branches register: filter company, enter branch with confirmation, land on Dashboard.
 */
export async function enterBranchPanelFromOwnerBranches(
    page: Page,
    companyName: string,
): Promise<void> {
    await page.goto('/management/branches');
    await expect(page.getByRole('heading', { name: 'Branches' })).toBeVisible({
        timeout: 20_000,
    });

    await page.locator('#company').selectOption({ label: companyName });
    await page.getByRole('button', { name: /^Apply filters$/i }).click();

    const row = page
        .locator('table tbody tr')
        .filter({ hasText: companyName })
        .first();
    await expect(row).toBeVisible({ timeout: 20_000 });

    const enter = row.getByRole('button', { name: 'Enter Branch Panel' });
    await expect(enter).toBeVisible();

    await enter.click();
    await confirmBranchEntryDialog(page);

    await expect(page).toHaveURL(/\/operations\/?$/, { timeout: 30_000 });
    await expect(page.getByText(new RegExp(companyName, 'i')).first()).toBeVisible({
        timeout: 20_000,
    });
}