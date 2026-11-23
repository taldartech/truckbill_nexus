# Owner Global Panel & Master Company Visibility — Certification

**Decisions:** `PO-OWNER-GLOBAL-MANAGEMENT`, `PO-MASTER-COMPANY-VISIBILITY`  
**Date:** 23 November 2026  
**Starting HEAD:** `bcb1d58b528c7ae87cebde2d37a146555e7c5d70`  
**Tip HEAD (pre-docs):** `79524e1` (tests commit); docs tip recorded after this file’s commit  
**Status:** Locally certified — no push / no production deploy

## Scope delivered

| Area                           | Result                                                                    |
| ------------------------------ | ------------------------------------------------------------------------- |
| Global Companies list          | `GET /owner/companies` (`owner.companies.index`)                          |
| Global Branches list           | `GET /owner/branches` (`owner.branches.all`)                              |
| Global Subscriptions list      | `GET /owner/subscriptions` (`owner.subscriptions.index`)                  |
| Workspace landing for owners   | Redirects to global Companies (not MRU/single-company force)              |
| Masters scope                  | Company `data_visibility` only; Scope UI removed; forged scope ignored    |
| Visibility change with masters | Blocked until PO migration policy                                         |
| Demo seeder                    | Alpha = Shared; Beta = Restricted; multi-company Owner fixtures preserved |
| Pest                           | `OwnerGlobalPanelTest`, `MasterCompanyVisibilityTest`, related updates    |
| Playwright                     | `tests/e2e/owner/global-panel.spec.ts` Scenarios A–F + mobile             |

## Preserved restrictions

- No live Razorpay / PO-P3-PAY-PROD
- Phase 3 EX-01…EX-07 unchanged
- Users / Settings / Audit remain company-specific
- Station / owner catalogues / central masters ownership unchanged
- No push / no production deploy

## Compatibility

See `docs/engineering/MASTER_VISIBILITY_COMPATIBILITY_REPORT.md`.
