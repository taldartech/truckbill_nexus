# ADR-010: Accounting Posting Consistency

| Field  | Value                              |
| ------ | ---------------------------------- |
| ADR ID | ADR-010                            |
| Title  | Accounting Posting Consistency     |
| Status | Accepted (PO-ERP-001, 26 Nov 2026) |
| Date   | 2026-11-21; Accepted 2026-11-26    |

## Context

Architecture §7 requires balanced posted vouchers, immutability, reversals, and either same-database atomic operational+accounting posting where practical, or async posting with explicit pending state and reconciliation. PRD/Rules posting maps remain subject to professional tax/accountant validation before production statutory reliance (AUD-GOV-006 / TB-TAX-001), but local ERP implementation is authorized under PO-ERP-001.

## Decision

- Product rules for immutability, balance and reversal are **Accepted** as documented.
- **Default strategy: synchronous** posting in the same database transaction as the operational financial event (`AuditedTransaction`), with source idempotency `(source_type, source_id, posting_purpose)`.
- **Hybrid exception:** document types that cannot share a transaction (external webhooks, long-running imports) use durable outbox + `accounting_posting_links` pending/failed states with explicit reconciliation.
- Posting maps ship as documented defaults for transport workflows; statutory production filing readiness remains Pending external validation.

## Alternatives

1. Always synchronous posting in the same DB transaction.
2. Always asynchronous posting with pending UI states.
3. Hybrid by document type (**selected** — sync default, async only when required).

## Consequences

- Accounting foundation and operational bridges may proceed under PO-ERP-001.
- Production statutory filing / live tax rates still require external validation before production deploy.

## Security implications

Financial integrity failures are high-severity business and compliance risks.

## Related requirements

TB-ACC-001, TB-ACC-002, TB-ACC-003, TB-PTL-004

## Related audit findings

AUD-GOV-006, AUD-ARCH-002
