# TruckBill Governing Specification Package

**Status: Expanded draft for review — not approved for implementation.**

## Six governing documents
1. PRD.md
2. Architecture.md
3. Rules.md
4. Design.md
5. Phases.md
6. Memory.md

## Supporting control artefact
- Requirements-Traceability-Starter.md

## Source authority
- Functional/business requirements are based on the decisions consolidated from the TruckBill rebuild discussions and the existing expanded PRD/Rules draft.
- Visual brand requirements are based on the attached six-page `TruckBill Design Guideline.pdf`.
- Older architecture files and old subscription proposals are historical/non-authoritative where superseded by later confirmed decisions.
- The pricing workbook is an initial catalogue source; commercial values remain configurable and versioned.

## Important scope and decision safeguards
- Laravel 13, React + Inertia, PostgreSQL, modular monolith.
- Integrated double-entry accounting is in scope.
- No base/workspace fee; one company billing account and common renewal date.
- Phase 1 manual Razorpay renewals only.
- Partload Expense Management is explicitly skipped.
- Reports are deferred until source workflows are successfully implemented.
- Legacy applications are migration sources only after the new platform is ready.
- No application coding until all six governing documents are reviewed and approved.

## Review caveat
This is a materially expanded specification package, but it must still pass a line-by-line requirements traceability review against the complete historical discussion record before approval. Any genuinely undecided point must remain labelled Pending; do not silently invent a business rule.
