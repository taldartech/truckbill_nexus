# Playwright E2E (gated)

**Status:** **Blocked pending Product Owner dependency approval** (`PO-P1-DEPS-E2E`).

This directory is a placeholder for the production Playwright suite requested in the Phase 1–5 master prompt.

## Do not

- Silently `npm install @playwright/test` without approval (project dependency gate).
- Claim E2E passed while packages are absent.
- Point tests at production or real customer accounts.

## After approval

1. Add `@playwright/test` (version pinned) to `package.json` with PO-P1-DEPS-E2E recorded.
2. Add `playwright.config.ts`, auth fixtures, Chromium project.
3. Seed via `LocalDemoWorkspaceSeeder` + `LOCAL_DEMO_PASSWORD`.
4. Wire CI smoke job + artifact upload (traces/screenshots).
5. Encode journeys listed in `docs/implementation/PHASE_1_TO_5_TEST_STRATEGY.md`.

## Proposed tree

```
tests/e2e/
  README.md          ← this file
  playwright.config.ts
  fixtures/
  auth/
  company/
  masters/
  subscription/
  enquiry/           ← after Phase 4 UI
  ownfleet/          ← after Phase 5
```
