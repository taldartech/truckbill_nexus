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
  company/      # expand as journeys land
  masters/
  subscription/
  crm/
  enquiry/
  ownfleet/
```

## CI

GitHub Actions installs Chromium and runs `npm run test:e2e:smoke` after the app build.

**CI #75 root cause:** `PLAYWRIGHT_SKIP_WEBSERVER: '0'` is truthy in JavaScript, so Playwright never started `php artisan serve` → `net::ERR_CONNECTION_REFUSED`. Fix: treat only `1` / `true` / `yes` as skip; omit the env var in CI and let Playwright manage the webServer (ready URL `/login`, 180s timeout, piped logs).
