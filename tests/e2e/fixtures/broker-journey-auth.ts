import fs from 'node:fs';
import path from 'node:path';

export type BrokerJourneyAuthFixture = {
    email: string;
    password: string;
    company_name: string;
};

const FIXTURE_PATH = path.resolve(
    process.cwd(),
    'storage/app/e2e/broker-journey-auth.json',
);

export function loadBrokerJourneyAuth(): BrokerJourneyAuthFixture {
    const fromEnv =
        process.env.E2E_BROKER_JOURNEY_EMAIL &&
        process.env.E2E_BROKER_JOURNEY_PASSWORD
            ? {
                  email: process.env.E2E_BROKER_JOURNEY_EMAIL,
                  password: process.env.E2E_BROKER_JOURNEY_PASSWORD,
                  company_name:
                      process.env.E2E_BROKER_JOURNEY_COMPANY ??
                      'Demo Logistics Alpha',
              }
            : null;

    if (fromEnv !== null) {
        return fromEnv;
    }

    if (!fs.existsSync(FIXTURE_PATH)) {
        throw new Error(
            'Missing Broker E2E auth fixture. Run: php artisan e2e:ensure-broker-journey',
        );
    }

    const parsed = JSON.parse(
        fs.readFileSync(FIXTURE_PATH, 'utf8'),
    ) as BrokerJourneyAuthFixture & { company_id?: string };

    return {
        email: parsed.email,
        password: parsed.password,
        company_name: parsed.company_name,
    };
}
