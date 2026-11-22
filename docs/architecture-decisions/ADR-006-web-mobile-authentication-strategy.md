# ADR-006: Web and Mobile Authentication Strategy

| Field           | Value                                  |
| --------------- | -------------------------------------- |
| ADR ID          | ADR-006                                |
| Title           | Web and Mobile Authentication Strategy |
| Status          | Accepted (Phase 2 web-only scope)      |
| Date            | 2026-11-21                             |
| Accepted by     | Product Owner (PO-P2-ADR-006)          |
| Acceptance date | 22 November 2026                       |

## Context

Customer identities require a single active session policy; Internal Team users may hold multiple concurrent sessions (TB-AUTH-004, TB-AUTH-005). Architecture mentions Sanctum “where appropriate.” Sanctum was not installed in Phase 1. Mobile client architecture, device token registry and cross-channel revocation remained underspecified (AUD-GAP-004).

Phase 2 Product Owner authorization (PO-P2-AUTH, 22 November 2026) requires a concrete authentication decision that unblocks web identity/access work without prematurely implementing mobile infrastructure.

## Decision

**Accepted for Phase 2 — web-only authentication:**

1. Use **Laravel Fortify** and **Laravel session-based authentication** for the first-party web application (Inertia).
2. Do **not** install or implement **Laravel Sanctum** in Phase 2.
3. Do **not** implement mobile authentication, API token infrastructure, or device token registries in Phase 2.
4. **Customer Users:** exactly **one active web session** at a time (new Customer web login revokes prior Customer web sessions).
5. **Internal Team users:** may hold **multiple concurrent web sessions**.
6. Enforce **session revocation** on password change, identity deactivation, and other applicable security events defined in governing rules (including ownership-transfer session effects where specified).
7. Keep application session policy modular enough that a **future** ADR revision or companion decision can add mobile authentication without rewriting identity/company foundations.
8. Do **not** prematurely invent mobile-specific abstractions in Phase 2.

**Deferred (not rejected):** Cross-channel customer single-session spanning TruckBill mobile, Sanctum (or alternate) mobile tokens, and related AUD-GAP-004 mobile registry details remain for a future Product Owner decision when mobile delivery is authorized. Until then, TB-AUTH-004 is implemented for **web** only; the mobile/cross-channel portion stays Deferred.

## Alternatives considered

1. **Laravel Sanctum** for SPA cookies + mobile API tokens in Phase 2 — not selected; Product Owner chose web-only to avoid premature mobile scope.
2. First-party token registry without Sanctum — not selected for Phase 2; unnecessary without mobile tokens.
3. Fully separate mobile auth provider — out of Phase 2 scope.
4. Web-only Fortify sessions (selected).

## Consequences

- Phase 2 may implement Customer single-web-session and Internal multi-session policies on Laravel sessions.
- Sanctum must not appear in Composer dependencies until a future Accepted decision authorizes it.
- Mobile clients cannot authenticate against Phase 2 APIs via tokens until a future decision.
- Architecture “Sanctum where appropriate” remains a future option, not a Phase 2 mandate.
- Session fixation prevention, secure cookies, CSRF and rate limiting remain mandatory for web auth.

## Security implications

- Web session theft/fixation risks are mitigated by Laravel session hardening, CSRF, secure cookie flags and revocation on security events.
- Absence of mobile tokens in Phase 2 reduces token-theft surface for this phase but leaves mobile delivery blocked.
- Authorization and tenant isolation remain independent of the session carrier.

## Related requirements

TB-AUTH-004 (web portion in Phase 2; mobile Deferred), TB-AUTH-005

## Related audit findings

AUD-GAP-004, AUD-REPO-009

## Acceptance record

| Decision ID   | Decision                                                                 | Status   | Date             | Authority     |
| ------------- | ------------------------------------------------------------------------ | -------- | ---------------- | ------------- |
| PO-P2-ADR-006 | Accept web-only Fortify + session auth for Phase 2; defer mobile/Sanctum | Accepted | 22 November 2026 | Product Owner |

## History

| Date       | Change                                               |
| ---------- | ---------------------------------------------------- |
| 2026-11-21 | Created as Pending                                   |
| 2026-11-22 | Accepted with Phase 2 web-only scope (PO-P2-ADR-006) |
