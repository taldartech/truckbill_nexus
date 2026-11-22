# Phase 4 Execution Plan — Enquiry Management & Unified LR

**Status:** **PO-P4-AUTH APPROVED** (23 November 2026) — backend/domain authorized; **Batch A COMPLETE** — ready for Batch B review (not auto-started)  
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

- Backend Batches A–F authorized subject to remaining gates; **do not auto-start Batch B** after A.
- Do **not** implement product UI until **PO-P4-DESIGN-FORMAL** (Pending).
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

**Backend-before-UI rule:** After **PO-P4-AUTH**, schema/domain/Pest may proceed without product UI. Product screens additionally require **G-DESIGN-P4** for that surface.

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

| Batch | Scope                             | Primary WS | Backend                                                               | Frontend                                    | Tests                                 | Blocked by            |
| ----- | --------------------------------- | ---------- | --------------------------------------------------------------------- | ------------------------------------------- | ------------------------------------- | --------------------- |
| **A** | Domain foundations and schema     | WS-1/5     | Models, migrations, enums, factories, sequence tables, policies stubs | None / shells only                          | Schema + factory + policy unit        | PO-P4-AUTH            |
| **B** | Website webhook + manual enquiry  | WS-1       | Webhook controller, EnquiryService, goods rows, docs                  | Create/Edit Enquiry, List, Unassigned Queue | Webhook security + enquiry validation | A; G-WEBHOOK decision |
| **C** | Assignment and follow-ups         | WS-2       | Assignment/follow-up services + history                               | Detail tabs, filters                        | Reassignment + tenant negatives       | B                     |
| **D** | Quotation management              | WS-3       | Quotation/broadcast-request services                                  | Quotation entry/compare/select              | Uniqueness + selection history        | C; G-BROADCAST        |
| **E** | Unified LR + numbering            | WS-5       | LR aggregate, freight calc, EWB, sequences                            | LR list/form/detail/numbering/print         | Freight + numbering concurrency       | A; G-NUMBER clarity   |
| **F** | Enquiry→LR conversion             | WS-4       | ConversionService transactional + idempotency                         | Conversion preview                          | Duplicate + concurrent conversion     | D + E; G-CONVERT      |
| **G** | UI integration, QA, certification | All        | Hardening                                                             | Full screen polish                          | Browser/a11y + Phase 4 cert report    | F; G-DESIGN-P4        |

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

## 4.3 Batch C — Assignment and follow-ups

| Item         | Detail                                                             |
| ------------ | ------------------------------------------------------------------ |
| Requirements | TB-ENQ-008…011                                                     |
| Dependencies | Batch B                                                            |
| Backend      | AssignmentService; FollowUpService; status history; search filters |
| Frontend     | Detail tabs: follow-ups, assignment history; filters on list       |
| Tests        | TEST-ASN-*; reassignment history; tenant negatives                 |
| Security     | Branch membership checks; remark mandatory on reassign             |
| Completion   | Website enquiry assignable; history immutable                      |
| Approvals    | None beyond AUTH                                                   |

## 4.4 Batch D — Quotation management

| Item         | Detail                                                                                 |
| ------------ | -------------------------------------------------------------------------------------- |
| Requirements | TB-QT-001…009                                                                          |
| Dependencies | Batch C; **G-BROADCAST**                                                               |
| Backend      | QuotationService; uniqueness; selection history; BroadcastRequestService → outbox only |
| Frontend     | Quotation entry, comparison, selection; broadcast request UI with Queued messaging     |
| Tests        | TEST-QT-*; no provider HTTP                                                            |
| Security     | Transporter party must be company-scoped; audit on edit                                |
| Completion   | Duplicate quote blocked; preferred selection history correct; Own Vehicle skip         |
| Approvals    | G-BROADCAST                                                                            |

## 4.5 Batch E — Unified LR and numbering

| Item         | Detail                                                                                                                                   |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Requirements | TB-LR-001…015                                                                                                                            |
| Dependencies | Batch A; **G-NUMBER** clarity                                                                                                            |
| Backend      | LorryReceiptService; context validators; freight calculator; EWB/charges; numbering + concurrency; cancel/soft-delete + dependency guard |
| Frontend     | LR List/Form/Detail/Numbering (design-gated)                                                                                             |
| Tests        | TEST-LR-001…016; concurrency allocation                                                                                                  |
| Security     | Tenant isolation; private docs; sequence locks                                                                                           |
| Completion   | All three contexts validate; concurrent numbers unique; delivered locks                                                                  |
| Approvals    | G-NUMBER                                                                                                                                 |

## 4.6 Batch F — Enquiry-to-LR conversion

| Item         | Detail                                                                                       |
| ------------ | -------------------------------------------------------------------------------------------- |
| Requirements | TB-CNV-001…006                                                                               |
| Dependencies | Batches D + E; **G-CONVERT**; cardinality decision                                           |
| Backend      | EnquiryConversionService; transactional lock; idempotency; outbox EnquiryConverted/LRCreated |
| Frontend     | Conversion Preview                                                                           |
| Tests        | TEST-CNV-*; assert no Trip/LH/Challan                                                        |
| Security     | Convert permission; company match; concurrent safety                                         |
| Completion   | Whole-enquiry only; duplicate/concurrent safe; history preserved                             |
| Approvals    | G-CONVERT; PO-P4-CNV-CARDINALITY                                                             |

## 4.7 Batch G — UI integration, QA and certification

| Item         | Detail                                                  |
| ------------ | ------------------------------------------------------- |
| Requirements | Design §5–6 print/a11y; exit criteria evidence          |
| Dependencies | Batch F; **G-DESIGN-P4**                                |
| Backend      | Hardening; print data presenters                        |
| Frontend     | Full screen polish; print preview; responsive/a11y      |
| Tests        | TEST-UI-*; browser smoke; Phase 4 certification report  |
| Security     | Final tenant/permission sweep                           |
| Completion   | Cert report READY FOR PO ACCEPTANCE (not auto-accepted) |
| Approvals    | PO-P4-DESIGN-FORMAL                                     |

---

## 5. Dependency graph

```text
PO-P4-AUTH ──► Batch A (schema/domain)
                 ├─► Batch B (enquiry + webhook)
                 │     └─► Batch C (assignment/follow-ups)
                 │           └─► Batch D (quotations)
                 ├─► Batch E (unified LR + numbering)  [parallel after A]
                 └─► Batch F (conversion) ← needs D + E
                       └─► Batch G (UI QA + certification) ← needs G-DESIGN-P4
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

| Approval                                 | When                                                     |
| ---------------------------------------- | -------------------------------------------------------- |
| **PO-P4-AUTH**                           | Before Batch A code/migrations                           |
| Webhook contract (G-WEBHOOK)             | Before Batch B webhook go-live contract freeze           |
| Broadcast boundary (G-BROADCAST)         | Before Batch D broadcast enqueue                         |
| Permission catalogue (G-PERM)            | Before Batch B policies finalize                         |
| Numbering scope (G-NUMBER)               | Before Batch E sequence design freeze                    |
| **PO-P4-DESIGN-FORMAL** (or per-surface) | Before Batch G product UI / earlier if UI-first surfaces |
| PO-P1-DEPS                               | Only if new packages proposed                            |

---

## 8. Authorization statement

**PO-P4-AUTH APPROVED** (23 November 2026). See `PHASE_4_AUTHORIZATION_RECORD.md`. Batch A is the immediate implementation scope. Product UI remains gated by **PO-P4-DESIGN-FORMAL** (Pending). Conversion cardinality remains gated by **PO-P4-CNV-CARDINALITY** (Pending).
