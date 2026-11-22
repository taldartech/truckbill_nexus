# Phase 1–5 Requirements-to-Code Gap Audit

**Date:** 23 November 2026  
**Baseline HEAD:** `06ead4bd09730741e3c406ce90e128b8aeccbae7`  
**Method:** Governing docs + ADRs + acceptance records + executable routes/controllers/pages/migrations/tests  
**Rule:** Historical phase acceptance ≠ exhaustive PRD completeness. Pending/Deferred decisions are **not** treated as Approved.

---

## 1. Executive summary

| Phase | Formal status                              | End-to-end product completeness vs this prompt                                                            | Primary blockers                                                    |
| ----- | ------------------------------------------ | --------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| 0     | Approved baseline                          | N/A (docs)                                                                                                | —                                                                   |
| 1     | Authorized; foundation in use              | **Mostly Complete** (technical shell + infra)                                                             | PO-P1-DEPLOY Deferred; Playwright/k6 **not installed**              |
| 2     | Accepted WITH EXCEPTIONS                   | **Partial** — branch admin Complete; PRD §7 company create **Blocked**; Internal lifecycle UI **Blocked** | Company schema/actor; Internal IA; membership reactivation Deferred |
| 3     | Accepted WITH EXCEPTIONS (EX-01…07)        | **Mostly Complete** for authorized masters/docs/subscription UI                                           | Live Razorpay, AUD-CON-006, ADR-010, tax invoice, PDF               |
| 4     | Batch A–B backend Complete; C+ not started | **Partial / Blocked** for product UI, quotation, LR ops, conversion                                       | PO-P4-DESIGN-FORMAL; PO-P4-CNV-CARDINALITY; Batch C+ auth           |
| 5     | **Not authorized**                         | **Missing**                                                                                               | PO-P5-AUTH + design                                                 |

**Overall:** Cannot certify Phases 1–5 as fully implemented. Correct verdict: **PARTIALLY IMPLEMENTED — APPROVED WORK COMPLETE, GATES REMAIN**.

---

## 2. Prioritized remediation register

| ID         | Sev | Area                 | Finding                                                                           | Action                                                                          | Gate                |
| ---------- | --- | -------------------- | --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- | ------------------- |
| GAP-P0-001 | P0  | Security/tooling     | Playwright/k6/CI E2E not present; cannot claim browser/perf certification         | Request `PO-P1-DEPS-E2E` / `PO-P1-DEPS-K6`; then install + wire CI              | Dependency approval |
| GAP-P0-002 | P0  | Phase 4 future       | `lr_goods_rows` full unique on position risks DEC-003 conflict when LR edit ships | Fix in Batch E with soft-delete + active unique (same pattern as enquiry goods) | Batch E + design    |
| GAP-P1-001 | P1  | Company              | PRD §7 fields not in schema/UI                                                    | Consolidate PO decisions → migrate → full-stack create/edit                     | Company PO package  |
| GAP-P1-002 | P1  | Internal             | Lifecycle/transfer HTTP without product UI                                        | Approve Internal IA → implement screens                                         | Internal design     |
| GAP-P1-003 | P1  | Phase 4 UI           | Enquiry JSON API only; no Inertia product UI                                      | Approve PO-P4-DESIGN-FORMAL → Batch C+ full-stack                               | Design + batch auth |
| GAP-P1-004 | P1  | Quotation/LR/Convert | Not implemented beyond foundations                                                | Batches D–F after design + cardinality                                          | Multiple            |
| GAP-P1-005 | P1  | Phase 5              | OwnFleet ops absent                                                               | PO-P5-AUTH + design → implement                                                 | Phase 5 auth        |
| GAP-P2-001 | P2  | E2E coverage         | Pest-only browser evidence                                                        | After Playwright approval, encode critical journeys                             | GAP-P0-001          |
| GAP-P2-002 | P2  | Perf                 | No measured baseline; Architecture 5k concurrent is objective not claim           | k6 smoke/baseline after env + deps                                              | GAP-P0-001          |
| GAP-P3-001 | P3  | Demo data            | Seeder covers P2–P4 enquiry fixtures; not P5/quotation/LR                         | Extend after modules exist                                                      | Feature gates       |

---

## 3. Traceability matrix (condensed)

