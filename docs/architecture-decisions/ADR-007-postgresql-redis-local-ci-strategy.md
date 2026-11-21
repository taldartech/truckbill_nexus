# ADR-007: PostgreSQL and Redis Local/CI Strategy

| Field           | Value                                  |
| --------------- | -------------------------------------- |
| ADR ID          | ADR-007                                |
| Title           | PostgreSQL and Redis Local/CI Strategy |
| Status          | Accepted                               |
| Date            | 2026-11-21                             |
| Accepted by     | Product Owner (PO-P1-ADR-007)          |
| Acceptance date | 21 November 2026                       |

## Context

Architecture requires PostgreSQL and Redis/Valkey. Local `.env` already uses pgsql + Redis for cache/queue/session. `.env.example` still defaults to SQLite and database drivers. `phpunit.xml` targets PostgreSQL `nexus_test`. CI lacks a Postgres service. Agent instructions describe Redis for cache/queue/session in Laravel.

## Decision

Standardize on PostgreSQL for application and test databases, and Redis (phpredis) for cache, queue and session in local/non-production environments matching Architecture. Update `.env.example` and CI accordingly in Phase 1 (WS-1 / WS-2). Automated tests may continue to use array cache, sync queue and array session where appropriate (`phpunit.xml`).

## Alternatives

1. Keep SQLite for local and CI — conflicts with PostgreSQL-specific constraints and audit goals.
2. Database drivers for cache/queue/session in production-like envs — conflicts with documented Redis direction.
3. Valkey-compatible Redis protocol — candidate later; not decided.

## Consequences

- Phase 1 implements PostgreSQL + Redis baseline and mandatory CI Postgres migrate + Pest.
- Local and CI environments must not silently fall back to SQLite for application intent.

## Security implications

Consistent environments reduce “works on my machine” authz/tenant bugs escaping to production.

## Related requirements

TB-SEC-001, TB-PLT-001

## Related audit findings

AUD-REPO-002, AUD-REPO-003

## Acceptance record

| Decision ID   | Decision                                      | Status   | Date             | Authority     |
| ------------- | --------------------------------------------- | -------- | ---------------- | ------------- |
| PO-P1-ADR-007 | Accept PostgreSQL and Redis local/CI baseline | Accepted | 21 November 2026 | Product Owner |
