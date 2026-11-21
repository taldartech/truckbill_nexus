# ADR-002: Tenant Context Propagation

| Field | Value |
|---|---|
| ADR ID | ADR-002 |
| Title | Tenant Context Propagation |
| Status | Proposed |
| Date | 2026-11-21 |

## Context
Architecture §3 requires shared PostgreSQL multi-tenancy with `company_id` / `branch_id` / explicit `owner_id` where applicable. Tenant scope must be enforced in policies, query layers and database constraints. Jobs, cache keys, exports, downloads, APIs and real-time channels must carry the same boundaries. Client-supplied tenant IDs must not be trusted without membership resolution.

## Decision
Propagate explicit company/branch (and owner where required) context on HTTP requests, queued jobs and cache key namespaces. Enforce authorization and query scoping in application layers. PostgreSQL RLS may be evaluated later as defence in depth only — never as the sole control.

## Alternatives
1. RLS-only tenancy — rejected.
2. Separate database per tenant — not selected; shared DB is specified.
3. Trust frontend-selected company/branch without server resolution — rejected.

## Consequences
- Phase 1 must deliver tenant context primitives before business modules.
- All factories/tests need multi-tenant fixtures and negative tests.
- Exact middleware/context object design is Proposed pending implementation planning.

## Security implications
Failure to propagate context is a cross-tenant data leak risk (AUD-SEC-001).

## Related requirements
TB-SEC-001, TB-SEC-002, TB-SEC-003, TB-SEC-004, TB-OWN-007

## Related audit findings
AUD-SEC-001, AUD-REPO-001, AUD-ARCH-001
