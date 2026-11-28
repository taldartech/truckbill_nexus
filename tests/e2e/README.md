# Playwright E2E

**Status:** **Authorized** (`PO-P1-DEPS-E2E` via `PO-P1-P5-FULLSTACK-AUTH`).  
**Package:** `@playwright/test@1.57.0` (pinned).  
**Browser:** Chromium.

## Commands

```bash
npx playwright install chromium
npm run test:e2e:smoke
# Authenticated journeys after LocalDemoWorkspaceSeeder:
E2E_REQUIRE_DEMO=1 npm run test:e2e
# OwnFleet financial journey (after seeder):
E2E_REQUIRE_DEMO=1 E2E_USER_PASSWORD="$LOCAL_DEMO_PASSWORD" PLAYWRIGHT_USE_SYSTEM_CHROME=1 \
  npx playwright test tests/e2e/ownfleet/complete-workflow.spec.ts
# Broker financial journeys (after seeder):
E2E_REQUIRE_DEMO=1 E2E_USER_PASSWORD="$LOCAL_DEMO_PASSWORD" PLAYWRIGHT_USE_SYSTEM_CHROME=1 \
  npx playwright test tests/e2e/broker/
```

## Rules

- Non-production environments only.
- Do not point at production or real customer accounts.
- Prefer stable labels / roles over brittle CSS selectors.
- Traces and screenshots on failure under `storage/playwright-*` (gitignored).

## Layout

```
tests/e2e/
  auth/
  fixtures/
  roles/customer/   # persona smokes (env credentials only)
  company/      # expand as journeys land
  masters/
  subscription/
  crm/
  enquiry/
  ownfleet/
  broker/
```

Persona smokes (`roles/customer/*.smoke.spec.ts`) skip unless `E2E_REQUIRE_DEMO=1` and a password env (`E2E_USER_PASSWORD` or `LOCAL_DEMO_PASSWORD`) is set. Demo emails are public fixtures (`@demo.test`); never commit passwords.

When **Demo Logistics Alpha** is missing from the dev database, `php artisan e2e:ensure-broker-journey` (Playwright global setup) can run `LocalDemoWorkspaceSeeder` once if `E2E_ALLOW_DEMO_SEED=1` and `LOCAL_DEMO_PASSWORD` is set — it does not reset the database or overwrite user password hashes.

**Branch entry:** Owner Branches requires the **Enter this branch?** confirmation before the workspace context POST. Helpers in `tests/e2e/fixtures/branch-entry.ts` follow that flow (no raw fetch bypass).

**Browsers:** Global setup ensures `~/Library/Caches/ms-playwright` exists and defaults to `PLAYWRIGHT_USE_SYSTEM_CHROME=1` when bundled Chromium is not installed. Run `npx playwright install chromium` only when you need Playwright-managed browsers.

**Database:** Playwright hits the **development** database via `e2e:ensure-broker-journey`. If `.env` points at `nexus_test`, set `E2E_DEV_DATABASE=nexus` (or your dev schema). The command refuses to seed or write fixtures into PHPUnit databases unless `E2E_FORCE_TEST_DATABASE=1`.

## CI

GitHub Actions installs Chromium and runs `npm run test:e2e:smoke` after the app build.

**CI #75 root cause:** `PLAYWRIGHT_SKIP_WEBSERVER: '0'` is truthy in JavaScript, so Playwright never started `php artisan serve` → `net::ERR_CONNECTION_REFUSED`. Fix: treat only `1` / `true` / `yes` as skip; omit the env var in CI and let Playwright manage the webServer (ready URL `/login`, 180s timeout, piped logs).
