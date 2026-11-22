# Phase 2 Design Deliverables Register

**Status:** Active — gate requirement **APPROVED** (PO-P2-DESIGN, 22 November 2026); artefacts **Draft — Awaiting Product Owner Review**  
**Authority:** Design.md §14; Phases.md Phase 2; TB-DES-002; `PHASE_2_AUTHORIZATION_RECORD.md`  
**Rule:** Do not implement Owner Panel, Branch Panel, workspace/company selection, Owner Dashboard or Branch Dashboard **product UI** until the relevant artefact is Completed and Product Owner–reviewed. Backend and non-UI technical work may proceed.

**Important:** Product Owner approval of the Design Deliverables **requirement** is **not** approval of any particular screen design. No fabricated dashboard metrics or unapproved workflows.

---

## Gate summary

| Item                                   | Status                                                                                             |
| -------------------------------------- | -------------------------------------------------------------------------------------------------- |
| PO-P2-DESIGN gate requirement          | **APPROVED** 22 November 2026                                                                      |
| Artefact production                    | **Draft — Awaiting Product Owner Review** (package under `docs/design/phase-2/`, 22 November 2026) |
| Formal design review / screen approval | **Not Started** — checklist ready; no artefact marked Approved                                     |
| P2-WS-8 product UI implementation      | **Blocked** until per-artefact approval recorded here                                              |

---

## Required artefacts (Phase 2 product surfaces)

| Deliverable                           | Description                                                                                                    | Status                                | Owner          | Notes / location                                                                                |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------- | ------------------------------------- | -------------- | ----------------------------------------------------------------------------------------------- |
| Owner Panel information architecture  | Company admin, billing visibility, users/memberships entry points; DEC-002 deactivated-company limited surface | Draft — Awaiting Product Owner Review | Design package | `docs/design/phase-2/OWNER_PANEL_IA.md`                                                         |
| Branch Panel information architecture | Branch operations navigation within membership/role constraints                                                | Draft — Awaiting Product Owner Review | Design package | `docs/design/phase-2/BRANCH_PANEL_IA.md`                                                        |
| Workspace / company selection flow    | Multi-company switcher; active company context resolution UX                                                   | Draft — Awaiting Product Owner Review | Design package | `docs/design/phase-2/WORKSPACE_SELECTION_FLOW.md`                                               |
| Owner Dashboard key screens           | Light-first B2B; Poppins; `#2FB36F`→`#2D8CFF`; no fabricated metrics                                           | Draft — Awaiting Product Owner Review | Design package | `docs/design/phase-2/OWNER_DASHBOARD_SPEC.md`                                                   |
| Branch Dashboard key screens          | Same brand rules; membership-scoped                                                                            | Draft — Awaiting Product Owner Review | Design package | `docs/design/phase-2/BRANCH_DASHBOARD_SPEC.md`                                                  |
| Access restriction / state matrix     | Active vs deactivated company; Owner vs member; Internal vs Customer                                           | Draft — Awaiting Product Owner Review | Design package | `docs/design/phase-2/ACCESS_STATE_MATRIX.md`                                                    |
| Formal Product Owner design approval  | Per-artefact or package review sign-off                                                                        | Draft — Awaiting Product Owner Review | Product Owner  | `docs/design/phase-2/DESIGN_REVIEW_CHECKLIST.md` — sign-off fields blank until actual PO review |

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
