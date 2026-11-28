import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const FIXTURE_PATH = path.resolve(
    process.cwd(),
    'storage/app/e2e/broker-journey-auth.json',
);

function ensurePlaywrightCacheDir(): void {
    const cacheRoot =
        process.env.PLAYWRIGHT_BROWSERS_PATH ??
        path.join(os.homedir(), 'Library/Caches/ms-playwright');

    if (!fs.existsSync(cacheRoot)) {
        fs.mkdirSync(cacheRoot, { recursive: true });
    }
}

function defaultSystemChromeWhenBundledBrowserMissing(): void {
    if (
        process.env.PLAYWRIGHT_USE_SYSTEM_CHROME === '1' ||
        process.env.PLAYWRIGHT_USE_SYSTEM_CHROME === 'true'
    ) {
        return;
    }

    const cacheRoot = path.join(os.homedir(), 'Library/Caches/ms-playwright');
    if (!fs.existsSync(cacheRoot)) {
        process.env.PLAYWRIGHT_USE_SYSTEM_CHROME = '1';

        return;
    }

    const hasChromium = fs
        .readdirSync(cacheRoot)
        .some(
            (entry) =>
                entry.startsWith('chromium') ||
                entry.startsWith('chromium_headless_shell'),
        );

    if (!hasChromium) {
        process.env.PLAYWRIGHT_USE_SYSTEM_CHROME = '1';
        // eslint-disable-next-line no-console
        console.warn(
            '[e2e] No Playwright Chromium in cache — set PLAYWRIGHT_USE_SYSTEM_CHROME=1 for this run.',
        );
    }
}

/**
 * Ensure Broker Playwright credentials on the dev database and load
 * them into the worker environment (global setup env is not inherited by workers).
 */
export default async function globalSetup(): Promise<void> {
    const root = path.resolve(
        path.dirname(fileURLToPath(import.meta.url)),
        '../..',
    );

    ensurePlaywrightCacheDir();
    defaultSystemChromeWhenBundledBrowserMissing();

    try {
        execFileSync('php', ['artisan', 'e2e:ensure-broker-journey'], {
            cwd: root,
            stdio: ['ignore', 'pipe', 'pipe'],
            timeout: 120_000,
            env: {
                ...process.env,
                APP_ENV: process.env.E2E_APP_ENV ?? 'local',
                ...(process.env.DB_DATABASE === 'nexus_test' ||
                process.env.DB_DATABASE === 'nexus_mysql_test'
                    ? {
                          E2E_CONNECTION:
                              process.env.E2E_CONNECTION ?? 'pgsql',
                          E2E_DEV_DATABASE:
                              process.env.E2E_DEV_DATABASE ?? 'nexus',
                      }
                    : {}),
            },
        });
    } catch (error: unknown) {
        const err = error as { stderr?: string; stdout?: string };
        throw new Error(
            `e2e:ensure-broker-journey failed:\n${err.stderr ?? ''}\n${err.stdout ?? ''}`,
        );
    }

    if (!fs.existsSync(FIXTURE_PATH)) {
        throw new Error(
            `Expected fixture at ${FIXTURE_PATH} after e2e:ensure-broker-journey.`,
        );
    }

    const fixture = JSON.parse(fs.readFileSync(FIXTURE_PATH, 'utf8')) as {
        email: string;
        password: string;
        company_name: string;
        company_id?: string;
        branch_id?: string;
    };

    if (!fixture.company_id || !fixture.branch_id) {
        throw new Error(
            'broker-journey-auth.json missing company_id/branch_id — re-run e2e:ensure-broker-journey.',
        );
    }

    process.env.E2E_BROKER_JOURNEY_EMAIL = fixture.email;
    process.env.E2E_BROKER_JOURNEY_PASSWORD = fixture.password;
    process.env.E2E_BROKER_JOURNEY_COMPANY = fixture.company_name;
    process.env.E2E_BROKER_JOURNEY_COMPANY_ID = fixture.company_id;
    process.env.E2E_BROKER_JOURNEY_BRANCH_ID = fixture.branch_id;
    process.env.E2E_USER_EMAIL = fixture.email;
    process.env.E2E_USER_PASSWORD = fixture.password;
}