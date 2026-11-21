# ADR-004: Soft Delete, Deactivation and No-Restore Strategy

| Field  | Value                                             |
| ------ | ------------------------------------------------- |
| ADR ID | ADR-004                                           |
| Title  | Soft Delete, Deactivation and No-Restore Strategy |
| Status | Accepted                                          |
| Date   | 2026-11-21                                        |

## Context

Governing rules prohibit hard deletion and restoration. Modules define when soft delete or deactivation is allowed, with permissions, dependency checks, remarks and immutable audit. The Laravel starter currently hard-deletes users via profile destroy (implementation change deferred until Phase 1 authorization).

## Decision (DEC-003, DEC-006)

- Hard deletion and restoration are prohibited for user identities, companies, branches, business records, financial records, documents and audit records.
- Use controlled deactivation or soft deletion only where module requirements permit.
- Users cannot delete their own accounts; lifecycle changes use authorized administration.
- Do not invent one universal deletion workflow for every record type; unresolved lifecycle details remain Pending.

## Alternatives

1. Hard delete with backup restore — rejected (DEC-003).
2. Universal soft-delete trait for all tables identically — rejected as over-simplification.
3. Self-service account deletion — rejected (DEC-006).

## Consequences

- Starter-kit account destruction must be removed/replaced in Phase 1 (engineering work).
- Module-specific soft-delete eligibility remains as specified in PRD/Rules.
- Identity soft-delete on zero memberships remains as specified (not self-service).

## Security implications

Prevents irreversible destruction of audit and financial history; reduces account-takeover wipe risk via self-delete.

## Related requirements

TB-DEL-001, TB-DEL-002, TB-AUTH-007, TB-OWN-005, TB-OWNF-004

## Related audit findings

AUD-CON-003, AUD-SEC-002, AUD-GAP-002
