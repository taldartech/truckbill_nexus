# Phase 2 Design Deliverables Register

**Status:** Active — gate requirement **APPROVED** (PO-P2-DESIGN); baseline workspace decisions **APPROVED** (PO-P2-DESIGN-SWITCHER / LANDING / DEACT-VIEW / DASH / MODULES / SUB-UX, 22 November 2026); artefacts **Draft — Awaiting Formal Design Approval**; engineering readiness **READY WITH DOCUMENTED EXCEPTIONS** (`docs/design/phase-2/FINAL_DESIGN_REVIEW.md`) — **not** formal approval  
**Authority:** Design.md §14; Phases.md Phase 2; TB-DES-002; `PHASE_2_AUTHORIZATION_RECORD.md`; `PHASE_2_PRODUCT_OWNER_DECISIONS.md` D11–D16  
**Rule:** Do not implement Owner Panel, Branch Panel, workspace/company selection, Owner Dashboard or Branch Dashboard **product UI** until the relevant artefact is formally Approved. Baseline decision approval alone does **not** unlock product UI. Backend and non-UI technical work may proceed.

**Important:** Product Owner approval of the Design Deliverables **requirement** and of baseline decisions is **not** formal approval of screen/IA artefacts. Final design review readiness is **not** formal approval. No fabricated dashboard metrics or unapproved workflows.

---

## Gate summary

| Item                                   | Status                                                                                |
| -------------------------------------- | ------------------------------------------------------------------------------------- |
| PO-P2-DESIGN gate requirement          | **APPROVED** 22 November 2026                                                         |
| Baseline workspace design decisions    | **APPROVED** — PO-P2-DESIGN-SWITCHER / LANDING / DEACT-VIEW / DASH / MODULES / SUB-UX |
| Artefact production                    | **Draft** — package under `docs/design/phase-2/` updated for D11–D16 baselines        |
| Final design review (engineering)      | **READY WITH DOCUMENTED EXCEPTIONS** — `docs/design/phase-2/FINAL_DESIGN_REVIEW.md`   |
| Formal design review / screen approval | **Still required** — checklist distinguishes baselines vs formal artefact approval    |
| P2-WS-8 product UI implementation      | **Blocked** until per-artefact formal approval recorded in checklist                  |

---

## Required artefacts (Phase 2 product surfaces)

| Deliverable                           | Description                                                                   | Status                                   | Owner          | Notes / location                                                                                |
| ------------------------------------- | ----------------------------------------------------------------------------- | ---------------------------------------- | -------------- | ----------------------------------------------------------------------------------------------- |
| Owner Panel information architecture  | Company admin, billing; unified switcher; deactivated view surface            | Draft — Awaiting Formal Design Approval  | Design package | `docs/design/phase-2/OWNER_PANEL_IA.md`                                                         |
| Branch Panel information architecture | Branch nav; hide undeveloped modules; unified switcher                        | Draft — Awaiting Formal Design Approval  | Design package | `docs/design/phase-2/BRANCH_PANEL_IA.md`                                                        |
| Workspace / company selection flow    | Unified switcher; landing rules; server-validated context                     | Draft — Awaiting Formal Design Approval  | Design package | `docs/design/phase-2/WORKSPACE_SELECTION_FLOW.md`                                               |
| Owner Dashboard key screens           | Identity/status + admin/billing entry points only; no charts/KPIs             | Draft — Awaiting Formal Design Approval  | Design package | `docs/design/phase-2/OWNER_DASHBOARD_SPEC.md`                                                   |
| Branch Dashboard key screens          | Context + available module shortcuts only; no charts/KPIs                     | Draft — Awaiting Formal Design Approval  | Design package | `docs/design/phase-2/BRANCH_DASHBOARD_SPEC.md`                                                  |
| Access restriction / state matrix     | Owner/member/Internal/deactivation/transfer/file; unified switcher consistent | Draft — Awaiting Formal Design Approval  | Design package | `docs/design/phase-2/ACCESS_STATE_MATRIX.md`                                                    |
| Formal Product Owner design approval  | Per-artefact sign-off; baselines vs formal approval distinguished             | Draft — Awaiting Formal Design Approval  | Product Owner  | `docs/design/phase-2/DESIGN_REVIEW_CHECKLIST.md` — sign-off blank until actual PO formal review |
| Final design review                   | Consistency audit, decision register, readiness verdict                       | Complete (engineering) — not PO approval | Engineering    | `docs/design/phase-2/FINAL_DESIGN_REVIEW.md`                                                    |

Package index: `docs/design/phase-2/README.md`

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
- `docs/engineering/PHASE_2_PRODUCT_OWNER_DECISIONS.md` (D11–D16)
