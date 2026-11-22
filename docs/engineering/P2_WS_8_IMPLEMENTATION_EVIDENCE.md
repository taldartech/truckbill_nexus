# P2-WS-8 Implementation Evidence

**Date:** 22 November 2026  
**Authorization:** Product Owner instruction — P2-WS-8 Product UI within approved design scope (PO-P2-DESIGN-FORMAL)  
**Baseline:** `99f7a0a3c6785ed6166271350e79a274c9b8329a`  
**Implementation commit:** `3c2e8c9abf687aaeb502cb22be0dbec1f42568d8`  
**Verdict candidate:** COMPLETE — READY FOR PRODUCT OWNER REVIEW (engineering)

## Scope delivered

| Milestone                   | Delivered                                                                                                                                                                 |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1 Shared UI foundation      | Workspace-aware sidebar, company switcher, status badge, empty/error/no-access states; Internal Team keeps technical `/dashboard`                                         |
| 2 Workspace selection       | Landing resolver, unified/owned company selection, sequential branch selection, server MRU (`users.mru_owned_company_id`), zero-access screen, enter company/branch posts |
| 3 Owner Panel               | Owner Dashboard, branches entry, deactivated `admin_billing_only` presentation                                                                                            |
| 4 Branch Panel              | Branch Dashboard, Branch Settings (prefs note only), hide unavailable modules (empty module list)                                                                         |
| 5 Subscription presentation | High-level unavailable presentation; `actions_allowed: false`; no invented entitlements                                                                                   |

## Routes

- `workspace.landing`, `workspace.no-access`, `workspace.companies.select`, `workspace.companies.enter`
- `workspace.branches.select`, `workspace.branches.enter`
- `owner.dashboard`, `owner.subscription`, `owner.branches`
- `branch.dashboard`, `branch.settings`

Fortify `home` → `/workspace`.

## Backend changes

- `WorkspaceNavigationService` — accessible companies/branches; MRU remember/resolve/clear
- `WorkspaceSession` — server-set session context keys (not client tenant authority)
- Controllers under `Workspace/`, `Owner/`, `Branch/`
- Migration: `mru_owned_company_id` on `users` (nullable FK → companies, nullOnDelete)
- `HandleInertiaRequests` shares customer `workspace` companies + active ids
- Authorization always via `CompanyAccessService` + route model binding; `RejectClientTenantIdentifiers` unchanged

## Deferred / excluded (explicit)

- AUD-CON-006 / TB-SUB-STATE-001 exact entitlement matrices and dependent actions
- Checkout / proration / Razorpay
- Broker / OwnFleet / Partload operational workflows
- Internal Team product IA
- Full notification centre
- Company admin CRUD screens beyond entry points / dashboards (create/edit companies, invite UI product flows)
- P2-WS-9

## Tests

- `tests/Feature/WorkspacePanelTest.php` — landing, MRU, member/owner/mixed, zero-access, invalid context, deactivation, client tenant reject, modules hidden, subscription unavailable
- `tests/Feature/WorkspaceNavigationServiceTest.php` — MRU + relationship listing
- Updated shell/auth/dashboard expectations for `/workspace` home

**Suite:** 280 passed / 2 skipped (full `php artisan test --compact`).

## Security checks

- No client `company_id` / `branch_id` query/body authority
- Owner Panel forbidden for members
- Branch Panel blocked when company deactivated
- Revoked membership denied
- Cross-company branch binding → 404
- Visual subscription status is not authorization

## Browser / UI validation

- Vite production build succeeded (`npm run build`)
- No Pest browser plugin installed — UI verified via Inertia feature assertions + build; interactive browser QA remains for Product Owner review

## Deviations from design artefacts

- Owner “Users/memberships/audit” nav items are not full CRUD screens yet — dashboards expose approved admin/context entry regions; detailed management UIs remain future slices within Owner Panel capability (not invented permissions)
- Subscription shows authoritative “unavailable / not provisioned” rather than fake Active/Trial/Grace labels without data
