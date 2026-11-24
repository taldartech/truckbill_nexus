# Portal UX — Playwright Visual Certification (Batch I)

**Date:** 24 November 2026  
**Spec:** `tests/e2e/portal/portal-ux-visual.spec.ts`  
**Prerequisites:** `LocalDemoWorkspaceSeeder`, `E2E_REQUIRE_DEMO=1`, `LOCAL_DEMO_PASSWORD`, `PLAYWRIGHT_USE_SYSTEM_CHROME=1` (when Chromium bundle missing)  
**Screenshots:** `docs/engineering/portal-ux-screenshots/`  
**Branch entry:** `enterDemoAlphaBranch` targets **Demo Logistics Alpha** (row-scoped; not Solo Gamma).

## Run command (local)

```bash
LOCAL_DEMO_PASSWORD=… E2E_REQUIRE_DEMO=1 PLAYWRIGHT_USE_SYSTEM_CHROME=1 \
  PLAYWRIGHT_SKIP_WEBSERVER=1 npx playwright test tests/e2e/portal/portal-ux-visual.spec.ts
```

(`PLAYWRIGHT_SKIP_WEBSERVER=1` only when `php artisan serve` is already bound to `127.0.0.1:8000`.)

## Case matrix

| #   | Case                                     | Verdict  | Evidence / notes                                                    |
| --- | ---------------------------------------- | -------- | ------------------------------------------------------------------- |
| 1   | Owner companies — icon row actions       | **Pass** | Semantic Edit/Branches/Users buttons; `owner-companies-desktop.png` |
| 2   | Global users — Manage is icon link       | **Pass** | `Manage membership`; no bare `Manage` text                          |
| 3   | Global audit — company audit icon        | **Pass** | `Open company audit`; `global-audit-desktop.png`                    |
| 4   | Expand control hidden at ~1280px desktop | **Pass** | No `Show row details` on companies table                            |
| 5   | Goods-types + five other masters         | **Pass** | All six catalogue routes `< 400` after Alpha entry                  |
| 6   | Unified `/invoices` nav + list           | **Pass** | Sidebar Invoices; list OwnFleet+Broker; screenshots                 |
| 7   | Customer Invoice Receipt nav label       | **Pass** | Distinct from Transporter Voucher Pay                               |
| 8   | Mobile 390 goods-types expand            | **Pass** | `goods-types-mobile-390.png`                                        |
| 9   | Partload create — no “Workflow”          | **Pass** | `partload-create-form.png`                                          |
| 10  | Broker registers hub — icon tiles        | **Pass** | `broker-registers-hub.png`                                          |

## Playwright summary

| Metric    | Value    |
| --------- | -------- |
| Tests run | 1        |
| Passed    | 1        |
| Failed    | 0        |
| Duration  | ~10–12 s |

## Honest gaps

1. Full multi-viewport matrix (1920/1440/1024/768) not captured for every portal screen — primary defects evidenced at 1280 and 390.
2. Remote GitHub Actions Playwright not claimed.
3. Exhaustive 11-persona RBAC browser matrix not re-run in this certification pass (Pest RBAC suites remain).
