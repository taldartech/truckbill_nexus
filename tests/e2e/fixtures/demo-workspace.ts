import { expect, type Page } from '@playwright/test';
import { loginAs } from './auth';
import { loadBrokerJourneyAuth } from './broker-journey-auth';

export const DEMO_OWNER_EMAIL = 'owner.alpha@demo.test';

export const DEMO_OPERATOR_EMAIL = 'operator.alpha@demo.test';

export const DEMO_VIEWER_EMAIL = 'viewer.alpha@demo.test';

export const DEMO_COMPANY_NAME = 'Demo Logistics Alpha';

export async function loginDemoOperator(
    page: Page,
    password: string,
): Promise<void> {
    await loginAs(page, DEMO_OPERATOR_EMAIL, password);
}

async function postBranchContext(
    page: Page,
    companyId: string,
    branchId: string,
): Promise<boolean> {
    await page.goto('/management/branches');
    await expect(page.getByRole('heading', { name: 'Branches' })).toBeVisible({
        timeout: 20_000,
    });

    const status = await page.evaluate(
        async ({ companyId: company, branchId: branch }) => {
            const token =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '';

            const headers = {
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            };

            const companyResponse = await fetch(
                `/workspace/context/companies/${company}`,
                {
                    method: 'POST',
                    headers,
                    credentials: 'same-origin',
                },
            );

            if (companyResponse.status >= 400) {
                return companyResponse.status;
            }

            const branchResponse = await fetch(
                `/workspace/context/companies/${company}/branches/${branch}`,
                {
                    method: 'POST',
                    headers,
                    credentials: 'same-origin',
                },
            );

            return branchResponse.status;
        },
        { companyId, branchId },
    );

    if (status >= 400) {
        return false;
    }
    await page.goto('/operations');

    return true;
}

async function enterDemoAlphaBranchViaTable(page: Page): Promise<void> {
        await page.goto('/management/branches');
        await expect(page.getByRole('heading', { name: 'Branches' })).toBeVisible(
            { timeout: 20_000 },
        );

        await page.locator('#company').selectOption({ label: DEMO_COMPANY_NAME });
        await page.getByRole('button', { name: /apply/i }).click();

        const alphaCompanyCell = page
            .locator('table tbody tr td')
            .filter({ hasText: DEMO_COMPANY_NAME })
            .first();
        await expect(alphaCompanyCell).toBeVisible({ timeout: 20_000 });

        const contextResponse = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                response.url().includes('/workspace/context/companies/') &&
                response.url().includes('/branches/'),
        );

        await page
            .locator('table tbody tr')
            .filter({ hasText: DEMO_COMPANY_NAME })
            .first()
            .getByRole('button', { name: 'Enter Branch Panel' })
            .click();

        const posted = await contextResponse;
        expect(posted.status()).toBeLessThan(400);
}

export async function enterDemoAlphaBranch(page: Page): Promise<void> {
    const fixture = loadBrokerJourneyAuth();

    const entered =
        fixture.company_id && fixture.branch_id
            ? await postBranchContext(
                  page,
                  fixture.company_id,
                  fixture.branch_id,
              )
            : false;

    if (!entered) {
        await enterDemoAlphaBranchViaTable(page);
    }

    await expect(page).toHaveURL(/\/operations\/?$/, { timeout: 20_000 });
    await expect(page.getByText(/Demo Logistics Alpha/i).first()).toBeVisible({
        timeout: 20_000,
    });
}
