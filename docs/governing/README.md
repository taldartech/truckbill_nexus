# TruckBill Governing Specification Package

**Status: Draft — Pending Review / Ready for Formal Approval preparation.**  
**Not formally approved for implementation.** Phase 0 documentation finalization incorporates user decisions DEC-001–DEC-009; formal approval remains with the product owner.

## Six governing documents
1. PRD.md
2. Architecture.md
3. Rules.md
4. Design.md
5. Phases.md
6. Memory.md

## Supporting control artefacts
- Requirements-Traceability-Starter.md (expanded Phase 0 register)
- `docs/engineering/FOUNDATION_AUDIT.md`
- `docs/engineering/PHASE_0_FINALIZATION_REPORT.md`
- `docs/architecture-decisions/` (ADR-001–ADR-012)
- `docs/testing/TEST_STRATEGY.md`
- `docs/implementation/PHASE_0_DELIVERABLES.md`

## Source authority
- Functional/business requirements are based on the decisions consolidated from the TruckBill rebuild discussions and the existing expanded PRD/Rules draft.
- Visual brand requirements are based on the TruckBill Design Guideline (PDF); repository presence of the PDF is Pending (AUD-GOV-004).
- Older architecture files and old subscription proposals are historical/non-authoritative where superseded by later confirmed decisions.
- The pricing workbook is an initial catalogue source; commercial values remain configurable and versioned.
- Delivery phase numbering authority: **`Phases.md` only** (DEC-001 / ADR-011).

## Important scope and decision safeguards
- Laravel 13, React + Inertia, PostgreSQL, modular monolith.
- Integrated double-entry accounting is in scope.
- No base/workspace fee; one company billing account and common renewal date.
- Initial manual Razorpay renewals are delivered in **Phase 3** (not Phase 1). Phase 1 is engineering foundation only.
- **Partload Expense Management is explicitly excluded** from Partload scope (DEC-005). OwnFleet expense management remains in scope.
- Reports are deferred until source workflows are successfully implemented.
- Legacy applications are migration sources only after the new platform is ready.
- **No application coding** (including engineering foundation implementation) until Phase 0 is formally completed and approved (DEC-004).

## Review caveat
This is a materially expanded specification package prepared for formal approval. It must still pass product-owner formal approval and remaining Pending items / external validations. Any genuinely undecided point remains labelled Pending; do not silently invent a business rule.

## Document status vocabulary
- **Draft** — content exists; not formally approved
- **Pending Review** — prepared for product-owner review
- **Pending Decision** — requires further user decision
- **Resolved by User Decision** — incorporated from DEC-001–DEC-009
- **Ready for Formal Approval** — documentation package complete enough to request approval
- **Approved** — reserved for product-owner formal approval only (not claimed by this package)
