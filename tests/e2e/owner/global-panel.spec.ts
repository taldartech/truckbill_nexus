import { expect, test } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { e2eEmails, e2ePassword } from '../fixtures/company-create';
import {
    branchContextUrl,
    companyContextUrl,
    loadOwnerGlobalPanelFixture,
    partiesIndexUrl,
    partyCreateUrl,
} from '../fixtures/owner-global-panel';

test.describe('Owner global panel', () => {
    test.beforeEach(() => {
        test.skip(
            process.env.E2E_REQUIRE_COMPANY_CREATE !== '1',
            'Set E2E_REQUIRE_COMPANY_CREATE=1 after e2e:seed-company-create.',
        );
    });

    test('Scenarios A–E — management actions, branch context, responsive table', async ({
        page,
    }) => {
        await loginAs(page, e2eEmails.owner, e2ePassword());

        // A — Management home + Companies (no /owner, no company switcher)
        await page.goto('/workspace');
        await expect(page).toHaveURL(/\/management\/?$/);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.getByTestId('company-switcher')).toHaveCount(0);

        await page.setViewportSize({ width: 390, height: 844 });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.setViewportSize({ width: 1280, height: 720 });

        await page.goto('/management/companies');
        await expect(page).toHaveURL(/\/management\/companies\/?$/);
        await expect(page.getByTestId('company-switcher')).toHaveCount(0);

        // Edit company via accessible icon action
        await page
            .getByRole('button', { name: 'Edit company' })
            .first()
            .click();
        await expect(page).toHaveURL(/\/management\/company\/settings\/?$/);
        await expect(page.getByTestId('company-switcher')).toHaveCount(0);
        await page.goto('/management/companies');
        await expect(page).toHaveURL(/\/management\/companies\/?$/);

        // B — Branches → Enter Branch Panel (session context)
        const fixture = loadOwnerGlobalPanelFixture();
        await page.goto('/management/branches');
        await expect(
            page.getByRole('heading', { name: 'Branches' }),
        ).toBeVisible();
        await page.locator('#company').selectOption(fixture.shared.company_id);
        await page.getByRole('button', { name: /apply/i }).click();
        await expect(page.getByText('Shared HO').first()).toBeVisible();

        await page
            .getByRole('button', { name: /Enter Branch/i })
            .first()
            .click();
        await expect(page).toHaveURL(/\/branch\/?$/);
        await expect(
            page.getByRole('link', { name: /Return to Management/i }),
        ).toBeVisible();
        await page.getByRole('link', { name: /Return to Management/i }).click();
        await expect(page).toHaveURL(/\/management\/?$/);

        // C — Subscriptions → Manage via icon
        await page.goto('/management/subscriptions');
        await expect(
            page.getByRole('heading', { name: 'Subscriptions' }),
        ).toBeVisible();
        await expect(page.getByTestId('company-switcher')).toHaveCount(0);
        await page
            .getByRole('button', {
                name: /Manage subscription|Open subscription/i,
            })
            .first()
            .click();
        await expect(page).toHaveURL(/\/management\/subscription\/?$/);
        await page.goto('/management/subscriptions');
        await expect(page).toHaveURL(/\/management\/subscriptions\/?$/);

        // D — Shared party via session branch context
        await page.request.post(branchContextUrl(fixture.shared, 'a'), {
            form: { redirect: '/branch/parties/create' },
        });
        const sharedParty = `Shared Party ${Date.now()}`;
        await page.goto(partyCreateUrl());
        await expect(
            page.getByRole('heading', { name: /Create party/i }),
        ).toBeVisible();
        await expect(
            page.getByText(/Shared across company branches/i),
        ).toBeVisible();
        await expect(page.locator('select[name="scope"]')).toHaveCount(0);
        await page.locator('#name').fill(sharedParty);
        await page.getByRole('button', { name: /save|Create party/i }).click();
        await expect(page).toHaveURL(/\/branch\/parties\/.+/);

        await page.request.post(branchContextUrl(fixture.shared, 'b'), {
            form: { redirect: '/branch/parties' },
        });
        await page.goto(partiesIndexUrl());
        await expect(page.getByText(sharedParty).first()).toBeVisible();

        // E — Restricted party stays on creating branch
        await page.request.post(branchContextUrl(fixture.restricted, 'a'), {
            form: { redirect: '/branch/parties/create' },
        });
        const restrictedParty = `Restricted Party ${Date.now()}`;
        await page.goto(partyCreateUrl());
        await expect(
            page.getByText(/Restricted to this branch/i),
        ).toBeVisible();
        await page.locator('#name').fill(restrictedParty);
        await page.getByRole('button', { name: /save|Create party/i }).click();
        await expect(page).toHaveURL(/\/branch\/parties\/.+/);

        await page.request.post(branchContextUrl(fixture.restricted, 'a'), {
            form: { redirect: '/branch/parties' },
        });
        await page.goto(partiesIndexUrl());
        await expect(page.getByText(restrictedParty).first()).toBeVisible();

        await page.request.post(branchContextUrl(fixture.restricted, 'b'), {
            form: { redirect: '/branch/parties' },
        });
        await page.goto(partiesIndexUrl());
        await expect(page.getByText(restrictedParty)).toHaveCount(0);

        // Responsive companies table — no horizontal overflow at mobile
        await page.request.post(companyContextUrl(fixture.shared.company_id), {
            form: { redirect: '/management/companies' },
        });
        await page.goto('/management/companies');
        await page.setViewportSize({ width: 390, height: 844 });
        const overflow = await page.locator('table').evaluate((table) => {
            const parent = table.parentElement;

            return parent ? parent.scrollWidth > parent.clientWidth + 2 : false;
        });
        expect(overflow).toBe(false);
        await expect(
            page.getByRole('button', { name: 'Show row details' }).first(),
        ).toBeVisible();
    });

    test('Scenario F — non-owner cannot open management URLs', async ({
        page,
    }) => {
        await loginAs(page, e2eEmails.internal, e2ePassword());
        const response = await page.goto('/management/companies');
        expect(response?.status()).toBe(403);
    });
});
