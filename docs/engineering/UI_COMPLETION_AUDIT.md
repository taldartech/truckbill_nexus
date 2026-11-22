# UI Completion Audit — Phases 1–4

**Date:** 23 November 2026 (updated — Phase 2 management follow-on)  
**Baseline HEAD (pre-management follow-on):** `434c32c34d34cd7544bde3846d1b1b073c8f9d5f`  
**Method:** Executable routes/controllers/Inertia pages + governing design/acceptance records  
**Authority:** Design approvals and Product Owner decisions override filenames  
**Management audit:** `docs/engineering/PHASE_2_MANAGEMENT_GAP_AUDIT.md`

---

## 1. Summary

| Phase | Screens reviewed                          | Complete                                                             | Needs work                                        | Blocked / deferred                                                             |
| ----- | ----------------------------------------- | -------------------------------------------------------------------- | ------------------------------------------------- | ------------------------------------------------------------------------------ |
| 1     | Shell, auth foundation                    | Yes (technical shell)                                                | None material                                     | —                                                                              |
| 2     | Workspace, Owner, Branch                  | Accepted w/ exceptions; **branch admin UI completed** this follow-on | Company create (PRD §7) gated                     | Membership reactivation; Internal Team IA UI; AUD-CON-006; full company create |
| 3     | Masters, documents, subscription, GST/TDS | Yes (accepted with exceptions)                                       | Follow-up polish **corrected** (nav/email/orphan) | EX-01…EX-07; company_record UI deferred on Owner settings                      |
| 4 A–B | Enquiry foundations + backend             | Backend complete                                                     | DEC-003 goods soft-delete **fixed** prior task    | Product Enquiry UI until `PO-P4-DESIGN-FORMAL`                                 |
| 4 C+  | Not started                               | —                                                                    | —                                                 | Not authorized                                                                 |

**Conclusion:** Phase 2 **navigation** was previously complete; **Owner branch administration** (create/edit/deactivate/capacity/member assign on create) was incomplete and is now implemented. Full PRD §7 company creation and Internal lifecycle product UI remain gated. Historical Phase 2 formal acceptance is unchanged.

---

## 2. Coverage matrix

