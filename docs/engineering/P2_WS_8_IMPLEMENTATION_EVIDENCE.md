# P2-WS-8 Implementation Evidence

**Date:** 22 November 2026  
**Authorization:** Product Owner instruction — P2-WS-8 Product UI within approved design scope (PO-P2-DESIGN-FORMAL)  
**Design baseline:** `99f7a0a3c6785ed6166271350e79a274c9b8329a`  
**Initial implementation commit:** `3c2e8c9abf687aaeb502cb22be0dbec1f42568d8`  
**Closure matrix:** `docs/engineering/P2_WS_8_CLOSURE_MATRIX.md`  
**Core workspace:** Product Owner accepted with documented exceptions (22 November 2026).  
**Owner Panel follow-on:** See `P2_WS_8_OWNER_PANEL_FOLLOWON_EVIDENCE.md` (engineering complete for authorized slice; follow-on PO acceptance not granted here).

## Scope delivered

| Milestone                   | Delivered                                                                                                                                     |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| 1 Shared UI foundation      | Workspace-aware sidebar, company switcher (Form POST), status badge, empty/error/no-access states; Internal Team keeps technical `/dashboard` |
| 2 Workspace selection       | Landing resolver, unified/owned company selection, sequential branch selection, server MRU, zero-access screen, enter company/branch posts    |
| 3 Owner Panel               | Owner Dashboard with Region B shortcuts; Branches; deferred Users/Settings/Audit entry pages; deactivated `admin_billing_only` presentation   |
| 4 Branch Panel              | Branch Dashboard, Branch Settings (prefs note only), hide unavailable modules                                                                 |
| 5 Subscription presentation | High-level unavailable presentation; `actions_allowed: false`; no invented entitlements                                                       |

## Routes

- `workspace.landing`, `workspace.no-access`, `workspace.companies.select`, `workspace.companies.enter`
- `workspace.branches.select`, `workspace.branches.enter`
- `owner.dashboard`, `owner.subscription`, `owner.branches`, `owner.users`, `owner.settings`, `owner.audit`
- `branch.dashboard`, `branch.settings`

Fortify `home` → `/workspace`.

## Backend changes

- `WorkspaceNavigationService` — accessible companies/branches; MRU remember/resolve/clear
- `WorkspaceSession` — server-set session context keys
- Controllers under `Workspace/`, `Owner/`, `Branch/` including `OwnerDeferredController`
- Migration: `users.mru_owned_company_id` (nullable FK → companies, nullOnDelete)
- `HandleInertiaRequests` shares customer workspace companies; active company/branch from route when present, else session
- Authorization via `CompanyAccessService` + route model binding; `RejectClientTenantIdentifiers` unchanged

## Closure defect fixes (approved scope)

1. Region B Owner shortcuts were missing / misleading → added Users/Settings/Audit **deferred** entry pages (not CRUD).
2. Company/branch enter used fragile `router.post` click handlers → Inertia `Form` + Wayfinder POST.
3. Active company chrome lagged on first Owner GET → route-derived active context in shared props.
4. Empty branch copy implied create UI → honest “not part of this slice” copy.
5. Deactivated banner aligned to approved wording.

## Deferred / excluded (explicit)

- Full Owner users / memberships / company-profile / audit **CRUD** screens (entry points only)
- AUD-CON-006 / TB-SUB-STATE-001 exact entitlement matrices and dependent actions
- Checkout / proration / Razorpay
- Broker / OwnFleet / Partload operational workflows
- Internal Team product IA
- Full notification centre
- P2-WS-9

## Tests

- `WorkspacePanelTest` — landing, MRU, member/owner/mixed, zero-access, invalid context, deactivation, client tenant reject, modules hidden, subscription unavailable, deferred entry points + member forbid
- `WorkspaceNavigationServiceTest` — MRU + relationship listing
- Skipped: 2× Fortify 2FA SecurityTest (feature disabled) — does not affect WS-8

**Suite after closure fixes:** 284 tests / 282 passed / 2 skipped.

## Security checks

- No client `company_id` / `branch_id` query/body authority
- Owner Panel (including deferred pages) forbidden for members
- Branch Panel blocked when company deactivated
- Revoked membership denied
- Cross-company branch binding → 404
- MRU cleared when remembered owned company becomes inaccessible
- Visual subscription status is not authorization

## Browser / UI validation

- Performed against local `php artisan serve` (127.0.0.1:8765) with seeded QA users
- Confirmed: multi-owner selection, Owner Dashboard, Branch Panel, module hide, Owner return, switcher context
- Pre-fix: company select `router.post` did not POST in browser automation — fixed
- Desktop-wide layout not exhaustively exercised (automation viewport mobile-width); see closure matrix manual checklist
- `npm run build` green
