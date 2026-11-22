# Phase 4 Authorization Request — PO-P4-AUTH

**Request date:** 22 November 2026  
**Requested decision:** **PO-P4-AUTH** — Authorize Phase 4 Enquiry Management & Unified LR implementation  
**Document status:** Request only — **do not treat as Approved**

---

## 1. Preconditions (satisfied)

| Item                                                 | Evidence                                                                      |
| ---------------------------------------------------- | ----------------------------------------------------------------------------- |
| Phase 0 approved                                     | PO-APPROVE-001                                                                |
| Phase 1–2 closed / accepted                          | Authorization + acceptance records                                            |
| Phase 3 formally accepted WITH DOCUMENTED EXCEPTIONS | `PHASE_3_ACCEPTANCE_RECORD.md`; baseline `68d11b1…`; Actions #60              |
| Phase 3 exceptions preserved                         | EX-01…EX-07 unchanged                                                         |
| Kickoff tip verified                                 | `193e6dc68b2509e90de4aa2990b1136260e80746` = `origin/main`; CI SUCCESS on tip |
| Phase 4 kickoff package prepared                     | Listed in §3                                                                  |

---

## 2. What authorization would allow

Under `docs/implementation/PHASE_4_EXECUTION_PLAN.md`, **PO-P4-AUTH** would authorize:

1. Database migrations and domain code for Enquiry + Unified LR (Batches A–F)
2. Website webhook endpoint per approved **PO-P4-WEBHOOK** contract
3. Quotation management with broadcast **request** boundary per **PO-P4-BROADCAST**
4. Whole-enquiry conversion to Unified LR only per **PO-P4-CONVERT**
5. Pest coverage per `PHASE_4_TEST_STRATEGY.md`
6. Documentation / certification updates as batches complete

**Still separately gated:**

| Gate                                 | Required for                                        |
| ------------------------------------ | --------------------------------------------------- |
| PO-P4-DESIGN-FORMAL (or per-surface) | Production Inertia product UI (Batch G / UI slices) |
| PO-P4-WEBHOOK decision               | Freezing webhook contract                           |
| PO-P4-PERM                           | Permission seeder finalize                          |
| PO-P4-NUMBER                         | Numbering scope freeze                              |
| PO-P1-DEPS                           | Any new Composer/NPM packages                       |
| PO-P3-PAY-PROD / production deploy   | Unrelated — remain Pending                          |

---

## 3. Package artefacts for review

1. `PHASE_4_EXECUTION_PLAN.md`
2. `PHASE_4_REQUIREMENTS_TRACEABILITY.md`
3. `PHASE_4_ARCHITECTURE_DESIGN.md`
4. `PHASE_4_DESIGN_AND_UI_SPECIFICATION.md` (draft — not approved)
5. `PHASE_4_TEST_STRATEGY.md`
6. `PHASE_4_PRODUCT_OWNER_DECISION_PACKAGE.md`
7. This request

---

## 4. Explicit exclusions (remain unauthorized even if PO-P4-AUTH granted)

- Phase 5 OwnFleet Trip operational workflows
- Phase 6 Broker Lorry Hire / vouchers / Memo
- Phase 7 Partload Challan / Inward / DR
- Phase 9 provider messaging adapters (unless PO-P4-BROADCAST pulls them — not recommended)
- Accounting GL postings (ADR-010)
- Sanctum / mobile token auth (ADR-006)
- Inventing AUD-CON-006 cells (EX-01)
- Production Razorpay charges / production deployment
- Push to remote / sibling `website/` code changes by this authorization
- Treating draft UI spec as design-approved

---

## 5. Recommended Product Owner response format

```text
PO-P4-AUTH: APPROVED | DEFERRED | REJECTED
Date:
Baseline tip acknowledged: 193e6dc…
Execution plan: docs/implementation/PHASE_4_EXECUTION_PLAN.md
Accompanying decisions (optional same message):
  PO-P4-WEBHOOK: …
  PO-P4-BROADCAST: Option A (recommended) | …
  PO-P4-CONVERT: Option A (recommended) | …
  PO-P4-PERM: …
  PO-P4-NUMBER: …
  PO-P4-CNV-CARDINALITY: N LRs (recommended) | …
  PO-P4-DESIGN-FORMAL: APPROVED | DEFERRED | …
```

Record approvals in Memory + a future `PHASE_4_AUTHORIZATION_RECORD.md` **only after** explicit PO text.

---

## 6. Engineering commitment on approval

- Vertical-slice Batches A→G per plan
- No Phase 3 behaviour changes except necessary shared infrastructure hooks
- Local commits; **no push** unless PO separately instructs (standing no-push policy remains)
- No production payments or deploys

---

## 7. Current formal status

**PO-P4-AUTH: NOT APPROVED**

Phase 4 kickoff package is ready for Product Owner review. Application implementation must not begin until explicit authorization is recorded.
