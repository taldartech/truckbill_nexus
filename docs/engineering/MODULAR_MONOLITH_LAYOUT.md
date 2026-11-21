# Modular Monolith Layout (ADR-001 Accepted)

**Authority:** ADR-001 / PO-P1-ADR-001 (21 November 2026)

## Convention

| Path                                                         | Purpose                                                                                                   |
| ------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------- |
| `app/Http`, `app/Models`, `app/Providers`, `app/Concerns`, … | Laravel application shell (HTTP adapters, framework entry points, starter models until migrated)          |
| `app/Domains/{Context}/`                                     | Bounded-context application code (Actions, domain Models, Policies, etc.) introduced as modules are built |
| `app/Support/`                                               | Shared-kernel primitives (tenant context, audit helpers, outbox, etc.)                                    |

## Rules

- Cross-context writes use explicit application interfaces or domain events — not arbitrary direct table writes.
- Do not introduce microservices or separate deployable packages in Phase 1.
- Domain folders are created when a workstream needs them; empty `Domains/` and `Support/` markers establish the convention in WS-1.
