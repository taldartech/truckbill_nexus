# ADR-001: Modular Monolith Structure

| Field           | Value                         |
| --------------- | ----------------------------- |
| ADR ID          | ADR-001                       |
| Title           | Modular Monolith Structure    |
| Status          | Accepted                      |
| Date            | 2026-11-21                    |
| Accepted by     | Product Owner (PO-P1-ADR-001) |
| Acceptance date | 21 November 2026              |

## Context

Architecture.md requires a modular monolith with bounded contexts (Identity/Access, Company/Branch, CRM/Enquiry, Masters, Consignment/LR, Broker, OwnFleet, Partload, Accounting, Subscription/Billing, Notifications, Communications, Asset Management, Inventory/Workshop, Documents, Audit, Reporting). The current repository is a flat Laravel React starter without module packages. Premature microservices are rejected.

## Decision

Adopt a modular monolith. Cross-context writes use explicit application interfaces or domain events, not arbitrary direct table writes.

**Accepted concrete `app/` layout (PO-P1-ADR-001):**

- Retain the Laravel application shell under `app/` (`Http`, `Models`, `Providers`, `Concerns`, and related framework entry points).
- Place bounded-context code under `app/Domains/{Context}/` as domains are introduced.
- Place cross-cutting shared-kernel primitives under `app/Support/`.
- Do not introduce microservices or separate deployable services in Phase 1.

## Alternatives

1. Premature microservices per context — rejected by Architecture.md.
2. Unstructured flat `App\` growth — high coupling risk (AUD-ARCH-001).
3. Separate deployable services for billing/accounting early — not authorized.

## Consequences

- Clear ownership of state and validation per context.
- Requires conventions for shared kernel, events and authorization.
- Phase 1 WS-1 establishes Domains/Support conventions; domain packages grow with later workstreams and phases.

## Security implications

Bounded contexts must still enforce tenant and authorization at every boundary.

## Related requirements

TB-SEC-001, TB-PLT-001

## Related audit findings

AUD-ARCH-001, AUD-GOV-009

## Acceptance record

| Decision ID   | Decision                          | Status   | Date             | Authority     |
| ------------- | --------------------------------- | -------- | ---------------- | ------------- |
| PO-P1-ADR-001 | Accept modular monolith structure | Accepted | 21 November 2026 | Product Owner |
