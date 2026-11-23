# Action Regression, Session Routing & Responsive Tables — Certification

**Date:** 23 November 2026  
**Baseline (remote HEAD before fix):** `15b39cd0684f99de563bfcf5544f50d40ca8e4d2`  
**Feature commit:** `3676ea8f154b6d54e808a542bc9db92dd824aa36`  
**GitHub Actions #77:** FAILED (444 passed / 59 failed) — session-routing migration regressions.

## Root causes (P0)

1. **Absolute redirect rejection** — `OwnerGlobalPanelService` emitted absolute `route()` URLs into `WorkspaceContextAction` `redirect` fields. `WorkspaceContextController::safeRedirect()` only accepted paths starting with `/`, so company action POSTs failed validation after context was already written, bouncing the user back to the previous page (actions appeared “broken”).
2. **Missing session context in tests/clients** — Company/branch scoped mutations without established workspace session returned **409**.
3. **Flash toast contract mismatch** — Controllers flash `success`/`error`; the toast hook only listened for Inertia `flash.toast`.
4. **Branding** — `VITE_APP_NAME` / default `Laravel` in document titles.

## Corrections

| Area             | Fix                                                                                                                         |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Redirects        | `SafeInternalRedirect` normalizes same-origin absolute URLs to relative paths; rejects external / protocol-relative targets |
| Inertia payloads | Management destination URLs generated with `absolute: false`                                                                |
| Middleware       | `ResolveManagementCompany` denies non-owners with 403 before soft redirects                                                 |
| Flash            | Shared `flash.success` / `flash.error` / `flash.toast`; `useFlashToast` shows all + HTTP 401/403/409/419/5xx                |
| Branding         | Document title forced to `TruckBill`; Inertia `name` already TruckBill                                                      |
| Tables           | `ResponsiveDataTable` with P0–P3 priorities; adopted on 22 list pages                                                       |
| Tests            | Full Pest suite migrated to session helpers; redirect security unit tests                                                   |

## Pest

Full suite after remediation: **504 passed, 0 failed, 2 skipped** (local).

## Playwright

Updated `tests/e2e/owner/global-panel.spec.ts` and fixtures for `/management` and `/branch` session URLs. Local run with `PLAYWRIGHT_USE_SYSTEM_CHROME=1` timed out waiting for `#email` on `/login` (page shell did not expose the field within 30s — likely Vite asset/hydration environment issue). **Not certified green in this batch.** Specs are updated for CI once browsers/assets are healthy.

## k6

Deferred until Playwright smoke is green in CI — not executed in this batch (no invented results).

## Local validation (this batch)

| Check                 | Result                                          |
| --------------------- | ----------------------------------------------- |
| Pest                  | 509 passed, 2 skipped                           |
| Pint                  | passed                                          |
| PHPStan               | 0 errors                                        |
| `npm run check`       | passed                                          |
| `npm run types:check` | passed                                          |
| `npm run build`       | passed                                          |
| Playwright Owner A–F  | **Not certified** (login timeout / browser env) |
| k6                    | Not run                                         |

## Verdict

**IMPLEMENTED WITH DOCUMENTED EXCEPTIONS** — P0 actions and Pest #77 regressions fixed; responsive tables adopted on ordinary list pages; Playwright/k6 browser evidence outstanding.
