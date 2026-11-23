# Full-Stack Delivery Standard

**Status:** Mandatory from the next functional development batch onward  
**Cursor rule:** `.cursor/rules/truckbill-fullstack-delivery.mdc` (`alwaysApply: true`)  
**Aligned with:** Clean Code standards, governing Phases / Rules / Architecture

---

## 1. Purpose

TruckBill Nexus delivers a modular monolith with Inertia React product surfaces. User-facing vertical slices must ship as coherent full-stack work: database, domain, authorization, UI, tests, and certification in one authorized batch.

Backend-only completion of a product feature is an **exception**, not the default.

---

## 2. Delivery sequence

```text
Requirements → Design Review → Backend → Frontend → Integration
  → Automated Tests → Browser QA → Security Review → Documentation
  → Certification → Local Commit
```

Design approval for product UI is a **hard gate**. Unresolved Product Owner decisions remain Pending; do not invent answers.

---

## 3. Completeness checklist

| Layer        | Expectation                                                      |
| ------------ | ---------------------------------------------------------------- |
| Data         | Forward migrations, constraints, tenant-safe FKs                 |
| Domain       | Focused services; no duplicated business rules in React          |
| HTTP         | Thin controllers; Form Requests / validated payloads             |
| AuthZ        | Server-side permission + tenant checks on every mutation/read    |
| UI           | Approved design system; typed Inertia props; loading/empty/error |
| Docs / files | Shared document drawer when the parent supports attachments      |
| Tests        | Pest feature coverage + frontend tests with installed tools      |
| Browser      | Critical paths via existing Playwright setup when available      |
| Cert         | Slice certification records actual evidence only                 |

---

## 4. Explicit exceptions

| Case                                | Treatment                                       |
| ----------------------------------- | ----------------------------------------------- |
| Infrastructure / ADR foundations    | May have no product UI                          |
| Background jobs / outbox consumers  | May have no dedicated screen                    |
| Design or policy Pending            | Block UI; document gate                         |
| Named backend-only PO authorization | Document as exception; do not imply UI Complete |

---

## 5. Phase 4 process change

| Batch | Delivery mode                                                         |
| ----- | --------------------------------------------------------------------- |
| A     | Foundations (backend/schema) — certified                              |
| B     | Backend enquiry intake — certified; UI gated by `PO-P4-DESIGN-FORMAL` |
| C–F   | **Backend + UI + tests in the same batch** after design approval      |
| G     | Integration QA, security, accessibility, final Phase 4 certification  |

Batch G must **not** be the first UI implementation for Batches C–F.

`PO-P4-CNV-CARDINALITY` remains Pending until Product Owner decision; do not invent LR cardinality.

---

## 6. Demo data (permanent)

**`database/seeders/LocalDemoWorkspaceSeeder.php` is the only demo-data seeder.**

- Do **not** add phase-specific or module-specific demo seeder classes (`CrmDemoSeeder`, `OwnFleetDemoSeeder`, etc.).
- Do **not** resurrect `LocalDemoUserSeeder` or other legacy demo seeders.
- Keep `DatabaseSeeder`, `RbacSeeder`, and `SubscriptionCatalogueSeeder` for essential application/reference data only — never wire demo personas into `DatabaseSeeder`.
- **Whenever an authorized feature ships, the same batch must update `LocalDemoWorkspaceSeeder` with meaningful, valid fixtures** (identity, masters, subscriptions, enquiries, LR/OwnFleet states that the domain actually supports).
- A feature is not demo-ready if required fixtures are missing.
- Profiles remain `small` / `standard` / `large`. Local/testing only; refuse production; idempotent; no live payments or outbound messaging.

Delivery checklist for every new module:

`Backend + UI + Pest + Playwright + LocalDemoWorkspaceSeeder update + Documentation + Certification`

---

## 7. Prohibited “fake complete” patterns

- Dummy/hardcoded operational metrics presented as live
- Buttons without implemented actions
- Fake success toasts
- Placeholder workflows labeled Complete
- Client-only authorization
- Fabricated financial totals
- Mock payment success in ordinary customer flows

---

## 8. Compatibility

Does not override governing PRD / Rules / Architecture / ADRs or Product Owner decisions. Prefer existing project patterns when they already satisfy this standard.
