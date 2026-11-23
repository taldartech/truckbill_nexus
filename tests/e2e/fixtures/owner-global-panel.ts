import fs from 'node:fs';
import path from 'node:path';

export type VisibilityFixture = {
    company_id: string;
    company_name: string;
    branch_a_id: string;
    branch_b_id: string;
};

export type OwnerGlobalPanelFixture = {
    shared: VisibilityFixture;
    restricted: VisibilityFixture;
};

export function loadOwnerGlobalPanelFixture(): OwnerGlobalPanelFixture {
    const file = path.resolve(
        process.cwd(),
        'storage/app/private/e2e/owner-global-panel.json',
    );
    const alt = path.resolve(
        process.cwd(),
        'storage/app/e2e/owner-global-panel.json',
    );
    const target = fs.existsSync(file) ? file : alt;
    if (!fs.existsSync(target)) {
        throw new Error(
            `Missing E2E fixture at ${file} or ${alt}. Run: php artisan e2e:seed-company-create`,
        );
    }

    return JSON.parse(
        fs.readFileSync(target, 'utf8'),
    ) as OwnerGlobalPanelFixture;
}

/** Canonical Branch Panel parties create (session context must already be set). */
export function partyCreateUrl(): string {
    return '/branch/parties/create';
}

/** Canonical Branch Panel parties index (session context must already be set). */
export function partiesIndexUrl(): string {
    return '/branch/parties';
}

export function branchContextUrl(
    fixture: VisibilityFixture,
    branch: 'a' | 'b',
): string {
    const branchId = branch === 'a' ? fixture.branch_a_id : fixture.branch_b_id;

    return `/workspace/context/companies/${fixture.company_id}/branches/${branchId}`;
}

export function companyContextUrl(companyId: string): string {
    return `/workspace/context/companies/${companyId}`;
}
