# Phase 0 Design Deliverables Register

**Status:** Active register (Phase 0 governing baseline approved 21 November 2026; design artefacts themselves remain Not Started)  
**Date:** 2026-11-21 (consistency correction); Phase 0 approval recorded 21 November 2026  
**Authority:** Design.md §14; Phases.md §§0–1; TB-DES-002; PO-APPROVE-001  
**Rule:** Do not mark Completed unless the artefact exists and has been reviewed.

**Phase 0 closure:** Formal approval does **not** complete these artefacts. They remain required under the Design Deliverables Gate before product-specific Phase 1 UI. Phase 1 technical UI shell still requires separate Product Owner Phase 1 authorization.

## Gate clarification (aligned across Design.md and Phases.md)

| Gate                                                | Mandatory for Phase 0 formal approval?              | Mandatory before product-specific UI in Phase 1?                    |
| --------------------------------------------------- | --------------------------------------------------- | ------------------------------------------------------------------- |
| Six governing documents approved                    | **Yes**                                             | Yes (DEC-004)                                                       |
| Design deliverables register exists and is accurate | **Yes** (register must be maintained)               | Yes                                                                 |
| Design artefacts themselves Completed/reviewed      | **No** — may remain Not Started at Phase 0 approval | **Yes** — Design Deliverables Gate for the relevant product screens |

**Phase 1 technical UI shell** (layouts, tokens, non-product scaffolding, auth surfaces aligned to DEC-006/009) may proceed after Phase 0 formal approval without completed product wireframes.  
**Product-specific screens** must wait for reviewed artefacts for those screens.

---

| Deliverable                                      | Description                                                          | Status      | Owner              | Notes                                                             |
| ------------------------------------------------ | -------------------------------------------------------------------- | ----------- | ------------------ | ----------------------------------------------------------------- |
| Product navigation map                           | Top-level routes across Owner, Branch and Internal surfaces          | Not Started | Pending assignment | Design Deliverables Gate for product navigation UI                |
| Owner Panel information architecture             | Company switcher, settings, billing, users, audit IA                 | Not Started | Pending assignment | Must reflect DEC-002; action inventory still Pending              |
| Branch Panel information architecture            | Operations, masters, accounting, assets, notifications               | Not Started | Pending assignment | Hide inaccessible items; server auth authoritative                |
| Internal Team interface information architecture | Internal CRM/admin surfaces                                          | Not Started | Pending assignment | Support module phase placement still Deferred (AUD-GAP-010)       |
| Wireframes                                       | Critical workflows (login, Owner/Branch entry, enquiry, LR, billing) | Not Started | Pending assignment | Required before corresponding product screens                     |
| Field specifications                             | Field-level specs aligned to PRD/Rules                               | Not Started | Pending assignment | Expand from governing docs; do not invent                         |
| Reusable component catalogue                     | Buttons, forms, tables, remark dialogs, status                       | Not Started | Pending assignment | —                                                                 |
| Design tokens                                    | Colour, spacing, elevation matching brand palette                    | Not Started | Pending assignment | Palette in Design §1; token plumbing may start in technical shell |
| Typography                                       | Poppins as product UI font (DEC-007)                                 | Not Started | Pending assignment | Requirement decided; artefact not yet produced                    |
| Responsive behavior                              | Desktop/tablet + critical mobile rules                               | Not Started | Pending assignment | Design §13                                                        |
| Accessibility requirements                       | Keyboard, focus, contrast, non-colour status                         | Not Started | Pending assignment | Design §13                                                        |
| Design Guideline PDF in repository               | Source brand PDF availability                                        | Not Started | Pending assignment | AUD-GOV-004 — PDF not present under docs/governing                |
| Design review status                             | Formal design review against artefacts                               | Not Started | Product owner      | Cannot complete until artefacts exist                             |

### Phase 2 product surfaces (cross-reference)

PO-P2-DESIGN (22 November 2026) **approves the gate requirement** to prepare and review IA/key screens for Owner Panel, Branch Panel, workspace selection, Owner Dashboard and Branch Dashboard before product UI. Artefacts remain **Not Started**. Detailed Phase 2 register: `docs/engineering/PHASE_2_DESIGN_DELIVERABLES.md`. Do not treat gate-requirement approval as screen-design approval.

## Related documentation

- `docs/governing/Design.md` §14
- `docs/governing/Phases.md` Phase 0 / Phase 1 / Phase 2
- `docs/engineering/PHASE_2_DESIGN_DELIVERABLES.md`
- `docs/testing/TEST_STRATEGY.md` (frontend/Playwright layers)
- Traceability: TB-DES-001, TB-DES-002
