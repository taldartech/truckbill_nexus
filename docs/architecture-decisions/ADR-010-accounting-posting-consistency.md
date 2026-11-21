# ADR-010: Accounting Posting Consistency

| Field  | Value                          |
| ------ | ------------------------------ |
| ADR ID | ADR-010                        |
| Title  | Accounting Posting Consistency |
| Status | Pending                        |
| Date   | 2026-11-21                     |

## Context

Architecture §7 requires balanced posted vouchers, immutability, reversals, and either same-database atomic operational+accounting posting where practical, or async posting with explicit pending state and reconciliation. PRD/Rules posting maps are intent pending accountant/tax advisor validation (AUD-GOV-006).

## Decision

- Product rules for immutability, balance and reversal are **Accepted** as documented.
- Sync vs async posting strategy for each source document type remains **Pending** until Phase 8 design and external accounting validation.
- Do not treat posting maps as production-ready without external validation.

## Alternatives

1. Always synchronous posting in the same DB transaction.
2. Always asynchronous posting with pending UI states.
3. Hybrid by document type.

## Consequences

- Phase 8 blocked on external validation and this ADR acceptance.
- Earlier phases that post accounting (e.g. Partload DR) must follow the eventual decision.

## Security implications

Financial integrity failures are high-severity business and compliance risks.

## Related requirements

TB-ACC-001, TB-ACC-002, TB-ACC-003, TB-PTL-004

## Related audit findings

AUD-GOV-006, AUD-ARCH-002
