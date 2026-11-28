import { expect, type Page } from '@playwright/test';
import { loadBrokerJourneyAuth } from './broker-journey-auth';

export async function loginAs(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login', { waitUntil: 'networkidle' });
    const emailField = page.locator('#email');
    await expect(emailField).toBeVisible({ timeout: 30_000 });
    await emailField.fill(email);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: /log in|sign in/i }).click();
    await expect(page).not.toHaveURL(/\/login$/, { timeout: 15_000 });
}

export function demoCredentials(): { email: string; password: string } {
    try {
        const fixture = loadBrokerJourneyAuth();

        return {
            email: fixture.email,
            password: fixture.password,
        };
    } catch {
        const password =
            process.env.E2E_USER_PASSWORD ??
            process.env.LOCAL_DEMO_PASSWORD ??
            '';

        if (password === '') {
            throw new Error(
                'Run php artisan e2e:ensure-broker-journey or set LOCAL_DEMO_PASSWORD for Broker Playwright journeys.',
            );
        }

        return {
            email: process.env.E2E_USER_EMAIL ?? 'owner.alpha@demo.test',
            password,
        };
    }
}
