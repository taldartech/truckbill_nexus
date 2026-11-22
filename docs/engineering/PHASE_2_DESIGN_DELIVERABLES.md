# Phase 2 Design Deliverables Register

**Status:** Active — gate requirement **APPROVED** (PO-P2-DESIGN, 22 November 2026); artefacts themselves **Not Started**  
**Authority:** Design.md §14; Phases.md Phase 2; TB-DES-002; `PHASE_2_AUTHORIZATION_RECORD.md`  
**Rule:** Do not implement Owner Panel, Branch Panel, workspace/company selection, Owner Dashboard or Branch Dashboard **product UI** until the relevant artefact is Completed and Product Owner–reviewed. Backend and non-UI technical work may proceed.

**Important:** Product Owner approval of the Design Deliverables **requirement** is **not** approval of any particular screen design. No fabricated dashboard metrics or unapproved workflows.

---

## Gate summary

| Item                                   | Status                                                  |
| -------------------------------------- | ------------------------------------------------------- |
| PO-P2-DESIGN gate requirement          | **APPROVED** 22 November 2026                           |
| Artefact production                    | **Not Started**                                         |
| Formal design review / screen approval | **Not Started** — cannot complete until artefacts exist |
| P2-WS-8 product UI implementation      | **Blocked** until per-screen approval recorded here     |

---

## Required artefacts (Phase 2 product surfaces)

| Deliverable                           | Description                                                                                                    | Status      | Owner              | Notes                                                                                    |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------- | ----------- | ------------------ | ---------------------------------------------------------------------------------------- |
| Owner Panel information architecture  | Company admin, billing visibility, users/memberships entry points; DEC-002 deactivated-company limited surface | Not Started | Pending assignment | Align to TB-OWN-002–005; actions beyond admin/billing info remain out of invention scope |
| Branch Panel information architecture | Branch operations navigation within membership/role constraints                                                | Not Started | Pending assignment | Server auth authoritative                                                                |
| Workspace / company selection flow    | Multi-company switcher; active company context resolution UX                                                   | Not Started | Pending assignment | Must not imply client-trusted company_id                                                 |
| Owner Dashboard key screens           | Light-first B2B; Poppins; `#2FB36F`→`#2D8CFF`; no fabricated metrics                                           | Not Started | Pending assignment | Placeholders only after approval                                                         |
| Branch Dashboard key screens          | Same brand rules; membership-scoped                                                                            | Not Started | Pending assignment | Placeholders only after approval                                                         |
| Access restriction / state matrix     | Active vs deactivated company; Owner vs member; Internal vs Customer                                           | Not Started | Pending assignment | Include DEC-002 / PO-P2-DEACT-*                                                          |
| Formal Product Owner design approval  | Per-artefact or package review sign-off                                                                        | Not Started | Product Owner      | Record date/initials when approved                                                       |

## Brand constraints (already decided)

- Light-first; avoid dark page backgrounds
- Poppins (DEC-007 / TB-DES-001)
- Gradient `#2FB36F` → `#2D8CFF`
- Professional B2B SaaS; accessible forms/tables/filters

## Related documentation

- `docs/implementation/PHASE_0_DELIVERABLES.md` (broader register; remains authoritative for global design inventory)
- `docs/governing/Design.md` §14
- `docs/implementation/PHASE_2_EXECUTION_PLAN.md` P2-WS-8
- `docs/engineering/PHASE_2_AUTHORIZATION_RECORD.md`
