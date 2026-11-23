import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000';

/**
 * Playwright E2E — Chromium only (PO-P1-DEPS-E2E).
 * Non-production environments only. Do not point at production.
 *
 * Prefer Playwright Chromium when installed (`npx playwright install chromium`).
 * Local fallback uses installed Google Chrome via `channel: 'chrome'` when
 * `PLAYWRIGHT_USE_SYSTEM_CHROME=1` (or when Playwright browser cache is incomplete).
 */
const useSystemChrome =
    process.env.PLAYWRIGHT_USE_SYSTEM_CHROME === '1' ||
    process.env.PLAYWRIGHT_USE_SYSTEM_CHROME === 'true';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: [
        ['list'],
        ['html', { open: 'never', outputFolder: 'storage/playwright-report' }],
    ],
    outputDir: 'storage/playwright-results',
    use: {
        baseURL,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'off',
        ...devices['Desktop Chrome'],
        ...(useSystemChrome ? { channel: 'chrome' as const } : {}),
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                ...(useSystemChrome ? { channel: 'chrome' as const } : {}),
            },
        },
    ],
    webServer: process.env.PLAYWRIGHT_SKIP_WEBSERVER
        ? undefined
        : {
              command: 'php artisan serve --host=127.0.0.1 --port=8000',
              url: baseURL,
              reuseExistingServer: !process.env.CI,
              timeout: 120_000,
          },
});
