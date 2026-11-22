# ADR-005: Internal and Customer Identity Model

| Field  | Value                                                                     |
| ------ | ------------------------------------------------------------------------- |
| ADR ID | ADR-005                                                                   |
| Title  | Internal and Customer Identity Model                                      |
| Status | Accepted (product model) / Accepted (P2-WS-1 identity persistence schema) |
| Date   | 2026-11-21                                                                |

## Context

PRD/Rules define one global identity with mutually exclusive Internal Team or Customer type, separate permission domains, Owner as protected designation, invite/admin provisioning, no public self-registration, admin-only email change (DEC-009), and no self-service account deletion (DEC-006).

## Decision

- Product identity model as specified in PRD §§3–6 and Rules §§2–3 is **Accepted**.
- **P2-WS-1 technical persistence (22 November 2026; corrected same day):** `users.identity_type` (`internal`|`customer`), `users.is_super_admin` (protected system privilege, Internal-only), `users.deactivated_at`, SoftDeletes `deleted_at`, with PostgreSQL check constraints. The premature fixed `internal_role` enum (`admin`/`sales_executive`/`support_executive`/`super_admin`) was removed so ordinary Internal/Customer roles remain **database-managed via Permission Master (P2-WS-3)**. Company/membership/invitation/session-token tables remain later workstreams. Sanctum/mobile remain out of Phase 2 (ADR-006).
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

## P2-WS-1 acceptance note

| Decision ID          | Decision                                                                                                   | Status      | Date             | Authority                                |
| -------------------- | ---------------------------------------------------------------------------------------------------------- | ----------- | ---------------- | ---------------------------------------- |
| PO-P2-AUTH / P2-WS-1 | Persist mutually exclusive identity type + Super Admin system privilege + deactivation/soft-delete columns | Implemented | 22 November 2026 | Product Owner authorization; engineering |
| P2-WS-1 correction   | Replace fixed `internal_role` enum with `is_super_admin`; defer configurable roles to P2-WS-3              | Implemented | 22 November 2026 | Architecture correction                  |
| P2-WS-3              | Database-managed Internal/Customer roles + Permission Master; Super Admin remains `is_super_admin` only    | Implemented | 22 November 2026 | Permission Master / branch roles         |
