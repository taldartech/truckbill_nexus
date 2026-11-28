import fs from 'node:fs';
import path from 'node:path';

export type BrokerJourneyAuthFixture = {
    email: string;
    password: string;
    company_name: string;
    company_id?: string;
    branch_id?: string;
};

const FIXTURE_PATH = path.resolve(
    process.cwd(),
    'storage/app/e2e/broker-journey-auth.json',
);

function readBrokerJourneyFixtureFile(): BrokerJourneyAuthFixture | null {
    if (!fs.existsSync(FIXTURE_PATH)) {
        return null;
    }

    return JSON.parse(
        fs.readFileSync(FIXTURE_PATH, 'utf8'),
    ) as BrokerJourneyAuthFixture;
}

export function loadBrokerJourneyAuth(): BrokerJourneyAuthFixture {
    const fromFile = readBrokerJourneyFixtureFile();

    const fromEnv =
        process.env.E2E_BROKER_JOURNEY_EMAIL &&
        process.env.E2E_BROKER_JOURNEY_PASSWORD
            ? {
                  email: process.env.E2E_BROKER_JOURNEY_EMAIL,
                  password: process.env.E2E_BROKER_JOURNEY_PASSWORD,
                  company_name:
                      process.env.E2E_BROKER_JOURNEY_COMPANY ??
                      fromFile?.company_name ??
                      'Demo Logistics Alpha',
                  company_id:
                      process.env.E2E_BROKER_JOURNEY_COMPANY_ID ??
                      fromFile?.company_id,
                  branch_id:
                      process.env.E2E_BROKER_JOURNEY_BRANCH_ID ??
                      fromFile?.branch_id,
              }
            : null;

    if (fromEnv !== null) {
        return fromEnv;
    }

    if (fromFile === null) {
        throw new Error(
            'Missing Broker E2E auth fixture. Run: php artisan e2e:ensure-broker-journey',
        );
    }

    return fromFile;
}

export function brokerBranchContextPath(
    fixture: BrokerJourneyAuthFixture,
): string | null {
    if (!fixture.company_id || !fixture.branch_id) {
        return null;
    }

    return `/workspace/context/companies/${fixture.company_id}/branches/${fixture.branch_id}`;
}
