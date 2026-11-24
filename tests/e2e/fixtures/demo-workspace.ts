import { expect, type Page } from '@playwright/test';
import { loginAs } from './auth';

export const DEMO_OWNER_EMAIL = 'owner.alpha@demo.test';

export const DEMO_OPERATOR_EMAIL = 'operator.alpha@demo.test';

export const DEMO_COMPANY_NAME = 'Demo Logistics Alpha';

export async function loginDemoOperator(
    page: Page,
    password: string,
): Promise<void> {
    await loginAs(page, DEMO_OPERATOR_EMAIL, password);
}

export async function enterDemoAlphaBranch(page: Page): Promise<void> {
    await page.goto('/management/branches');
    await expect(page.getByRole('heading', { name: 'Branches' })).toBeVisible({
        timeout: 20_000,
    });

    await page.locator('#company').selectOption({ label: DEMO_COMPANY_NAME });
    await page.getByRole('button', { name: /apply/i }).click();

    const alphaCompanyCell = page
        .locator('table tbody tr td')
        .filter({ hasText: DEMO_COMPANY_NAME })
        .first();
    await expect(alphaCompanyCell).toBeVisible({ timeout: 20_000 });

    await page
        .locator('table tbody tr')
        .filter({ hasText: DEMO_COMPANY_NAME })
        .first()
        .getByRole('button', { name: 'Enter Branch Panel' })
        .click();

    await expect(page).toHaveURL(/\/operations\/?$/, { timeout: 20_000 });
    await expect(page.getByText(/Demo Logistics Alpha/i).first()).toBeVisible({
        timeout: 20_000,
    });
}
