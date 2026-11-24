# Phase 5 & 6 RBAC Final Matrix (Stabilization Pass)

**Date:** 24 November 2026

## Preserved

- AUD-013: Branch dashboard requires Owner **or** `platform.operations.view` (`88bfb55`).
- Module permissions remain on branch roles; Owner designation does not auto-grant Trip/Broker action codes.
- No-Freight view continues to redact via `FreightAmountPresentation` on invoice facades.

## This pass

| Change                              | Security impact                     |
| ----------------------------------- | ----------------------------------- |
| Landing aligned with dashboard gate | Prevents assignment-only 403 bounce |
| Broker invoice print                | Gated by `broker.invoice.print`     |
| Trip vehicle lock                   | Server rejects forged vehicle_id    |

## Open

Full 11-persona forged-HTTP browser matrix not re-run in this assignment. Rely on existing `tests/Feature/Rbac` until Stage O browser certification is scheduled.
