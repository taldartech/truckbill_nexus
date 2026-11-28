import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const FIXTURE_PATH = path.resolve(
    process.cwd(),
    'storage/app/e2e/broker-journey-auth.json',
);

/**
 * Ensure isolated Broker Playwright credentials on the dev database and load
 * them into the worker environment (global setup env is not inherited by workers).
 */
export default async function globalSetup(): Promise<void> {
    const root = path.resolve(
        path.dirname(fileURLToPath(import.meta.url)),
        '../..',
    );

    try {
        execFileSync('php', ['artisan', 'e2e:ensure-broker-journey'], {
            cwd: root,
            stdio: ['ignore', 'pipe', 'pipe'],
            timeout: 120_000,
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
    };

    process.env.E2E_BROKER_JOURNEY_EMAIL = fixture.email;
    process.env.E2E_BROKER_JOURNEY_PASSWORD = fixture.password;
    process.env.E2E_BROKER_JOURNEY_COMPANY = fixture.company_name;
    process.env.E2E_USER_EMAIL = fixture.email;
    process.env.E2E_USER_PASSWORD = fixture.password;
}
