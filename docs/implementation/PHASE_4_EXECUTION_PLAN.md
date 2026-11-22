# Phase 4 Execution Plan — Enquiry Management & Unified LR

**Status:** **PO-P4-AUTH APPROVED** (23 November 2026) — backend/domain authorized; **Batch A COMPLETE**; **Batch B COMPLETE** (PO-P4-BATCH-B-AUTH, 23 Nov 2026) — ready for Batch C review (not auto-started)  
**Authority:** `docs/governing/Phases.md` (sole phase-numbering and Phase 4 scope authority)  
**Authorization record:** `PHASE_4_AUTHORIZATION_RECORD.md`  
**Kickoff tip:** `193e6dc68b2509e90de4aa2990b1136260e80746` · Auth baseline tip: `2c3f351444ac7e80ffd0a723475c4b5e8a3234f5` (Actions #62)  
**Phase 3 accepted baseline:** `68d11b1ecc267e91d3adb37825d94def71334a3a` (PO-P3-FINAL-ACCEPT; Actions #60)

**Related:**

- `PHASE_4_REQUIREMENTS_TRACEABILITY.md`
- `PHASE_4_ARCHITECTURE_DESIGN.md`
- `PHASE_4_DESIGN_AND_UI_SPECIFICATION.md`
- `PHASE_4_TEST_STRATEGY.md`
- `PHASE_4_PRODUCT_OWNER_DECISION_PACKAGE.md`
- `PHASE_4_AUTHORIZATION_REQUEST.md`
- `docs/implementation/PHASE_3_ACCEPTANCE_RECORD.md`

**Hard rules under PO-P4-AUTH:**

- Backend Batches A–F authorized subject to remaining gates; **do not auto-start Batch C** after B without separate PO batch auth.
- Do **not** implement product UI until **PO-P4-DESIGN-FORMAL** (Pending). Batch B Enquiry UI package is ready for review in `docs/design/phase-4/PHASE_4_UI_REVIEW.md` — **not** self-approved.
- From **Batch C onward**, authorized user-facing slices deliver **backend + corresponding UI + integrated tests in the same batch** (`docs/engineering/FULLSTACK_DELIVERY_STANDARD.md`). Obtain design approval for each surface **before** that batch begins.
- **Batch G** is integration QA / polish / certification — **not** the first UI delivery for Batches C–F.
- Do **not** implement conversion cardinality behaviour until **PO-P4-CNV-CARDINALITY** (Pending).
- Do **not** modify Phase 3 accepted behaviour except shared infrastructure hooks.
- Do **not** install unapproved dependencies; do not invent AUD-CON-006 cells.
- Do **not** start Phase 5–7 operational workflows.
- Preserve Phase 3 accepted exceptions EX-01…EX-07 unchanged.

---

## 1. Scope

### In scope (Phases.md § Phase 4)

1. Website webhook + manual Branch Panel enquiry
2. Central unassigned queue; company/branch assignment; follow-ups
3. Quotation broadcast request history, manual entry, comparison, selection
4. Whole-enquiry conversion only (no partial/split)
5. Unified LR foundation (Broker / OwnFleet / Partload context-aware validation)
6. Goods rows, freight rate types, additional charges, multiple EWBs
7. Auto/manual LR numbering with concurrency protection
8. Documents, edit/cancel/soft-delete with dependency checks, audit

### Exit criteria (Phases.md)

- Duplicate conversion prevention
- Scope / tenant correctness
- Numbering concurrency
- Edit / cancel / delete dependency tests

### Explicitly out of scope

| Item                                      | Phase / gate             |
| ----------------------------------------- | ------------------------ |
| OwnFleet Trip lifecycle                   | Phase 5                  |
| Broker Lorry Hire / vouchers / Memo       | Phase 6                  |
| Partload Challan / Inward / DR            | Phase 7                  |
| Full WhatsApp/SMS/Email provider platform | Phase 9                  |
| Accounting GL postings                    | ADR-010 Pending          |
| Production Razorpay / live charges        | PO-P3-PAY-PROD Pending   |
| Invented AUD-CON-006 cells                | Pending                  |
| Sibling `website/` form UI changes        | Separate website project |
| New Composer/NPM packages                 | PO-P1-DEPS case-by-case  |

### Reuse first (Phases 1–3)

| Capability         | Reuse                                                                                                              |
| ------------------ | ------------------------------------------------------------------------------------------------------------------ |
| Tenant isolation   | `CompanyAccessService`, route binding, `RejectClientTenantIdentifiers`                                             |
| RBAC               | Permission Masters, `PermissionAuthorizer` (extend catalogue only with PO-approved codes)                          |
| Masters            | Party (roles), Station, Vehicle catalogues, Owner catalogue items (Goods/Package/Truck Type), Document attachments |
| Audit              | `AuditedTransaction`, append-only audit, mandatory remarks                                                         |
| Files              | `PrivateFileService`, document drawer, soft-delete retention                                                       |
| Async              | Outbox + idempotency                                                                                               |
| Subscription gates | Confirmed Rules §15 only — Branch mutations / credit consumption as already enforced                               |
| Sessions           | Fortify web sessions (ADR-006 web-only)                                                                            |

---

## 2. Authorization and design gates

| Gate          | Requirement                                                                    | Status                                      |
| ------------- | ------------------------------------------------------------------------------ | ------------------------------------------- |
| G-P4-AUTH     | Explicit **PO-P4-AUTH** authorizing this plan                                  | **APPROVED** (23 Nov 2026)                  |
| G-DESIGN-P4   | Design artefacts for Phase 4 product UI reviewed/approved before those screens | **PENDING** — draft only                    |
| G-WEBHOOK     | Website enquiry webhook contract (auth, payload, idempotency)                  | **APPROVED — V1** (Batch B endpoint)        |
| G-BROADCAST   | Quotation broadcast integration boundary vs Phase 9                            | **APPROVED — Option A** (ADR-013)           |
| G-CONVERT     | Conversion creates Unified LR only; Trip/LH/Partload ops deferred              | **APPROVED — Option A** (ADR-014)           |
| G-PERM        | Module permission codes for Enquiry/LR (vs platform.\* reuse)                  | **APPROVED** (no blanket grants)            |
| G-NUMBER      | Numbering scope vs Data Visibility settings (Deferred from Phase 2)            | **APPROVED**                                |
| G-CNV-CARD    | Multi-vehicle LR cardinality / allocation                                      | **PENDING**                                 |
| G-AUD-CON-006 | Fine-grained entitlements                                                      | **Pending** — do not invent (Phase 3 EX-01) |
| G-DEPS        | New packages                                                                   | **Case-by-case PO-P1-DEPS**                 |
| G-CI          | Migrate + Pest + Pint + Larastan + FE check/types/build                        | Required after auth                         |

**Full-stack delivery (Batches C+):** Design approval for the batch’s product surfaces must precede implementation. Backend and frontend ship together. Batch A remains a documented infrastructure exception (no product UI). Batch B backend shipped under explicit backend-only authorization; Enquiry product UI waits on **PO-P4-DESIGN-FORMAL**.

---

## 3. Functional workstreams

### P4-WS-1 — Enquiry Management

Manual Branch Panel create; website webhook → company unassigned queue; goods rows; exactly one vehicle type + quantity; statuses; documents; audit.

### P4-WS-2 — Assignment and Follow-ups

Assign/reassign branch and authorized user with history; follow-up dates/remarks; search/filter; tenant-aware authz.

### P4-WS-3 — Quotation Management

Select transporters; broadcast **request** history (Phase 9 delivery); manual quotation entry; uniqueness; edit+remark; compare; select preferred; selection history. Own Vehicle skips quotation.

### P4-WS-4 — Whole-Enquiry Conversion

All requested vehicles arranged; idempotent conversion; duplicate prevention; concurrent-safe; create approved Unified LR records; preserve enquiry/quotation history. No Trip/Lorry Hire/Partload ops.

### P4-WS-5 — Unified LR

One foundation; context-aware From/To and party rules; goods/freight/EWBs/charges; auto/manual numbering; documents; edit until delivery; cancel/soft-delete with dependency hooks for future Trip/LH.

---

## 4. Implementation batches (vertical slices)

| Batch | Scope                            | Primary WS | Backend                                                               | Frontend                                                              | Tests                                              | Blocked by                         |
| ----- | -------------------------------- | ---------- | --------------------------------------------------------------------- | --------------------------------------------------------------------- | -------------------------------------------------- | ---------------------------------- |
| **A** | Domain foundations and schema    | WS-1/5     | Models, migrations, enums, factories, sequence tables, policies stubs | None (infrastructure exception)                                       | Schema + factory + policy unit                     | PO-P4-AUTH                         |
| **B** | Website webhook + manual enquiry | WS-1       | Webhook + EnquiryService + manual JSON API (**COMPLETE**)             | Product UI **blocked** until `PO-P4-DESIGN-FORMAL`                    | Webhook + enquiry Pest (**COMPLETE**)              | A; design formal for UI            |
| **C** | Assignment and follow-ups        | WS-2       | Assignment/follow-up services + history                               | **Same batch:** detail tabs, filters, history (after design approval) | Reassignment + tenant + UI integration             | B; design for C surfaces           |
| **D** | Quotation management             | WS-3       | Quotation/broadcast-request services                                  | **Same batch:** entry/compare/select/broadcast request UI             | Uniqueness + selection + UI                        | C; G-BROADCAST; design for D       |
| **E** | Unified LR + numbering           | WS-5       | LR aggregate, freight calc, EWB, sequences                            | **Same batch:** LR list/form/detail/numbering/print                   | Freight + numbering + UI                           | A; G-NUMBER; design for E          |
| **F** | Enquiry→LR conversion            | WS-4       | ConversionService transactional + idempotency                         | **Same batch:** conversion preview/confirm                            | Duplicate + concurrent + UI; **needs cardinality** | D + E; G-CONVERT; CNV-CARDINALITY  |
| **G** | Integration QA & certification   | All        | Hardening only                                                        | Polish / a11y / responsive — **not first UI for C–F**                 | Browser/a11y + Phase 4 cert report                 | F; design formal already satisfied |

### Per-batch completion criteria (common)

1. Requirements mapped in Phase 4 traceability
2. Tenant + permission negatives
3. Audit remarks where Rules §4 require
4. Soft-delete / no restore
5. Pest coverage per `PHASE_4_TEST_STRATEGY.md`
6. No Phase 5–7 leakage
7. CI-equivalent gates green

---

## 4.1 Batch A — Domain foundations and schema

| Item         | Detail                                                                                         |
| ------------ | ---------------------------------------------------------------------------------------------- |
| Requirements | TB-ENQ/LR structural IDs; enums; factories                                                     |
| Dependencies | **PO-P4-AUTH**; G-PERM draft codes acceptable as stubs                                         |
| Backend      | Migrations; models; enums; factories; sequence tables; policy stubs; `LrDependencyGuard` no-op |
| Frontend     | None (or empty route shells only if needed for Wayfinder — prefer none)                        |
| Tests        | Migration/factory smoke; unique constraints; soft-delete columns                               |
| Security     | Tenant FKs; no public IDs leaking; partial unique indexes                                      |
| Completion   | Schema migrates clean on PostgreSQL; Larastan clean for new types                              |
| Approvals    | PO-P4-AUTH only                                                                                |

## 4.2 Batch B — Website webhook and manual enquiry

| Item         | Detail                                                                                                                                  |
| ------------ | --------------------------------------------------------------------------------------------------------------------------------------- |
| Requirements | TB-ENQ-001…007, 012–014; webhook TB-ENQ-002/003                                                                                         |
| Dependencies | Batch A; **G-WEBHOOK** decision freeze                                                                                                  |
| Backend      | Webhook controller; token resolution; idempotency store; `EnquiryService` create/update; goods rows; documents morph; enquiry numbering |
| Frontend     | Enquiry List, Unassigned Queue, Create/Edit (if G-DESIGN-P4 allows; else API/Pest-only + defer UI to G)                                 |
| Tests        | TEST-ENQ-_; TEST-WH-_; TEST-TEN-001/003                                                                                                 |
| Security     | Token auth; rate limit; replay; never trust client company_id; private files                                                            |
| Completion   | Manual + webhook create unassigned/assigned correctly; negatives green                                                                  |
| Approvals    | G-WEBHOOK; G-PERM for policy wiring                                                                                                     |

## 4.3 Batch C — Assignment and follow-ups (**full-stack**)

| Item         | Detail                                                                                          |
| ------------ | ----------------------------------------------------------------------------------------------- |
| Requirements | TB-ENQ-008…011                                                                                  |
| Dependencies | Batch B; **design approval for C surfaces** before code                                         |
| Backend      | AssignmentService; FollowUpService; status history; search filters                              |
| Frontend     | **Same batch:** detail tabs (follow-ups, assignment history); list filters; permission-aware UI |
| Tests        | TEST-ASN-*; reassignment history; tenant negatives; UI integration                              |
| Security     | Branch membership checks; remark mandatory on reassign                                          |
| Completion   | Website enquiry assignable in UI; history immutable                                             |
| Approvals    | Batch auth; design for C                                                                        |

## 4.4 Batch D — Quotation management (**full-stack**)

| Item         | Detail                                                                                             |
| ------------ | -------------------------------------------------------------------------------------------------- |
| Requirements | TB-QT-001…009                                                                                      |
| Dependencies | Batch C; **G-BROADCAST**; design approval for D surfaces                                           |
| Backend      | QuotationService; uniqueness; selection history; BroadcastRequestService → outbox only             |
| Frontend     | **Same batch:** quotation entry, comparison, selection; broadcast request UI with Queued messaging |
| Tests        | TEST-QT-*; no provider HTTP; UI integration                                                        |
| Security     | Transporter party must be company-scoped; audit on edit                                            |
| Completion   | Duplicate quote blocked; preferred selection history correct; Own Vehicle skip; UI wired           |
| Approvals    | G-BROADCAST; design for D                                                                          |

## 4.5 Batch E — Unified LR and numbering (**full-stack**)

| Item         | Detail                                                                                                                                   |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Requirements | TB-LR-001…015                                                                                                                            |
| Dependencies | Batch A; **G-NUMBER**; design approval for E surfaces                                                                                    |
| Backend      | LorryReceiptService; context validators; freight calculator; EWB/charges; numbering + concurrency; cancel/soft-delete + dependency guard |
| Frontend     | **Same batch:** LR List/Form/Detail/Numbering/print preview where approved                                                               |
| Tests        | TEST-LR-001…016; concurrency allocation; UI integration                                                                                  |
| Security     | Tenant isolation; private docs; sequence locks                                                                                           |
| Completion   | All three contexts validate; concurrent numbers unique; delivered locks; UI wired                                                        |
| Approvals    | G-NUMBER; design for E                                                                                                                   |
| Note         | Prefer active-only unique indexes for soft-deleted goods positions (see enquiry goods DEC-003 fix)                                       |

## 4.6 Batch F — Enquiry-to-LR conversion (**full-stack**)

| Item         | Detail                                                                                       |
| ------------ | -------------------------------------------------------------------------------------------- |
| Requirements | TB-CNV-001…006                                                                               |
| Dependencies | Batches D + E; **G-CONVERT**; **PO-P4-CNV-CARDINALITY**; design for F                        |
| Backend      | EnquiryConversionService; transactional lock; idempotency; outbox EnquiryConverted/LRCreated |
| Frontend     | **Same batch:** conversion preview, confirm, outcome presentation                            |
| Tests        | TEST-CNV-*; assert no Trip/LH/Challan; UI integration                                        |
| Security     | Convert permission; company match; concurrent safety                                         |
| Completion   | Whole-enquiry only; duplicate/concurrent safe; history preserved; UI wired                   |
| Approvals    | G-CONVERT; PO-P4-CNV-CARDINALITY; design for F                                               |

## 4.7 Batch G — Integration QA and certification (**not first UI**)

| Item         | Detail                                                                                  |
| ------------ | --------------------------------------------------------------------------------------- |
| Requirements | Design §5–6 print/a11y; exit criteria evidence                                          |
| Dependencies | Batch F; product surfaces already implemented under prior design approvals              |
| Backend      | Hardening; print data presenters                                                        |
| Frontend     | Polish, responsive/a11y pass, print preview refinements — **not** deferred C–F first UI |
| Tests        | TEST-UI-*; browser smoke when tooling available; Phase 4 certification report           |
| Security     | Final tenant/permission sweep                                                           |
| Completion   | Cert report READY FOR PO ACCEPTANCE (not auto-accepted)                                 |
| Approvals    | Formal Phase 4 acceptance is a separate PO decision                                     |

---

## 5. Dependency graph

```text
PO-P4-AUTH ──► Batch A (schema/domain; no product UI)
                 ├─► Batch B (enquiry + webhook backend; UI gated on DESIGN-FORMAL)
                 │     └─► Batch C (assignment/follow-ups — backend + UI same batch)
                 │           └─► Batch D (quotations — backend + UI same batch)
                 ├─► Batch E (unified LR + numbering — backend + UI same batch)
                 └─► Batch F (conversion — backend + UI; needs CNV-CARDINALITY)
                       └─► Batch G (integration QA + certification polish)
```

---

## 6. Security requirements (all batches)

- Company + branch isolation on every path
- Never trust client `company_id` / `branch_id`
- Webhook: token auth, rate limit, replay protection, idempotency key
- Confirmed subscription entitlements only
- Private document access
- Concurrent numbering locks / unique constraints
- Conversion idempotency + row locks
- No hard delete

---

## 7. Required approvals before/during delivery

| Approval                                 | When                                                                                                       |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| **PO-P4-AUTH**                           | Before Batch A code/migrations                                                                             |
| Webhook contract (G-WEBHOOK)             | Before Batch B webhook go-live contract freeze                                                             |
| Broadcast boundary (G-BROADCAST)         | Before Batch D broadcast enqueue                                                                           |
| Permission catalogue (G-PERM)            | Before Batch B policies finalize                                                                           |
| Numbering scope (G-NUMBER)               | Before Batch E sequence design freeze                                                                      |
| **PO-P4-DESIGN-FORMAL** (or per-surface) | Before implementing that surface’s product UI (Batch B Enquiry UI package ready; before Batches C–F start) |
| Full-stack delivery standard             | Batches C–F: backend + UI + tests same batch; Batch G = QA polish only                                     |
| PO-P1-DEPS                               | Only if new packages proposed                                                                              |

---

## 8. Authorization statement

**PO-P4-AUTH APPROVED** (23 November 2026). See `PHASE_4_AUTHORIZATION_RECORD.md`. Batch A is the immediate implementation scope. Product UI remains gated by **PO-P4-DESIGN-FORMAL** (Pending). Conversion cardinality remains gated by **PO-P4-CNV-CARDINALITY** (Pending).