Status legend: **C** Complete · **P** Partial · **M** Missing · **B** Blocked · **D** Deferred

### Phase 1 — Foundation

| ID        | Module             | Requirement                      | Decision     | DB            | Backend            | Frontend    | AuthZ       | Pest | Playwright | k6    | Status       | Action             |
| --------- | ------------------ | -------------------------------- | ------------ | ------------- | ------------------ | ----------- | ----------- | ---- | ---------- | ----- | ------------ | ------------------ |
| P1-AUTH   | Auth               | Fortify login/reset              | DEC-006/009  | users         | Fortify            | auth pages  | Session     | Yes  | **M**      | —     | **C** (Pest) | Add E2E after deps |
| P1-TENANT | Tenancy            | Company/branch context           | Architecture | —             | TenantContext      | Shell       | Fail closed | Yes  | **M**      | —     | **C**        | —                  |
| P1-AUDIT  | Audit              | Append-only + remarks            | ADR-003      | audit_entries | AuditWriter        | Owner audit | Scope       | Yes  | **M**      | —     | **C**        | —                  |
| P1-FILE   | Files              | Private + scan                   | Architecture | files         | PrivateFileService | Docs drawer | Tenant      | Yes  | **M**      | —     | **C**        | —                  |
| P1-OUTBOX | Outbox/idempotency | Reliable side effects            | Architecture | outbox        | Jobs               | —           | —           | Yes  | —          | —     | **C**        | —                  |
| P1-CI     | CI                 | Format/types/build/Pest/Larastan | Phase 1      | —             | GHA tests.yml      | —           | —           | Yes  | **M**      | **M** | **P**        | Extend after deps  |
| P1-SHELL  | UI shell           | Chrome only                      | Design gate  | —             | —                  | App shell   | —           | Yes  | **M**      | —     | **C**        | —                  |

### Phase 2 — Identity / company / access

| ID           | Module                      | Requirement                                  | Decision                | DB                    | Backend                          | Frontend         | AuthZ          | Pest   | PW    | k6  | Status | Action                      |
| ------------ | --------------------------- | -------------------------------------------- | ----------------------- | --------------------- | -------------------------------- | ---------------- | -------------- | ------ | ----- | --- | ------ | --------------------------- |
| P2-CO-CREATE | Company create PRD §7       | Full legal/GST/bank/visibility               | **Pending** package     | name only             | createCompany name+HO            | **M**            | Customer       | Domain | **M** | —   | **B**  | PO decisions then implement |
| P2-CO-EDIT   | Company profile             | Name (+ deferred masters)                    | WS-8                    | name                  | updateCompany                    | owner/settings   | Owner full     | Yes    | **M** | —   | **P**  | After schema approval       |
| P2-SWITCH    | Switcher                    | Multi-company                                | PO-P2-DESIGN-SWITCHER   | —                     | Access/Navigation                | CompanySwitcher  | Membership     | Yes    | **M** | —   | **C**  | —                           |
| P2-BR-ADMIN  | Branch CRUD                 | List/create/edit/deactivate/assign on create | OWNER_PANEL_IA / PRD §3 | branches              | OwnerBranchAdministrationService | owner/branches/* | Owner+capacity | Yes    | **M** | —   | **C**  | Add Playwright after deps   |
| P2-OWN-USERS | Memberships/invites/roles   | Invite/assign/deactivate                     | PO-P2-INVITE            | memberships           | OwnerUsersController             | owner/users/*    | Owner          | Yes    | **M** | —   | **C**  | —                           |
| P2-MEM-REACT | Membership reactivation     | Reactivate                                   | **Deferred**            | —                     | **M**                            | **M**            | —              | —      | —     | —   | **D**  | Wait policy                 |
| P2-DEACT     | Company/identity deactivate | SA lifecycle                                 | PO-P2-DEACT-*           | deactivated_at        | Lifecycle controllers            | **M** UI         | SA             | Yes    | **M** | —   | **P**  | Internal UI package         |
| P2-XFER      | Ownership transfer          | Preserve data                                | P2-WS-7                 | ownership_transfers   | OwnershipTransferService         | **M** UI         | SA/admin       | Yes    | **M** | —   | **P**  | Internal UI                 |
| P2-SESSION   | Customer single session     | Web-only                                     | ADR-006                 | customer_web_sessions | EnforceWebSessionPolicy          | —                | —              | Yes    | **M** | —   | **C**  | No Sanctum                  |

### Phase 3 — Masters / documents / subscription

| ID       | Module              | Requirement                         | Decision                     | Status           | Action                     |
| -------- | ------------------- | ----------------------------------- | ---------------------------- | ---------------- | -------------------------- |
| P3-PARTY | Party + roles       | Full CRUD + docs                    | PO-P3-DESIGN-FORMAL          | **C** (accepted) | Preserve EX                |
| P3-VEH   | Vehicle             | CRUD + docs                         | Same                         | **C**            | —                          |
| P3-BANK  | Bank/Cash           | CRUD + masking                      | Same                         | **C**            | —                          |
| P3-STN   | Station             | Owner shared + default transporter  | Same                         | **C**            | —                          |
| P3-CAT   | Catalogues          | Owner catalogues                    | Same                         | **C**            | —                          |
| P3-DOC   | Documents           | Upload/replace/download/soft-delete | Same                         | **C**            | company_record UI Deferred |
| P3-SUB   | Subscription        | Catalogue/draft/checkout test mode  | PO-P3-PAY-TEST; PROD Pending | **P**            | No live Razorpay           |
| P3-INV   | Invoices            | Preview/email/number approval       | Sample ≠ tax invoice         | **P**            | EX preserved               |
| P3-ENT   | Entitlements matrix | Exact trial/grace                   | AUD-CON-006 **Pending**      | **B**/D          | Do not invent              |

