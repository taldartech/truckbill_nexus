# ADR-003: Audit Log and Atomic Mutation

| Field | Value |
|---|---|
| ADR ID | ADR-003 |
| Title | Audit Log and Atomic Mutation |
| Status | Accepted |
| Date | 2026-11-21 |
| Accepted by | Product Owner (PO-P1-ADR-003) |
| Acceptance date | 21 November 2026 |

## Context
Rules §4 and Architecture §5 require immutable audit history, mandatory remarks for edit/update/deletion, and atomic commit of business mutation with audit (and outbox where applicable).

## Decision
Every qualifying mutation writes an append-only audit record in the same database transaction as the mutation. Audit records are immutable. Creation does not require a remark; edits/deletes do. Cancellation requires reason and applicable date/time.

## Alternatives
1. Best-effort async audit after commit — rejected (can lose audit on failure).
2. Mutable audit tables — rejected.
3. Application logs only — insufficient for product audit requirements.

## Consequences
- Phase 1 must provide a reusable audit writer and transaction pattern (WS-4).
- Schema details are finalized during WS-4 implementation under this Accepted decision.

## Security implications
Privileged-action audit is required for accountability and incident response.

## Related requirements
TB-AUD-001, TB-AUD-002, TB-AUD-003, TB-EVT-001

## Related audit findings
AUD-SEC-003, AUD-ARCH-004

## Acceptance record
| Decision ID | Decision | Status | Date | Authority |
|---|---|---|---|---|
| PO-P1-ADR-003 | Accept audit log and atomic mutation | Accepted | 21 November 2026 | Product Owner |
