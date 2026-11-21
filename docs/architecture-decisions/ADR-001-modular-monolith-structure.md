# ADR-001: Modular Monolith Structure

| Field | Value |
|---|---|
| ADR ID | ADR-001 |
| Title | Modular Monolith Structure |
| Status | Proposed |
| Date | 2026-11-21 |

## Context
Architecture.md requires a modular monolith with bounded contexts (Identity/Access, Company/Branch, CRM/Enquiry, Masters, Consignment/LR, Broker, OwnFleet, Partload, Accounting, Subscription/Billing, Notifications, Communications, Asset Management, Inventory/Workshop, Documents, Audit, Reporting). The current repository is a flat Laravel React starter without module packages. Premature microservices are rejected.

## Decision
Adopt a modular monolith. Cross-context writes use explicit application interfaces or domain events, not arbitrary direct table writes. Exact folder/package layout (e.g. `app/Domains/*` vs Laravel modules) remains **Proposed** until Phase 1 design is approved after Phase 0 formal approval.

## Alternatives
1. Premature microservices per context — rejected by Architecture.md.
2. Unstructured flat `App\` growth — high coupling risk (AUD-ARCH-001).
3. Separate deployable services for billing/accounting early — not authorized.

## Consequences
- Clear ownership of state and validation per context.
- Requires conventions for shared kernel, events and authorization.
- Implementation deferred until Phase 0 formal approval (DEC-004).

## Security implications
Bounded contexts must still enforce tenant and authorization at every boundary.

## Related requirements
TB-SEC-001, TB-PLT-001

## Related audit findings
AUD-ARCH-001, AUD-GOV-009
