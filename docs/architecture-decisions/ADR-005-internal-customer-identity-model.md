# ADR-005: Internal and Customer Identity Model

| Field | Value |
|---|---|
| ADR ID | ADR-005 |
| Title | Internal and Customer Identity Model |
| Status | Accepted (product model) / Proposed (technical schema) |
| Date | 2026-11-21 |

## Context
PRD/Rules define one global identity with mutually exclusive Internal Team or Customer type, separate permission domains, Owner as protected designation, invite/admin provisioning, no public self-registration, admin-only email change (DEC-009), and no self-service account deletion (DEC-006).

## Decision
- Product identity model as specified in PRD §§3–6 and Rules §§2–3 is **Accepted**.
- Technical persistence (columns, tables, Sanctum/session registry) remains **Proposed** until Phase 2 design after Phase 0 approval.
- Email changes: authorized administration only for Internal and Customer unless a future governing requirement explicitly distinguishes them (DEC-009). Verification/notification/uniqueness/audit field details are Pending.

## Alternatives
1. Laravel Teams / single membership model — rejected by agent/architecture guardrails.
2. Separate login systems per audience — rejected; one login system with separate authorization.
3. Self-service profile email/delete — rejected (DEC-006, DEC-009).

## Consequences
- Phase 2 implements the product model.
- Invitation lifecycle details (AUD-GAP-003) must be specified before coding onboarding.

## Security implications
Identity type boundaries and admin-only email change reduce account takeover and impersonation risk.

## Related requirements
TB-AUTH-001 … TB-AUTH-008, TB-OWN-001, TB-RBAC-001, TB-INVITE-001

## Related audit findings
AUD-GAP-002, AUD-GAP-003, AUD-CON-003
