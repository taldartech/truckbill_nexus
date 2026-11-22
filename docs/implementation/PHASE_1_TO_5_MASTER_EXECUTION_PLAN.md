# Phase 1–5 Master Execution Plan

**Date:** 23 November 2026  
**Baseline:** `06ead4bd09730741e3c406ce90e128b8aeccbae7`  
**Companions:** `PHASE_1_TO_5_REQUIREMENTS_GAP_AUDIT.md`, `PHASE_1_TO_5_CONSOLIDATED_PO_DECISIONS.md`  
**Discipline:** Vertical slices · fullstack delivery for user-facing batches · no invented approvals · no push/deploy/live Razorpay

---

## Stage 0 — Requirements reconciliation (THIS DELIVERY)

| Deliverable                                      | Status                      |
| ------------------------------------------------ | --------------------------- |
| Gap audit                                        | Done                        |
| Consolidated PO decisions                        | Done                        |
| Master execution plan                            | Done                        |
| Test / security / performance / final cert stubs | Done                        |
| Playwright/k6 gated READMEs                      | Done                        |
| Implement gated product modules                  | **Blocked** (see decisions) |

**Exit:** Accurate blockers recorded; independent work identified. **Not** project completion.

---

## Stage 1 — Phase 2 completion (gated)

1. PO approves company-creation IDs (D1).
2. Migrations + `CompanyWorkspaceService` expansion + Owner/Internal create/edit UI + Pest + (later) Playwright.
3. PO approves Internal IA/UI (D2) → lifecycle/transfer screens.
4. Do **not** implement membership reactivation while Deferred.

**Already done (do not rebuild):** Owner branch administration (`OwnerBranchAdministrationService` + UI).

---

## Stage 2 — Phase 3 hardening

1. Preserve EX-01…07.
2. Defect-only fixes on masters/docs/subscription.
3. No live payments, no ADR-010, no fake tax-invoice claims.

---

## Stage 3 — Phase 4 completion (gated sequence)

| Step | Gate                            | Work                                                     |
| ---- | ------------------------------- | -------------------------------------------------------- |
| 3a   | PO-P4-DESIGN-FORMAL             | Enquiry product UI design signed                         |
| 3b   | PO-P4-BATCH-C-AUTH              | Assignment + follow-ups full-stack                       |
| 3c   | Batch D auth + design           | Quotations (broadcast = queued request only)             |
| 3d   | Batch E                         | Unified LR + **active unique goods** soft-delete pattern |
| 3e   | PO-P4-CNV-CARDINALITY + Batch F | Conversion                                               |

---

## Stage 4 — Phase 5 OwnFleet (gated)

Requires `PO-P5-AUTH` + `PO-P5-DESIGN-FORMAL`.  
Implement Trip (1 LR = 1 Trip), multi-point, dispatch/completion, expenses/diesel, POD, invoice/payments **without** inventing ADR-010 postings.

---

## Stage 5 — Integrated certification

After Stages 1–4 authorized slices land:

1. Full Pest regression
2. Playwright critical journeys (after D7)
3. k6 smoke/baseline (after D7; stress not every PR)
4. Security review update
5. Final certification with honest % coverage

---

## Dependency graph (simplified)

```
Stage 0
  ├─► D7 deps ──► Playwright/k6 CI
  ├─► D1 company ──► Stage 1 company UI
  ├─► D2 internal ──► Stage 1 Internal UI
  ├─► D3 design ──► D5 Batch C ──► D ──► E ──► D4+F conversion
  └─► D6 Phase 5 ──► OwnFleet (after usable LR/convert)
```

---

## Commit policy

Focused local commits per vertical slice. Absolute **no-push**. No giant rewrite commits.
