# ADR-015: Session-Based Management and Branch Routing

| Field       | Value                                     |
| ----------- | ----------------------------------------- |
| ADR ID      | ADR-015                                   |
| Title       | Session-Based Workspace Routing           |
| Status      | Accepted                                  |
| Date        | 2026-11-23                                |
| Accepted by | Product Owner (this UX/routing directive) |

## Context

Owner management URLs used `/owner` and embedded company UUIDs. Branch URLs embedded company and branch UUIDs. Product Owner approved clean `/management` and `/branch` URLs with secure session context (extending ADR-002).

## Decision

1. Canonical Owner management URIs use `/management/*` (route names remain `owner.*` for stability).
2. Branch operational URIs use `/branch/*` without company/branch UUIDs.
3. Explicit CSRF-protected context posts bind tenants via route model binding:
    - `POST /workspace/context/companies/{company}`
    - `POST /workspace/context/companies/{company}/branches/{branch}`
4. Middleware injects validated `company` / `branch` into the route for controllers.
5. `WorkspaceSession` carries a `context_version` for multi-tab stale mutation rejection when clients send `X-Workspace-Context-Version`.
6. Client request bodies must not carry `company_id` / `branch_id` (existing RejectClientTenantIdentifiers).
7. Redundant company dashboard is removed from navigation; company actions open settings/branches/users/subscription/audit via context switch.
8. Legacy `/owner/*` and UUID branch URLs redirect without silently changing session context.

## Consequences

- Controllers keep type-hinted Company/Branch after middleware injection; resource route params precede session params in method signatures.
- Global Owner lists must not require a selected company.
- Frontend action icons POST context then redirect to clean URLs.
- Tests use `enterManagementCompany` / `enterBranchWorkspace` / `seedBranchSession` helpers.

## Related

ADR-002, Owner/Branch navigation specs, GLOBAL_UI_CONSISTENCY_AUDIT.md
