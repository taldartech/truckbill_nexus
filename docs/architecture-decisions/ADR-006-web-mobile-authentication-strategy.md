# ADR-006: Web and Mobile Authentication Strategy

| Field | Value |
|---|---|
| ADR ID | ADR-006 |
| Title | Web and Mobile Authentication Strategy |
| Status | Pending |
| Date | 2026-11-21 |

## Context
Customer identities have a single active session across web and TruckBill mobile; Internal users may have multiple sessions. Architecture mentions Sanctum “where appropriate.” Sanctum is not installed. Mobile client architecture, device token registry and revocation mechanics are underspecified (AUD-GAP-004).

## Decision
No technical authentication stack decision is accepted yet beyond existing Fortify web login/password-reset for the starter. Choose web session + mobile token strategy only after product owner approval of a concrete proposal.

## Alternatives (unevaluated)
1. Laravel Sanctum token API for mobile + cookie sessions for web.
2. First-party token registry without Sanctum.
3. Fully separate mobile auth provider.

## Consequences
- Cannot implement customer single-session enforcement across mobile until decided.
- Do not install Sanctum until this ADR is Accepted.

## Security implications
Session fixation, token theft and revocation semantics depend on the chosen approach.

## Related requirements
TB-AUTH-004, TB-AUTH-005

## Related audit findings
AUD-GAP-004, AUD-REPO-009
