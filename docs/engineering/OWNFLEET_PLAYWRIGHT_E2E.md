# OwnFleet Playwright E2E

**Spec:** `tests/e2e/ownfleet/complete-workflow.spec.ts`  
**Fixtures:** `tests/e2e/fixtures/ownfleet-workflow.ts`

## Local run (verified 2026-11-24)

```bash
set -a && source .env && set +a
export E2E_USER_PASSWORD="${LOCAL_DEMO_PASSWORD}"
E2E_REQUIRE_DEMO=1 PLAYWRIGHT_USE_SYSTEM_CHROME=1 \
  npx playwright test tests/e2e/ownfleet/complete-workflow.spec.ts
```

**Last result:** 3 passed / 0 failed / 0 skipped (~31s).

## Prerequisites

- `php artisan db:seed --class=LocalDemoWorkspaceSeeder` (local/testing only)
- `LOCAL_DEMO_PASSWORD` in `.env` (never commit)
- `E2E_REQUIRE_DEMO=1` — skips removed unless this is unset (by design)

## Infrastructure notes

- If Playwright reports a missing `chromium_headless_shell-*` binary, either run `npx playwright install chromium` or set `PLAYWRIGHT_USE_SYSTEM_CHROME=1` (see `playwright.config.ts`).
- Demo login for journeys uses **owner.alpha@demo.test** via `demoCredentials()` (branch entry through Demo Logistics Alpha HO).
- Own vehicle selection on LR create must match ownership label `· Own`, not registration substrings containing “OWN”.
- Unified invoice list is at `/invoices` (not a separate OwnFleet-only index heading).

## Coverage

| Test           | Intent                                                                                                                                                                                |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Main journey   | Login → branch → OwnFleet LR → trip (fixed vehicle) → movement → diesel expense → documents tab → dispatch/complete → trip invoice → partial + fill-net final payment → invoices list |
| Duplicate trip | Second create-trip visit redirects to existing trip; LR Actions shows Open Trip only                                                                                                  |
| Vehicle lock   | Trip create has read-only LR vehicle, no `select#vehicle_id`                                                                                                                          |

## Gaps

- Document upload drawer not exercised (file input / virus scan flow).
- Consolidated trip invoices and charge-line editing not covered.
- Does not replace Pest feature coverage for domain rules.