| Phase | Module             | Screen                                                             | Design                                             | Backend         | Frontend                                 | Integration                  | AuthZ               | Tests        | Status                 | Action                                              |
| ----- | ------------------ | ------------------------------------------------------------------ | -------------------------------------------------- | --------------- | ---------------------------------------- | ---------------------------- | ------------------- | ------------ | ---------------------- | --------------------------------------------------- |
| 1     | Shell              | App / Owner / Branch shells, header, sidebar, switcher             | Approved (brand DEC-007)                           | N/A             | Complete                                 | Connected                    | Session policy      | Existing     | **Complete**           | None                                                |
| 1     | Auth               | Login, forgot/reset password                                       | Approved                                           | Complete        | Complete                                 | Connected                    | Fortify             | Existing     | **Complete**           | None                                                |
| 2     | Workspace          | Landing, company/branch select, no-access                          | PO-P2-DESIGN-FORMAL                                | Complete        | Complete                                 | Connected                    | CompanyAccess       | Existing     | **Complete**           | None                                                |
| 2     | Owner              | Dashboard, users, settings, audit                                  | PO-P2-DESIGN-FORMAL                                | Complete        | Complete                                 | Connected                    | Owner scope         | Existing     | **Complete**           | None                                                |
| 2     | Owner              | Branch admin (list/create/edit/deactivate/enter)                   | OWNER_PANEL_IA §3.3 / PRD §3                       | Complete        | **Complete** (was enter-only)            | Connected                    | Owner + capacity    | New Pest     | **Complete**           | Prior audit overstated “branches” as admin-complete |
| 2     | Owner              | Company create (PRD §7 full)                                       | Pending legal/tax/visibility                       | Partial service | Missing product create                   | —                            | TBD                 | Domain       | **Requires PO**        | See company creation decision package               |
| 2     | Branch             | Dashboard, settings                                                | PO-P2-DESIGN-FORMAL                                | Complete        | Complete                                 | Connected                    | Branch panel        | Existing     | **Complete**           | None                                                |
| 2     | Internal Admin     | Identity/company lifecycle, ownership transfer                     | Internal IA **out of scope** (P2 design exception) | Complete (HTTP) | **No product UI** (authorized exception) | API/redirect only            | SA / internal.admin | Pest         | **Blocked / deferred** | Do not invent Internal CRM UI                       |
| 2     | Memberships        | Reactivation UI                                                    | Deferred in acceptance                             | Partial         | Missing by design                        | —                            | —                   | —            | **Deferred**           | Wait for API/policy                                 |
| 3     | Party              | List/create/edit/show + roles                                      | PO-P3-DESIGN-FORMAL + UI-AUTH                      | Complete        | Complete                                 | Connected                    | platform.*          | Pest         | **Complete**           | None                                                |
| 3     | Vehicle            | List/create/edit/show                                              | Same                                               | Complete        | Complete                                 | Connected                    | platform.*          | Pest         | **Complete**           | None                                                |
| 3     | Bank/Cash          | List/create/edit/show                                              | Same                                               | Complete        | Complete                                 | Connected                    | platform.*          | Pest         | **Complete**           | None                                                |
| 3     | Station            | List/create/edit/show + default transporter                        | Same                                               | Complete        | Complete                                 | Connected                    | platform.*          | Pest         | **Complete**           | None                                                |
| 3     | Catalogues         | Goods/Truck/Package/etc. hub                                       | Same                                               | Complete        | Complete                                 | Connected                    | Owner               | Pest         | **Complete**           | None                                                |
| 3     | Documents          | Shared drawer + documents index                                    | Same                                               | Complete        | Complete                                 | Connected                    | Parent authZ        | Pest         | **Complete**           | None                                                |
| 3     | Subscription       | Overview, catalogue, drafts, checkout, credits, payments, invoices | Same                                               | Complete        | Complete                                 | Connected                    | Owner               | Pest         | **Complete**           | Preserve EX-01…07                                   |
| 3     | Admin tax          | GST rates, TDS rules                                               | Batch G                                            | Complete        | Complete                                 | Connected + Internal sidebar | Internal            | Pest         | **Complete**           | Nav added this follow-up                            |
| 3     | Admin invoices     | Subscription invoice approval                                      | Batch G                                            | Complete        | Complete                                 | Connected + SA sidebar       | Super Admin         | Pest         | **Complete**           | Nav gated on `is_super_admin`                       |
| 3     | Documents          | Company-record (`company_record`) parent                           | Reserved on Owner settings                         | Complete        | Missing by design                        | Routes exist                 | Parent authZ        | Pest         | **Deferred**           | Do not invent; Owner settings deferred section      |
| 4     | Enquiry API        | Manual create/edit JSON + webhook                                  | Backend auth                                       | Complete        | N/A (backend-only exception Batch B)     | —                            | enquiry.* / token   | Pest Batch B | **Complete (backend)** | UI blocked                                          |
| 4     | Enquiry product UI | List/create/edit/detail/unassigned                                 | **PO-P4-DESIGN-FORMAL Pending**                    | Ready           | Missing                                  | —                            | —                   | —            | **Blocked**            | Formal design approval then implement               |
| 4     | Assignment+        | Batch C+ screens                                                   | Pending design per surface                         | Missing         | Missing                                  | —                            | —                   | —            | **Blocked**            | Full-stack Batch C+                                 |

---

## 3. Defects found this audit

| ID         | Severity | Finding                                                                                                                            | Resolution                                                                         |
| ---------- | -------- | ---------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| UI-DEF-001 | **P0**   | Enquiry edit used `forceDelete()` on goods rows (DEC-003 conflict); full unique `(enquiry_id, position)` blocked soft-delete reuse | Forward migration → active-only unique index; soft-delete on edit; regression Pest |
| UI-DEF-002 | Info     | `lr_goods_rows` still has full unique on position — same class of risk when Batch E implements LR edit                             | Document for Batch E; do not invent LR edit now                                    |
| UI-DEF-003 | Gate     | Phase 4 Enquiry nav intentionally absent from sidebar until design formal                                                          | Keep hidden                                                                        |
| UI-DEF-004 | P1       | Batch G admin Inertia pages had no Internal sidebar links                                                                          | **Corrected** — GST/TDS for Internal; subscription invoices for Super Admin only   |
| UI-DEF-005 | P1       | Owner subscription invoices list ignored `email_url`                                                                               | **Corrected** — Form POST Email action on list                                     |
| UI-DEF-006 | Cleanup  | Orphan `components/documents/document-drawer.tsx` (unused duplicate)                                                               | **Removed**; masters drawer remains canonical                                      |
| UI-DEF-007 | Deferred | `company_record` document routes without Owner UI                                                                                  | Leave deferred — Owner settings still marks company documents as reserved          |

---

## 4. Testing tooling note

- Pest feature suite: available and used
- Dedicated frontend unit/component runner (Vitest / Testing Library): **not installed**
- Playwright / browser plugin: **not installed**

Do not invent browser/e2e results. Critical-path browser QA remains a future tooling/authorization item.

---

## 5. Product Owner gates (unchanged)

- `PO-P4-DESIGN-FORMAL` — **Pending**
- `PO-P4-CNV-CARDINALITY` — **Pending**
- Phase 3 EX-01…EX-07 — unchanged
- Membership reactivation UI — Deferred
- Internal Team IA — out of Phase 2 Customer package scope
