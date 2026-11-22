# ADR-013: Quotation Broadcast Outbox Boundary

| Field           | Value                                    |
| --------------- | ---------------------------------------- |
| ADR ID          | ADR-013                                  |
| Title           | Quotation Broadcast Outbox Boundary      |
| Status          | Accepted                                 |
| Date            | 2026-11-23                               |
| Accepted by     | Product Owner (PO-P4-BROADCAST Option A) |
| Acceptance date | 23 November 2026                         |

## Context

PRD §9 requires broadcasting quotation requests to transporters via configured channels and retaining history. Full Communications providers belong to Phase 9. Phase 4 must not invent WhatsApp/SMS/Email delivery.

## Decision

Phase 4 persists broadcast **requests** (targets, channel intent, correlation) and enqueues outbox events such as `quotation.broadcast.requested`. Provider adapters, template compliance, credit reservation, and delivery-status claims are Phase 9. Persisting a request is not provider submission.

## Alternatives

1. Implement a provider in Phase 4 — rejected (premature Phase 9 pull-forward).
2. Omit broadcast structures until Phase 9 — rejected (weaker vs PRD history requirement).

## Consequences

- Batch D may build request UI/services without provider SDKs.
- Phase 9 consumers must be idempotent on outbox payloads.

## Related

PO-P4-BROADCAST; Architecture §6, §9; Phases.md Phase 9
