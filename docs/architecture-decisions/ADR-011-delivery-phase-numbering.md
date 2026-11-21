# ADR-011: Delivery Phase Numbering

| Field  | Value                    |
| ------ | ------------------------ |
| ADR ID | ADR-011                  |
| Title  | Delivery Phase Numbering |
| Status | Accepted                 |
| Date   | 2026-11-21               |

## Context

Foundation Audit AUD-CON-001 found overloaded use of “Phase 1” for both engineering foundation and Razorpay billing. Traceability TB-SUB-002 text conflicted with its Phase column.

## Decision (DEC-001)

- `docs/governing/Phases.md` is the **single authoritative source** for delivery phase numbers.
- Phase 1 = engineering foundation.
- Initial manual Razorpay subscription renewals = **Phase 3**.
- Ambiguous “Phase 1 billing” language is removed; use “initial manual renewal delivery (Phase 3)” where needed.
- No application coding until Phase 0 formal approval (DEC-004).

## Alternatives

1. Keep dual meanings of Phase 1 — rejected.
2. Move Razorpay into Phase 1 — rejected by user decision.

## Consequences

- PRD, Rules, Design, Memory, README and traceability updated accordingly.
- Agents must not implement billing during Phase 1.

## Security implications

None directly; reduces sequencing errors that could ship billing without foundation controls.

## Related requirements

TB-PLT-001, TB-PLT-002, TB-SUB-002

## Related audit findings

AUD-CON-001, AUD-CON-008, AUD-CON-004
