# ADR-009: Transactional Outbox and Idempotency

| Field           | Value                                |
| --------------- | ------------------------------------ |
| ADR ID          | ADR-009                              |
| Title           | Transactional Outbox and Idempotency |
| Status          | Accepted                             |
| Date            | 2026-11-21                           |
| Accepted by     | Product Owner (PO-P1-ADR-009)        |
| Acceptance date | 21 November 2026                     |

## Context

Architecture §6 requires atomic business mutation + outbox commit, idempotent consumers, bounded retries, dead-letter handling and correlation IDs. Payments, webhooks and financial posting require idempotency keys (Architecture §12; Rules §15).

## Decision

Provide platform primitives for transactional outbox and idempotency in Phase 1 (WS-6). Consumers reload source state and revalidate. Exact table schemas and dispatcher technology are finalized during WS-6 under this Accepted decision.

## Alternatives

1. Fire-and-forget events after commit — rejected.
2. Dual-write without outbox — rejected.
3. Exactly-once broker semantics as sole guarantee — insufficient alone.

## Consequences

- Minimal generic primitives first; domain events grow with modules.
- Razorpay webhook idempotency (Phase 3) depends on these primitives.

## Security implications

Idempotency prevents duplicate financial side effects from retries.

## Related requirements

TB-EVT-001, TB-SUB-008

## Related audit findings

AUD-ARCH-004

## Acceptance record

| Decision ID   | Decision                                    | Status   | Date             | Authority     |
| ------------- | ------------------------------------------- | -------- | ---------------- | ------------- |
| PO-P1-ADR-009 | Accept transactional outbox and idempotency | Accepted | 21 November 2026 | Product Owner |
