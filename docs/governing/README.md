# TruckBill Governing Specification Package

**Status: Approved by Product Owner — Phase 0 governing baseline.**  
**Approval date:** 21 November 2026  
**Approval scope:** Phase 0 governing baseline (six governing documents + supporting Phase 0 engineering package).  
**Approval decisions:** PO-APPROVE-001 (Approved); PO-EXCEPT-001 (Accepted); PO-HIST-001 (Accepted).  
**Phase 0:** Formally approved and **closed**.  
**Phase 1:** **PO-P1-AUTH APPROVED** (21 November 2026) — see `docs/engineering/PHASE_1_AUTHORIZATION_RECORD.md`.

Approval is **subject to** accepted Pending, Deferred and Proposed exceptions (PO-EXCEPT-001). Those items retain their existing statuses and must not be silently reclassified as Confirmed/Accepted.

## Six governing documents

1. PRD.md — **Approved** (21 November 2026)
2. Architecture.md — **Approved** (21 November 2026)
3. Rules.md — **Approved** (21 November 2026)
4. Design.md — **Approved** (21 November 2026)
5. Phases.md — **Approved** (21 November 2026)
6. Memory.md — **Approved** (21 November 2026)

## Supporting control artefacts

- Requirements-Traceability-Starter.md (expanded Phase 0 register; Pending/Deferred/Excluded statuses preserved)
- `docs/engineering/FOUNDATION_AUDIT.md`
- `docs/engineering/PHASE_0_FINALIZATION_REPORT.md` (historical; 75-row stats superseded)
- `docs/engineering/PHASE_0_FINAL_CONSISTENCY_REPORT.md`
- `docs/engineering/PHASE_0_APPROVAL_READINESS_REPORT.md` (records formal approval)
- `docs/architecture-decisions/` (ADR-001–ADR-012; ADR-001/002/003/007/008/009 Accepted under PO-P1-ADR-*; **ADR-006 Accepted** web-only under PO-P2-ADR-006; ADR-010 remains Pending)
- `docs/engineering/PHASE_1_AUTHORIZATION_RECORD.md`
- `docs/engineering/PHASE_2_AUTHORIZATION_RECORD.md` (PO-P2-AUTH APPROVED)
- `docs/implementation/PHASE_1_EXECUTION_PLAN.md` (Approved — Phase 1 Implementation Authorized)
- `docs/implementation/PHASE_2_EXECUTION_PLAN.md` (Authorized — Phase 2 Implementation)
- `docs/testing/TEST_STRATEGY.md`
- `docs/implementation/PHASE_0_DELIVERABLES.md` (artefacts remain Not Started; see also `PHASE_2_DESIGN_DELIVERABLES.md`)

## Source authority

- Functional/business requirements are based on the decisions consolidated from the TruckBill rebuild discussions and the existing expanded PRD/Rules draft.
- Visual brand requirements are based on the TruckBill Design Guideline (PDF); repository presence of the PDF remains Pending (AUD-GOV-004).
- Older architecture files and old subscription proposals are historical/non-authoritative where superseded by later confirmed decisions.
- The pricing workbook is an initial catalogue source; commercial values remain configurable and versioned.
- Delivery phase numbering authority: **`Phases.md` only** (DEC-001 / ADR-011).
- **PO-HIST-001:** Historical discussion residual risk accepted; no additional sampling review required.

## Important scope and decision safeguards

- Laravel 13, React + Inertia, PostgreSQL, modular monolith.
- Integrated double-entry accounting is in scope.
- No base/workspace fee; one company billing account and common renewal date.
- Initial manual Razorpay renewals are delivered in **Phase 3** (not Phase 1). Phase 1 is engineering foundation only.
- **Partload Expense Management is explicitly excluded** from Partload scope (DEC-005). OwnFleet expense management remains in scope.
- Reports are deferred until source workflows are successfully implemented.
- Legacy applications are migration sources only after the new platform is ready.
- **DEC-004:** Phase 0 approval prerequisite is **satisfied**. Implementation must follow `Phases.md` sequencing. **PO-P1-AUTH APPROVED** (21 November 2026) authorizes Phase 1 Engineering Foundation under the Phase 1 Execution Plan. Phase 2+ remains unauthorized until separately approved.

## Accepted exceptions (PO-EXCEPT-001)

Outstanding Pending, Deferred and Proposed items remain open for their designated phases or gates. Do not invent resolutions. External validations (accounting maps, GST/TDS) remain required before production use of those rules.

## Document status vocabulary

- **Draft** — content exists; not formally approved
- **Pending Review** — prepared for product-owner review
- **Pending Decision** — requires further user decision
- **Resolved by User Decision** — incorporated from DEC-001–DEC-009
- **Ready for Formal Approval** — documentation package complete enough to request approval
- **Approved** — Product Owner formal approval recorded (Phase 0 governing baseline, 21 November 2026)
