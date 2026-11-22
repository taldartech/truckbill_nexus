# ADR-014: Enquiry Conversion Creates Unified LR Only

| Field           | Value                                      |
| --------------- | ------------------------------------------ |
| ADR ID          | ADR-014                                    |
| Title           | Enquiry Conversion Creates Unified LR Only |
| Status          | Accepted                                   |
| Date            | 2026-11-23                                 |
| Accepted by     | Product Owner (PO-P4-CONVERT Option A)     |
| Acceptance date | 23 November 2026                           |

## Context

Whole-enquiry conversion (Rules §7) must create operational records. OwnFleet Trip, Broker Lorry Hire, and Partload workflows are Phases 5–7. Cardinality of LRs per multi-vehicle enquiry remains Pending (`PO-P4-CNV-CARDINALITY`).

## Decision

When conversion is implemented (Batch F), it creates **Unified LR** records only. It must not create Trips, Lorry Hires, Challans, Inward Registers, Delivery Receipts, or accounting postings. Schema remains cardinality-neutral until `PO-P4-CNV-CARDINALITY` is approved.

## Alternatives

1. Create stub Trip/LH shells at conversion — rejected as premature.
2. Defer all conversion persistence — rejected; LR foundation is Phase 4 exit scope.

## Consequences

- Future phases attach to `lorry_receipts` via explicit interfaces/events.
- `LrDependencyGuard` registers real blockers only when those modules exist.

## Related

PO-P4-CONVERT; PO-P4-CNV-CARDINALITY Pending; Rules §7–8; Phases.md §§4–7
