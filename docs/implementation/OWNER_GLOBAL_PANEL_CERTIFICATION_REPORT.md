# Owner Global Panel & Master Company Visibility — Certification

**Decisions:** `PO-OWNER-GLOBAL-MANAGEMENT`, `PO-MASTER-COMPANY-VISIBILITY`  
**Date:** 23 November 2026 (amended 25 November 2026 — session decoupling)  
**Starting HEAD:** `bcb1d58b528c7ae87cebde2d37a146555e7c5d70`  
**Tip HEAD:** _(session-decoupling commit — see git log)_  
**Status:** Locally certified for session-decoupling slice — no push / no production deploy

## Scope delivered

| Area                           | Result                                                                    |
| ------------------------------ | ------------------------------------------------------------------------- |
| Global Companies list          | `GET /management/companies` (`owner.companies.index`)                     |
| Global Branches list           | `GET /management/branches` (`owner.branches.all`)                         |
| Global Create Branch           | `GET /management/branches/create` + `…/companies/{company}/branches`      |
| Global Subscriptions list      | `GET /management/subscriptions` (`owner.subscriptions.index`)             |
| Workspace landing for owners   | Redirects to global Owner home (not MRU/single-company force)             |
| Session independence           | Admin mutations do not use / mutate WorkspaceSession company              |
| Stale deactivated session      | Cannot block Create Branch for another active owned company               |
| Return to Owner Portal         | Clears company **and** branch WorkspaceSession keys                       |
| Masters scope                  | Company `data_visibility` only; Scope UI removed; forged scope ignored    |
| Visibility change with masters | Blocked until PO migration policy                                         |
| Demo seeder                    | Alpha = Shared; Beta = Restricted; multi-company Owner fixtures preserved |
| Pest                           | `OwnerGlobalPanelTest`, `OwnerPanelSessionIndependenceTest`, related      |
| Playwright                     | `global-panel.spec.ts`; `create-branch-session-independence.spec.ts`      |

## Preserved restrictions

- No live Razorpay / PO-P3-PAY-PROD
- Phase 3 EX-01…EX-07 unchanged
- Users / Settings / Audit remain company-specific
- Station / owner catalogues / central masters ownership unchanged
- No push / no production deploy

## Compatibility

See `docs/engineering/MASTER_VISIBILITY_COMPATIBILITY_REPORT.md`.
