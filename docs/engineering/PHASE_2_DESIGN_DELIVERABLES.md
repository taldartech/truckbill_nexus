# Phase 2 Design Deliverables Register

**Status:** Active — gate requirement **APPROVED** (PO-P2-DESIGN); baseline workspace decisions **APPROVED** (D11–D16); formal artefact approval **APPROVED WITH DOCUMENTED EXCEPTIONS** (PO-P2-DESIGN-FORMAL, 22 November 2026)  
**Authority:** Design.md §14; Phases.md Phase 2; TB-DES-002; `PHASE_2_AUTHORIZATION_RECORD.md`; `PHASE_2_PRODUCT_OWNER_DECISIONS.md` D11–D23  
**Rule:** Formal design approval with exceptions does **not** auto-start P2-WS-8. Product UI requires a **separate** Product Owner implementation authorization. Entitlement-dependent interactions remain blocked (AUD-CON-006 / TB-SUB-STATE-001). Backend and non-UI technical work may proceed.

**Important:** No fabricated dashboard metrics or unapproved workflows. Do not invent trial/grace/restricted permissions.

---

## Gate summary

| Item                                | Status                                                                                         |
| ----------------------------------- | ---------------------------------------------------------------------------------------------- |
| PO-P2-DESIGN gate requirement       | **APPROVED** 22 November 2026                                                                  |
| Baseline workspace design decisions | **APPROVED** — PO-P2-DESIGN-SWITCHER / LANDING / DEACT-VIEW / DASH / MODULES / SUB-UX          |
| Formal design artefact approval     | **APPROVED WITH DOCUMENTED EXCEPTIONS** — PO-P2-DESIGN-FORMAL (22 November 2026)               |
| Final design review (engineering)   | Complete — `docs/design/phase-2/FINAL_DESIGN_REVIEW.md`                                        |
| P2-WS-8 product UI implementation   | **Eligible for separate authorization only** — not started; entitlement-dependent work blocked |
| P2-WS-9 certification               | **Unauthorized**                                                                               |

---

## Required artefacts (Phase 2 product surfaces)

| Deliverable                           | Description                                                         | Status                                  | Owner          | Notes / location                                                                    |
| ------------------------------------- | ------------------------------------------------------------------- | --------------------------------------- | -------------- | ----------------------------------------------------------------------------------- |
| Owner Panel information architecture  | Company admin, billing; unified switcher; deactivated view surface  | **Approved with documented exceptions** | Design package | `docs/design/phase-2/OWNER_PANEL_IA.md` — AUD-CON-006 cells excluded                |
| Branch Panel information architecture | Branch nav; hide undeveloped modules; unified switcher              | **Approved**                            | Design package | `docs/design/phase-2/BRANCH_PANEL_IA.md`                                            |
| Workspace / company selection flow    | Unified switcher; landing; sequential selection; zero-access        | **Approved**                            | Design package | `docs/design/phase-2/WORKSPACE_SELECTION_FLOW.md`                                   |
| Owner Dashboard key screens           | Identity/status + admin/billing entry points only; no charts/KPIs   | **Approved with documented exceptions** | Design package | `docs/design/phase-2/OWNER_DASHBOARD_SPEC.md` — exact entitlement chrome Pending    |
| Branch Dashboard key screens          | Context + available module shortcuts only; no charts/KPIs           | **Approved**                            | Design package | `docs/design/phase-2/BRANCH_DASHBOARD_SPEC.md`                                      |
| Access restriction / state matrix     | Owner/member/Internal/deactivation/transfer/file; high-level SUB-UX | **Approved with documented exceptions** | Design package | `docs/design/phase-2/ACCESS_STATE_MATRIX.md` — restricted exact permissions Pending |
| Formal Product Owner design approval  | Per-artefact sign-off; exceptions documented                        | **Approved** (gate record)              | Product Owner  | `docs/design/phase-2/DESIGN_REVIEW_CHECKLIST.md`                                    |
| Final design review                   | Consistency audit, decision register, readiness verdict             | Complete (engineering)                  | Engineering    | `docs/design/phase-2/FINAL_DESIGN_REVIEW.md`                                        |

Package index: `docs/design/phase-2/README.md`

## Documented exceptions (binding)

| Exception / dependency           | Status       | Dependency owner | Affected workstreams                                 |
| -------------------------------- | ------------ | ---------------- | ---------------------------------------------------- |
| AUD-CON-006 / TB-SUB-STATE-001   | Pending      | Product Owner    | Entitlement-dependent P2-WS-8 UI; Phase 3 billing UX |
| Internal Team product UI         | Out of scope | Design / PO      | Not P2-WS-8 Customer panels                          |
| Broker / OwnFleet / Partload ops | Out of scope | Later phases     | Module screens beyond hide/nav principles            |

## Recommended P2-WS-8 implementation scope (when separately authorized)

**In scope (after separate PO implementation authorization):**

- Shared workspace layouts / chrome consistent with approved IA
- Unified company switcher (Owner vs Member; server-validated)
- Sequential company → branch selection
- Landing / MRU (server-side) / dedicated zero-access screen
- Owner Panel surfaces within approved admin/billing/deactivated-view boundaries
- Branch Panel navigation with hide-unavailable modules; Branch Settings prefs only
- Admin/context dashboards (no charts/KPIs)
- High-level subscription state presentation labels only (no invented permissions)

**Out of scope / blocked until further decision:**

- Exact trial/grace/restricted/expired entitlement matrices and dependent interactions
- Subscription checkout, proration, module-add billing UI (Phase 3)
- Internal Team product UI
- Broker / OwnFleet / Partload operational screens
- P2-WS-9 certification

## Brand constraints (already decided)

- Light-first; avoid dark page backgrounds
- Poppins (DEC-007 / TB-DES-001)
- Gradient `#2FB36F` → `#2D8CFF`
- Professional B2B SaaS; accessible forms/tables/filters

## Related documentation

- `docs/implementation/PHASE_0_DELIVERABLES.md` (broader register; Phase 2 package does not complete the global Phase 0 inventory)
- `docs/governing/Design.md` §14
- `docs/implementation/PHASE_2_EXECUTION_PLAN.md` P2-WS-8
- `docs/engineering/PHASE_2_AUTHORIZATION_RECORD.md`
- `docs/engineering/PHASE_2_PRODUCT_OWNER_DECISIONS.md` (D11–D23)