### Phase 4 — Enquiry / quotation / LR / conversion

| ID    | Module                   | Requirement                                | Decision                          | Status                          | Action                            |
| ----- | ------------------------ | ------------------------------------------ | --------------------------------- | ------------------------------- | --------------------------------- |
| P4-A  | Foundations              | Schema/enums/numbering/webhook foundations | PO-P4-AUTH                        | **C** backend                   | —                                 |
| P4-B  | Manual + webhook enquiry | JSON API + secure webhook                  | PO-P4-BATCH-B-AUTH                | **C** backend                   | No Inertia                        |
| P4-UI | Enquiry product UI       | List/create/detail/queue                   | **PO-P4-DESIGN-FORMAL Pending**   | **B**                           | Formal design then Batch C+       |
| P4-C  | Assign/follow-ups        | Full-stack                                 | Needs batch auth + design         | **M**                           | Do not auto-start                 |
| P4-D  | Quotations               | Broadcast request + entry/select           | ADR-013 Option A; design          | **M**                           | After C                           |
| P4-E  | Unified LR               | Shared LR domain + UI                      | Design + Batch E                  | **M** (schema foundations only) | Fix goods unique with soft-delete |
| P4-F  | Conversion               | Whole enquiry → LR(s)                      | **PO-P4-CNV-CARDINALITY Pending** | **B**                           | Cardinality decision first        |

### Phase 5 — OwnFleet

| ID     | Module                                    | Requirement      | Decision          | Status | Action                  |
| ------ | ----------------------------------------- | ---------------- | ----------------- | ------ | ----------------------- |
| P5-ALL | Trip/dispatch/expense/POD/invoice/payment | PRD OwnFleet ops | **No PO-P5-AUTH** | **M**  | Authorize Phase 5 first |

---

## 4. What must not be claimed Complete

- Full PRD §7 company creation UI/schema
- Internal lifecycle product UI
- Phase 4 Enquiry/Quotation/LR/Conversion product workflows
- Phase 5 OwnFleet
- Playwright or k6 certification
- 5,000 concurrent users support
- Live Razorpay / production tax invoices / ADR-010 postings

---

## 5. Independently authorized next work (no invented approvals)

1. Stage 0 documentation + consolidated PO decision sheet (**this package**).
2. After **explicit** PO approvals: company schema/UI; Internal UI; Phase 4 design + Batches C–F; Phase 5.
3. After **dependency approval**: Playwright + k6 + CI smoke.
4. Phase 3 EX preservation — defect-only hardening if found (none P0 open beyond tooling).
5. Branch/Owner management already delivered at tip `06ead4b` — do not rebuild.
