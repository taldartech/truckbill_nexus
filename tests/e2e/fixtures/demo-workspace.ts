import { type Page } from '@playwright/test';
import { loginAs } from './auth';
import { enterBranchPanelFromOwnerBranches } from './branch-entry';

export const DEMO_OWNER_EMAIL = 'owner.alpha@demo.test';

export const DEMO_OPERATOR_EMAIL = 'operator.alpha@demo.test';

export const DEMO_VIEWER_EMAIL = 'viewer.alpha@demo.test';

export const DEMO_COMPANY_NAME = 'Demo Logistics Alpha';

export async function loginDemoOperator(
    page: Page,
    password: string,
): Promise<void> {
    await loginAs(page, DEMO_OPERATOR_EMAIL, password);
}

export async function enterDemoAlphaBranch(page: Page): Promise<void> {
    await enterBranchPanelFromOwnerBranches(page, DEMO_COMPANY_NAME);
}