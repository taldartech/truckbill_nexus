import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';
import { demoCredentials, loginAs } from '../fixtures/auth';
import { enterDemoAlphaBranch } from '../fixtures/demo-workspace';

const screenshotDir = path.resolve(
    process.cwd(),
    'docs/engineering/portal-ux-screenshots',
);

function ensureScreenshotDir(): void {
    fs.mkdirSync(screenshotDir, { recursive: true });
}

async function capture(page: import('@playwright/test').Page, name: string) {
    ensureScreenshotDir();
    await page.screenshot({
        path: path.join(screenshotDir, `${name}.png`),
        fullPage: true,
    });
}

test.describe('Portal UX visual certification', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_DEMO !== '1',
            'Set E2E_REQUIRE_DEMO=1 after LocalDemoWorkspaceSeeder.',
        );
    });

    test('journey — management icons, branch nav, optional broker/partload', async ({
        page,
    }) => {
        const { email, password } = demoCredentials();
        await loginAs(page, email, password);

        await page.goto('/management/companies');
        await expect(
            page.getByRole('heading', { name: 'Companies' }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Edit company' }).first(),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Manage branches' }).first(),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Manage users' }).first(),
        ).toBeVisible();
        await capture(page, 'owner-companies-desktop');

        await page.setViewportSize({ width: 1280, height: 720 });
        await expect(
            page.getByRole('button', { name: 'Show row details' }),
        ).toHaveCount(0);

        await page.goto('/management/users');
        await expect(
            page.getByRole('heading', { name: 'Users' }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Manage membership' }).first(),
        ).toBeVisible();
        await expect(page.getByRole('link', { name: /^Manage$/i })).toHaveCount(
            0,
        );
        await capture(page, 'global-users-desktop');

        await page.goto('/management/audit');
        await expect(
            page.getByRole('heading', { name: 'Audit' }),
        ).toBeVisible();
        const companyAuditAction = page
            .getByRole('link', { name: 'Open company audit' })
            .first();
        if ((await companyAuditAction.count()) > 0) {
            await expect(companyAuditAction).toBeVisible();
        }
        await capture(page, 'global-audit-desktop');

        await enterDemoAlphaBranch(page);

        await page.goto('/goods-types');
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible({
            timeout: 20_000,
        });
        await capture(page, 'goods-types-branch');

        for (const path of [
            '/truck-types',
            '/tyre-layouts',
            '/document-types',
            '/expense-categories',
            '/package-types',
        ]) {
            const response = await page.goto(path);
            expect(response?.status() ?? 500).toBeLessThan(400);
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible({
                timeout: 20_000,
            });
        }

        const invoicesNav = page.getByRole('link', {
            name: 'Invoices',
            exact: true,
        });
        await expect(invoicesNav).toBeVisible();
        await expect(invoicesNav).toHaveAttribute('href', '/invoices');
        await capture(page, 'unified-invoices-nav-branch');

        const invoicesResponse = await page.goto('/invoices');
        if (invoicesResponse && invoicesResponse.status() < 400) {
            await expect(
                page.getByRole('heading', { name: /Invoices/i }),
            ).toBeVisible({ timeout: 20_000 });
            await capture(page, 'unified-invoices-list');
        }

        const paymentsNav = page.getByRole('link', {
            name: /Customer Invoice Receipt/i,
        });
        if ((await paymentsNav.count()) > 0) {
            await expect(paymentsNav.first()).toBeVisible();
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/goods-types');
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible({
            timeout: 20_000,
        });
        await capture(page, 'goods-types-mobile-390');

        await page.setViewportSize({ width: 1280, height: 720 });

        const partloadResponse = await page.goto(
            '/lorry-receipts/create?context=partload',
        );
        if (partloadResponse && partloadResponse.status() < 400) {
            await expect(
                page.getByRole('heading', { name: /Create Partload Bilty/i }),
            ).toBeVisible({ timeout: 20_000 });
            await expect(
                page.getByText('Workflow', { exact: true }),
            ).toHaveCount(0);
            await capture(page, 'partload-create-form');
        }

        const brokerResponse = await page.goto('/broker/lorry-hires');
        if (brokerResponse && brokerResponse.status() < 400) {
            await expect(
                page.getByRole('heading', { name: /Lorry Hires/i }),
            ).toBeVisible({ timeout: 20_000 });
            await capture(page, 'broker-lorry-hires-index');
        }
    });
});
