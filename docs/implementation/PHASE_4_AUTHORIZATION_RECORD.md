# TruckBill — Phase 4 Authorization Record

**Date:** 23 November 2026  
**Repository:** `/Users/ttc/Sites/truckbill/nexus`  
**Decision authority:** Product Owner  
**Governing plan:** `docs/implementation/PHASE_4_EXECUTION_PLAN.md`  
**Accepted kickoff baseline tip:** `2c3f351444ac7e80ffd0a723475c4b5e8a3234f5` (Actions #62 SUCCESS)  
**Phase 3 acceptance:** PO-P3-FINAL-ACCEPT (baseline `68d11b1…`; EX-01…EX-07 preserved)

> **FORMAL PHASE 4 AUTHORIZATION RECORDED (23 November 2026)**
>
> - **PO-P4-AUTH:** **APPROVED** — Phase 4 backend/domain implementation authorized under `PHASE_4_EXECUTION_PLAN.md`.
> - Immediate implementation scope: **Batch A only** (do not auto-start Batch B).
> - **PO-P4-DESIGN-FORMAL:** remains **Pending** — no product UI.
> - **PO-P4-CNV-CARDINALITY:** remains **Pending** — no conversion cardinality assumptions.
> - Phase 3 exceptions EX-01…EX-07 unchanged.

---

## 1. Decision register

| Decision ID               | Topic                                                           | Status                  | Date             |
| ------------------------- | --------------------------------------------------------------- | ----------------------- | ---------------- |
| **PO-P4-AUTH**            | Authorize Phase 4 backend/domain under execution plan           | **APPROVED**            | 23 November 2026 |
| **PO-P4-BROADCAST**       | Quotation broadcast = request enqueue + history only (Option A) | **APPROVED — Option A** | 23 November 2026 |
| **PO-P4-CONVERT**         | Conversion creates Unified LR only (Option A)                   | **APPROVED — Option A** | 23 November 2026 |
| **PO-P4-PERM**            | Enquiry/quotation/LR permission catalogue                       | **APPROVED**            | 23 November 2026 |
| **PO-P4-NUMBER**          | Enquiry company-wide; LR Shared/Restricted scope rules          | **APPROVED**            | 23 November 2026 |
| **PO-P4-WEBHOOK**         | Website enquiry webhook V1 principles                           | **APPROVED — V1**       | 23 November 2026 |
| **PO-P4-CNV-CARDINALITY** | LR count / allocation for multi-vehicle enquiries               | **PENDING**             | —                |
| **PO-P4-DESIGN-FORMAL**   | Formal product UI design approval                               | **PENDING**             | —                |

---

## 2. Approved scope (PO-P4-AUTH)

Permits:

- Enquiry and Unified LR domain implementation
- PostgreSQL migrations
- Domain models, services, policies
- Approved permission catalogue extensions (register codes; **no** blanket role grants)
- Backend HTTP endpoints in **subsequent** batches subject to their gates
- Unit, feature, integration tests
- Audit, outbox, idempotency infrastructure
- Backend Batches A–F subject to remaining per-batch gates

Immediate authorized batch: **Batch A — Domain foundations and schema**.

---

## 3. Accompanying approved decisions (summary)

### PO-P4-BROADCAST — Option A

Select transporters; persist broadcast requests/history; enqueue for Phase 9. **No** WhatsApp/SMS/Email provider send or delivery claims.

### PO-P4-CONVERT — Option A

Whole-enquiry conversion creates **Unified LR records only**. No Trip, Lorry Hire, Partload Challan/Inward/DR, or accounting postings.

### PO-P4-PERM

Codes approved: `enquiry.*`, `quotation.*`, `lr.*` as listed in the Product Owner decision. Branch-specific RBAC; Delete and `lr.number.adjust` remain explicit/restricted; no blanket grants to existing roles.

### PO-P4-NUMBER

- Enquiry: company-wide unique active numbers
- LR: Shared Data Visibility → company-wide; Restricted → branch-specific
- Auto/manual; manual alphanumeric; active duplicate prevention; sequence adjust needs auth + remark + audit; concurrency-safe

### PO-P4-WEBHOOK — V1

Company-scoped hashed bearer tokens; create/rotate/revoke; idempotent processing; **durable** company-scoped external reference deduplication; no multipart uploads; rate limit + replay protection; tenant from token only. Public webhook **endpoint not in Batch A**. Operational parameters not explicitly approved (e.g. token overlap duration, exact retry limits) must not be misrepresented as PO policy.

---

## 4. Explicit boundaries / prohibited

- Phase 4 product UI / Inertia screens / nav (until PO-P4-DESIGN-FORMAL)
- Public website webhook route in Batch A
- Provider messaging delivery
- Conversion execution that assumes LR cardinality
- OwnFleet Trip / Broker Lorry Hire / Partload ops
- Accounting GL (ADR-010 Pending)
- Sanctum/mobile (ADR-006)
- Inventing AUD-CON-006 cells (EX-01)
- Production Razorpay / deploy / push
- Unapproved Composer/NPM packages

---

## 5. Remaining gates

| Gate                                        | Status                    | Blocks                                                |
| ------------------------------------------- | ------------------------- | ----------------------------------------------------- |
| PO-P4-CNV-CARDINALITY                       | Pending                   | Conversion behaviour depending on LR count/allocation |
| PO-P4-DESIGN-FORMAL                         | Pending                   | Product UI                                            |
| Batch B G-WEBHOOK operational detail freeze | Open for technical params | Public webhook go-live contract details               |
| Phase 3 EX-01…EX-07                         | Unchanged                 | As recorded                                           |

---

## 6. Related artefacts

- Request: `PHASE_4_AUTHORIZATION_REQUEST.md`
- Decision package: `PHASE_4_PRODUCT_OWNER_DECISION_PACKAGE.md`
- Execution plan: `PHASE_4_EXECUTION_PLAN.md`
- Cardinality proposal: `PHASE_4_CONVERSION_CARDINALITY_DECISION_PROPOSAL.md`
- Batch A certification: `PHASE_4_BATCH_A_CERTIFICATION_REPORT.md` (after implementation)

---

## 7. Batch B authorization (23 November 2026)

| Decision ID            | Status       | Scope                                                                                                                                                                                    |
| ---------------------- | ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **PO-P4-BATCH-B-AUTH** | **APPROVED** | Phase 4 Batch B backend: website webhook V1, manual enquiry create/edit, goods persistence, numbering, durable dedup, idempotency, replay, rate limit, audit/outbox, Pest, certification |

**Does not authorize:** product UI, Batch C+, quotations, conversion, LR ops, Phase 5–7, live payments, push/deploy.

Remaining Pending unchanged: PO-P4-CNV-CARDINALITY, PO-P4-DESIGN-FORMAL, Phase 3 EX-01…EX-07.
