# Complete Codebase and Access Audit

**Baseline HEAD (remote verified):** `4e8a91579283d592039ccc7ecbf29e3b7610c323`  
**Local tip at audit start of this batch:** `434f2f887a145c95d1b91d2db2ce7bd95d224065` (GA #86 unused-import fix)  
**Date:** 23 November 2026  
**Scope:** Phase 1–5 implemented surface (no Phase 6)  
**Method:** Fresh repository introspection (routes, Permission Master, controllers, Pest, Playwright, UI pages) — not prior certification claims alone.

## Executive summary

| Area                      | Finding                                                                                                                                         |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| CI #86                    | Fixed: six unused ESLint imports removed; `npm run check` clean                                                                                 |
| Customer permission codes | 41 registered via `PermissionCodes` + `RbacSeeder`                                                                                              |
| Default Customer roles    | 5; platform matrix matches PRD §6; **no** module blanket grants                                                                                 |
| Internal ordinary roles   | 3 seeded **without** permissions (configurable)                                                                                                 |
| Owner vs ops permissions  | Owner Panel ≠ operational PM codes; confirmed in Authorizer + HTTP                                                                              |
| Masters Owner OR-bypass   | `AuthorizesBranchPanel::masterPermissions` grants Owner platform-like master actions without branch role — **document, do not silently change** |
| Protected named routes    | ~200 application routes; contract test added                                                                                                    |
| Playwright                | 6 product specs under `tests/e2e` (auth/owner/branch/company) — persona RBAC suite **incomplete**                                               |
| UI redesign               | Prior pass was largely PageShell/token; Trip Detail now tabbed IA; remaining screens still need structural redesign + browser evidence          |

## Issue register

| ID      | Module/Phase        | File                              | Business impact                                        | Security impact                                           | Severity       | Root cause                                                                             | Required change                                                                 | Test evidence                                                           | Status               | Fix commit         |
| ------- | ------------------- | --------------------------------- | ------------------------------------------------------ | --------------------------------------------------------- | -------------- | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- | ----------------------------------------------------------------------- | -------------------- | ------------------ |
| AUD-001 | CI / Frontend       | Six branch TSX pages              | CI red on main                                         | None                                                      | Medium         | Unused `Link`/`FormSection` imports after shell redesign; `vp check` fails on warnings | Remove unused imports; keep AppIconAction navigation                            | `npm run check` pass                                                    | Fixed                | `434f2f8`          |
| AUD-002 | RBAC / Owner        | `PermissionAuthorizer`, Trip HTTP | Owner may assume ops rights                            | Privilege confusion                                       | High (clarity) | Design: Owner designation separate from PM codes                                       | Keep rule; expand HTTP matrix for all modules                                   | `OwnerOperationalPermissionHttpTest`, `PermissionMasterTest` owner case | Verified / expanding | pending RBAC batch |
| AUD-003 | Masters / Branch    | `AuthorizesBranchPanel.php`       | Owner can manage masters without ordinary role         | Broader than Trip/LR module assert                        | Medium         | Intentional OR `$isOwner \|\| authorizer`                                              | Document as approved masters behavior vs module codes; align only if PO revises | Pending dedicated masters Owner HTTP tests                              | Open                 | —                  |
| AUD-004 | RBAC coverage       | Module controllers                | Gaps in deny/revoke/subscription matrix per code       | Incomplete assurance                                      | High           | Suite grew feature-first; catalogue unit coverage incomplete historically              | Catalogue + HTTP grant/deny per code + route contract                           | `CustomerPermissionCatalogueCoverageTest` (41 codes)                    | Partial              | pending            |
| AUD-005 | No-Freight          | Trip/Invoice/Payment props        | Financial leak risk                                    | High if incomplete                                        | High           | Must redact server payloads                                                            | Expand negative tests to LR print/export/search                                 | `OwnFleetNoFreightViewTest` exists; surfaces incomplete                 | Partial              | —                  |
| AUD-006 | Playwright personas | `tests/e2e`                       | Browser RBAC unverified for most roles                 | UI may show forbidden actions                             | High           | Only smoke + few flows                                                                 | Expand role/permission/tenant e2e                                               | 6 specs                                                                 | Open                 | —                  |
| AUD-007 | UI IA               | Most of 88 pages                  | Daily ops UX remains skeleton-like                     | N/A                                                       | High (PO req)  | Shared shell without per-screen IA                                                     | Structural redesign + screenshots per screen                                    | Trip Detail tabs (`DetailTabs`/`WorkflowProgress`) started              | Partial              | pending            |
| AUD-008 | Route contract      | `routes/web.php`                  | New routes can ship without auth tests                 | Auth gaps                                                 | Medium         | No failing contract previously                                                         | `ProtectedRouteContractTest` + matrix doc                                       | Pest contract                                                           | In progress          | pending            |
| AUD-009 | Internal roles      | CRM/Support                       | Ordinary internals have empty PM by default            | Correct if configured; dangerous if UI assumes role title | Medium         | Seeder design                                                                          | Test granted vs absent for Sales/Support                                        | Partial (revocation in PermissionMasterTest)                            | Open                 | —                  |
| AUD-010 | Subscription×role   | Entitlement gate                  | Mutations may be tested without all module×role combos | Bypass risk                                               | High           | Combinatorial                                                                          | Dedicated matrix Pest                                                           | `SubscriptionRoleCombinationTest` started                               | Partial              | —                  |
| AUD-011 | OwnFleet Trip HTTP  | `TripController`                  | Broker-only co. could open Trip index with `trip.view` | Module entitlement bypass on Trip views                   | High           | Index used PM only; TripService mutations already entitled                             | Assert OwnFleet module in `assertPermission` (+ index)                          | `SubscriptionRoleCombinationTest` broker-only                           | Fixed                | pending commit     |

## Inventory (verified counts)

- TSX pages: **88** (`admin` 15, `owner` 24, `branch` 37, `auth` 4, `settings` 3, `workspace` 3, + dashboard/welcome).
- Customer permission codes: **41**.
- Default Customer roles: **5** (`customer.admin|operator|viewer|operator_no_freight|viewer_no_freight`).
- Default Internal roles: **3** (`internal.admin|sales_executive|support_executive`) — no default PM grants.
- Playwright product specs: **6** (plus fixtures).
- Named web routes: **216** total from `route:list` (includes framework/auth).

## Owner authorization (actual behavior)

1. `CompanyAccessService` grants Owner Panel + branch **entry** for designated Owner.
2. `PermissionResolver::codesForCustomerBranch` returns **[]** without an active branch role assignment (Owner alone).
3. Trip/Enquiry/LR/Invoice controllers call `PermissionAuthorizer` — Owner without role → denied.
4. Masters hub uses `$isOwner || allowsCustomer(...)` for view/create/edit/delete — Owner **can** mutate masters without ordinary role.
5. Do **not** silently unify (3) and (4) without PO decision.

## Default Customer platform matrix (seeded)

| Permission      | Admin        | Operator | Viewer | Op No Freight | Viewer No Freight |
| --------------- | ------------ | -------- | ------ | ------------- | ----------------- |
| view            | Y            | Y        | Y      | Y             | Y                 |
| create          | Y            | Y        | N      | Y             | N                 |
| edit            | Y            | N        | N      | N             | N                 |
| delete          | N (explicit) | N        | N      | N             | N                 |
| cancel          | Y            | N        | N      | N             | N                 |
| print           | Y            | Y        | Y      | Y             | Y                 |
| export          | Y            | N        | N      | N             | N                 |
| manage_settings | Y            | N        | N      | N             | N                 |
| view_freight    | Y            | Y        | Y      | N             | N                 |

Module codes (enquiry, quotation, lr, trip, invoice, payment): **registered, not granted by default**.

## Next batches

- B–C: HTTP grant/deny for every protected mutation; No-Freight surfaces; subscription combos; Internal grant/absent.
- D–J: Structural UI redesign per portal with browser screenshots (not token-only).
- K–L: Playwright personas + final certification metrics (honest, no fabricated counts).

## Restrictions preserved

Live Razorpay off; sample invoices not production tax invoices; ADR-010 GL Pending; Phase 9 messaging deferred; Phase 6 not started; no push; no Homebrew MySQL datadir; no production deploy.
