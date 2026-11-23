import { expect, type Page } from '@playwright/test';

export async function loginAs(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill(email);
    await page.getByLabel(/password/i).fill(password);
    await page.getByRole('button', { name: /log in|sign in/i }).click();
    await expect(page).not.toHaveURL(/\/login$/);
}

export function demoCredentials(): { email: string; password: string } {
    return {
        email: process.env.E2E_USER_EMAIL ?? 'owner@demo.truckbill.local',
        password:
            process.env.E2E_USER_PASSWORD ??
            process.env.LOCAL_DEMO_PASSWORD ??
            'password',
    };
}
