# Portal-Wide UX Remediation — Final Acceptance Report

**Date:** 24 November 2026  
**Authority:** Product Owner portal UX remediation directive  
**Phase 7:** Not started

## A. Initial / Final HEAD

| Ref                                           | SHA                                                            |
| --------------------------------------------- | -------------------------------------------------------------- |
| Verified remote baseline (Actions #88 parent) | `fef49e6c656581d995c2f33d0a696cccecd6f24e`                     |
| Tip before Batch I–J working tree             | `afad55134a58d64a144a9e9ef3fb450203ce836a` (ahead of origin)   |
| Final tip after Batch I–J commit              | _(recorded in IMPLEMENTATION_LOG / Memory after local commit)_ |

**No push performed.** Remote Actions #88 remains red until Product Owner pushes remediation commits.

## B. GitHub Actions #88 Resolution

| Failure                                                                                                   | Fix                                                 | Commit    |
| --------------------------------------------------------------------------------------------------------- | --------------------------------------------------- | --------- |
| MySQL errno 1830 on `linked_bilty_groups_company_payee_fk` (`ON DELETE SET NULL` + NOT NULL `company_id`) | `restrictOnDelete()` + forward corrective migration | `1dae925` |
| Pint `LinkedBiltyGroup` unary/not-operator spacing                                                        | Pint                                                | `1dae925` |

Evidence: `docs/engineering/GITHUB_ACTIONS_88_REMEDIATION.md`.  
**Remote Actions green:** Not claimed (requires PO push).

## C. Total UI Screens Audited

| Portal                | Screens in matrix | Notes                                              |
| --------------------- | ----------------: | -------------------------------------------------- |
| Owner                 |                14 | Includes global + company-scoped surfaces          |
| Internal              |                 7 | CRM + admin lifecycle / SA invoices                |
| Branch / OwnFleet     |                11 | Masters, LR, trips, unified invoices               |
| Broker                |                 8 | Including registers + balances                     |
| **Total matrix rows** |            **40** | Route-reachable product screens in redesign matrix |

Approx. **104** TSX page files exist under `resources/js/pages/`; matrix focuses on user-facing route screens (wrappers/print forms excluded from Fixed counts).

## D. Total UI Defects Found

**12** unique defect IDs in `PORTAL_WIDE_UX_DEFECT_REGISTER.md` (UX-001…UX-012), plus follow-on density/truncation notes recorded as remaining.

## E. Total UI Defects Fixed

| Status  |                                               Count |
| ------- | --------------------------------------------------: |
| Fixed   |                                                  10 |
| Partial |     2 (UX-009 list leftovers / UX-010 form density) |
| Open    | 0 in register (remaining deferred as Partial notes) |

## F. Remaining Defects

1. **UX-009 Partial:** Owner settings / draft-contract surfaces still use conventional text primary buttons (acceptable for page CTAs); not every historic text link in rarely used detail tabs was iconized.
2. **UX-010 Partial:** Some multi-section forms (LR/Trip create) still use tall vertical stacking; densified filters/lists completed for primary indexes.
3. **Invoice list at ~1280px:** Customer/LR columns truncate by design (`max-w` + expand at narrower breakpoints); Source/Status now `whitespace-nowrap` in Batch I.
4. **MySQL full Pest:** Not re-run locally against disposable Docker DB in this pass; migration strategy certified in GA #88 doc. CI mysql job expected to validate after push.
5. **Remote CI / production:** Not claimed.

## G. Users Actions

| Surface       | Result                                                             | Evidence                                |
| ------------- | ------------------------------------------------------------------ | --------------------------------------- |
| Global Users  | Icon `Manage membership` (Settings2 / info); no bare `Manage` text | Playwright + `global-users-desktop.png` |
| Company Users | View icon actions (Batch E)                                        | Matrix Fixed                            |

## H. Audit Actions

| Surface       | Result                            | Evidence                                |
| ------------- | --------------------------------- | --------------------------------------- |
| Global Audit  | Icon `Open company audit`         | Playwright + `global-audit-desktop.png` |
| Company Audit | Compact filters + icons (Batch E) | Matrix Fixed                            |

## I. Company Action Colors

| Action       | Variant    | Evidence                                |
| ------------ | ---------- | --------------------------------------- |
| Edit         | Amber      | `owner-companies-desktop.png`           |
| Branches     | Blue/Teal  | same                                    |
| Users        | Blue       | same                                    |
| Subscription | Green      | same                                    |
| Audit        | Slate/Blue | same (wraps under dense action cluster) |
| Create       | Green      | same                                    |

Root fix: `WorkspaceContextAction` forwards semantic `variant` to `AppIconAction`.

## J. Table Expansion Behavior

| Case                            | Result                                                                      |
| ------------------------------- | --------------------------------------------------------------------------- |
| Desktop ~1280px companies table | No `Show row details` (Playwright)                                          |
| Mobile 390px goods-types        | Expand chevron present for hidden P1+ fields (`goods-types-mobile-390.png`) |
| Implementation                  | `expandControlVisibilityClass(maxPriority)` CSS paired with column priority |

## K. Unified Invoice Navigation and Architecture

| Item   | Result                                                                         |
| ------ | ------------------------------------------------------------------------------ |
| Nav    | Single Branch **Invoices** → `/invoices`                                       |
| Facade | `UnifiedCustomerInvoiceReadFacade` (non-destructive; source services retained) |
| List   | OwnFleet + Broker fixtures visible (`unified-invoices-list.png`)               |
| Create | Context from selected LRs; redirects from legacy module indexes                |
| Docs   | `UNIFIED_CUSTOMER_INVOICE_ARCHITECTURE.md`                                     |

## L. Customer Payments Navigation

| Item  | Result                                                                                     |
| ----- | ------------------------------------------------------------------------------------------ |
| Label | **Customer Invoice Receipt** (distinct from Transporter Voucher Pay)                       |
| Route | `/payments` remains OwnFleet receipt surface; Broker voucher payouts stay under Broker nav |

## M. Separate Master Pages

Routes live: `/goods-types`, `/truck-types`, `/tyre-layouts`, `/document-types`, `/expense-categories`, `/package-types`.  
Playwright visited all six after Alpha branch entry. Screenshot: `goods-types-branch.png`.  
Docs: `MASTERS_NAVIGATION_REDESIGN.md`.

## N. Partload Workflow Removal

`Create Partload Bilty` has **no** Workflow field (`partload-create-form.png`). Backend Partload classification preserved.

## O. Empty-Space and Layout Improvements

PageShell/FormSection densification on Owner/Internal/Branch/Broker list surfaces (Batches E–H). Metric cards and compact filter toolbars applied to registers, balances, subscription overview, SA invoice approval.

## P. Owner Portal Completion

Matrix: Fixed for primary management lists; settings/drafts Partial. Browser: companies/users/audit screenshots reviewed.

## Q. Internal Portal Completion

CRM dashboard/leads/tickets + SA subscription invoice approve icon Fixed. Identity show Partial.

## R. Branch Portal Completion

Masters separated; unified invoices; payments naming; LR/trips/parties icon actions Fixed. LR create multi-column polish Partial.

## S. Broker Portal Completion

Registers hub icon tiles (`broker-registers-hub.png`); transporter balances ResponsiveDataTable; hire/voucher/delivery/invoice/linked lists Fixed in Batch H + I polish.

## T. Role / Permission Regression

Focused Pest: Broker + CustomerInvoice + Phase3Masters **63 passed**.  
Full PostgreSQL Pest: **exit 0** (local tip).  
No-Freight / RBAC: prior suites retained; unified facade authorizes per source.  
**Not claimed:** exhaustive 11-persona browser matrix in this run.

## U. Playwright Results

| Spec                                        | Result                                                                                                                                                                                             |
| ------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/e2e/portal/portal-ux-visual.spec.ts` | **1 passed** (Alpha branch fixture fixed)                                                                                                                                                          |
| Cases covered in journey                    | Companies colors, Users icons, Audit icons, expand hidden desktop, 6 masters routes, unified invoices nav+list, payments nav label, mobile 390 goods-types, Partload no Workflow, Broker registers |
| Skipped                                     | None after Alpha branch entry fix                                                                                                                                                                  |

## V. Screenshot Evidence

Directory: `docs/engineering/portal-ux-screenshots/`

| File                              | Reviewed                     |
| --------------------------------- | ---------------------------- |
| `owner-companies-desktop.png`     | Yes — semantic action colors |
| `global-users-desktop.png`        | Yes — icon manage            |
| `global-audit-desktop.png`        | Yes — icon company audit     |
| `goods-types-branch.png`          | Yes — Alpha Mumbai HO        |
| `goods-types-mobile-390.png`      | Yes — expand present         |
| `unified-invoices-nav-branch.png` | Yes                          |
| `unified-invoices-list.png`       | Yes — OwnFleet + Broker rows |
| `partload-create-form.png`        | Yes — no Workflow            |
| `broker-registers-hub.png`        | Yes — icon open tiles        |

## W. PostgreSQL Tests

| Suite                                            | Result                                      |
| ------------------------------------------------ | ------------------------------------------- |
| Focused Broker / CustomerInvoice / Phase3Masters | **63 passed** (250 assertions)              |
| Full `php artisan test --compact`                | exit **0** (local; exit code authoritative) |

## X. MySQL Tests

| Item                             | Result                                                                  |
| -------------------------------- | ----------------------------------------------------------------------- |
| Local disposable MySQL full Pest | **Not run** this pass (Homebrew datadir untouched; no `.env` overwrite) |
| Migration compatibility          | GA #88 FK strategy + forward fix committed                              |
| CI mysql job                     | Pending PO push                                                         |

## Y. Build / Pint / Larastan

| Check                                        | Result                                                     |
| -------------------------------------------- | ---------------------------------------------------------- |
| `npx vp check --fix` / `npm run types:check` | Pass (Batch I)                                             |
| `npm run build`                              | Pass (Batch I)                                             |
| `vendor/bin/pint`                            | Pass                                                       |
| `vendor/bin/phpstan analyse`                 | Pass (0 errors) after removing unused invoice list helpers |

## Z. Local Commits and Certification Verdict

### Local commits (Batches A–J tip chain)

| Batch | Commit                          | Summary                                                                         |
| ----- | ------------------------------- | ------------------------------------------------------------------------------- |
| A     | `1dae925`                       | GA #88 MySQL FK + Pint                                                          |
| B     | `e87b603`                       | Semantic actions + expand                                                       |
| C     | `51b5fc5`                       | Separate masters + Partload Workflow removal                                    |
| D     | `aea9599`                       | Unified customer invoices                                                       |
| E–H   | `bd3b724`…`0e53351` + `afad551` | Portal polish + docs SHAs                                                       |
| I–J   | _(this commit)_                 | Remaining Open screens, Playwright evidence, PHPStan dead-code, acceptance docs |

### Verdict

**PORTAL UX REMEDIATION — LOCALLY COMPLETE WITH DOCUMENTED REMAINING PARTIALS**

- P0 Actions #88 fixed locally; remote green not claimed.
- Confirmed PO defects (icon actions, company colors, empty expand, masters split, Partload Workflow, unified Invoices) verified with browser screenshots on Demo Logistics Alpha.
- Phase 7 Partload operations **not** started.
- Absolute **no-push**.
