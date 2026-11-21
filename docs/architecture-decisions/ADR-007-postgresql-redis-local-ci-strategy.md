# ADR-007: PostgreSQL and Redis Local/CI Strategy

| Field | Value |
|---|---|
| ADR ID | ADR-007 |
| Title | PostgreSQL and Redis Local/CI Strategy |
| Status | Proposed |
| Date | 2026-11-21 |

## Context
Architecture requires PostgreSQL and Redis/Valkey. Local `.env` already uses pgsql + Redis for cache/queue/session. `.env.example` still defaults to SQLite and database drivers. `phpunit.xml` targets PostgreSQL `nexus_test`. CI lacks a Postgres service. Agent instructions describe Redis for cache/queue/session in Laravel.

## Decision (directional, not yet implemented)
Standardize on PostgreSQL for application and test databases, and Redis (phpredis) for cache, queue and session in local/non-production environments matching Architecture, once Phase 0 is approved and Phase 1 engineering is authorized. Update `.env.example` and CI accordingly at that time — **not during Phase 0 documentation**.

## Alternatives
1. Keep SQLite for local and CI — conflicts with PostgreSQL-specific constraints and audit goals.
2. Database drivers for cache/queue/session in production-like envs — conflicts with documented Redis direction.
3. Valkey-compatible Redis protocol — candidate later; not decided.

## Consequences
- Documentation now states the target; implementation awaits Phase 1 authorization (DEC-004).
- CI Postgres service design remains Proposed.

## Security implications
Consistent environments reduce “works on my machine” authz/tenant bugs escaping to production.

## Related requirements
TB-SEC-001, TB-PLT-001

## Related audit findings
AUD-REPO-002, AUD-REPO-003
