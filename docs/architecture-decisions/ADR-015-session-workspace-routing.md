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
9. Context-switch `redirect` targets must be same-origin relative paths (or absolute URLs normalized via `SafeInternalRedirect`); external and protocol-relative targets are rejected.
10. Company-scoped management middleware denies non-owners with 403 before soft “select company” redirects.

## Amendment — Global Owner administration vs operational session (25 November 2026)

Product Owner defect: Create Branch failed with “Company administration writes are unavailable while the company is deactivated” when a stale deactivated company remained in `WorkspaceSession`, even though the Owner selected a different active company.

**Corrected rule:**

11. The Owner Panel is a **global** administration workspace for the authenticated Owner. Global Owner routes (`/management`, companies/branches/users/subscriptions/audit lists, global Create Branch) must **not** require an active operational Company or Branch session, and must **not** derive the mutation target from `WorkspaceSession`.
12. Administrative target company/branch identity comes from **explicit route parameters**, authorized resource relationships, or (for global Create Branch preselect only) the non-tenant query key `?company=` — never from a session fallback.
13. `AuthorizesOwnerPanel` activates **request-scoped** `TenantContext` only. It must not call `WorkspaceSession::ensureCompany()` / `put()` on ordinary Owner Panel authorization.
14. Explicit company-addressed mutations use routes such as:
    - `GET/POST /management/companies/{company}/branches…`
    - Global Create Branch: `GET /management/branches/create` → store on the company-addressed POST above.
15. Operational workspace session is established **only** by explicit Enter Company / Enter Branch context POSTs. **Return to Owner Portal** (`POST /workspace/context/leave-branch`) clears **both** company and branch session keys so administration cannot inherit a stale operational company.
16. Company-scoped `/management/company/*` and `/management/subscription` routes may continue to use `management.company` session resolution for clean URLs; Prefer migrating mutations to company-addressed routes. Session context must never override an explicit route company on those new routes.

## Consequences

- Controllers keep type-hinted Company/Branch after middleware injection; resource route params precede session params in method signatures.
- Global Owner lists must not require a selected company.
- Frontend action icons POST context then redirect to clean URLs; Inertia destination payloads use relative paths.
- Tests use `enterManagementCompany` / `enterBranchWorkspace` / `seedManagementSession` / `seedBranchSession` / `internalRoute` helpers.
- Session-independence coverage: `tests/Feature/OwnerPanelSessionIndependenceTest.php`.

## Related

ADR-002, Owner/Branch navigation specs, `RESPONSIVE_TABLE_STANDARD.md`, `ACTION_REGRESSION_CERTIFICATION.md`, `GLOBAL_UI_CONSISTENCY_AUDIT.md`, `OWNER_GLOBAL_PANEL_CERTIFICATION_REPORT.md`
