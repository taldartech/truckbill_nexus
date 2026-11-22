# G — Formal Product Owner Design Approval Package

**Status:** Draft — Awaiting Product Owner Review  
**Package path:** `docs/design/phase-2/`  
**Gate:** PO-P2-DESIGN requires artefact review before P2-WS-8 product UI  
**Instruction:** Do **not** mark any artefact Approved until the Product Owner completes review. Engineering must not start product UI from Draft status.

## 1. Package contents

| Artefact ID | Name                                  | Document                      | Draft date       |
| ----------- | ------------------------------------- | ----------------------------- | ---------------- |
| A           | Owner Panel information architecture  | `OWNER_PANEL_IA.md`           | 22 November 2026 |
| B           | Branch Panel information architecture | `BRANCH_PANEL_IA.md`          | 22 November 2026 |
| C           | Workspace / company selection flow    | `WORKSPACE_SELECTION_FLOW.md` | 22 November 2026 |
| D           | Owner Dashboard key-screen design     | `OWNER_DASHBOARD_SPEC.md`     | 22 November 2026 |
| E           | Branch Dashboard key-screen design    | `BRANCH_DASHBOARD_SPEC.md`    | 22 November 2026 |
| F           | Access restriction / state matrix     | `ACCESS_STATE_MATRIX.md`      | 22 November 2026 |
| G           | This approval checklist               | `DESIGN_REVIEW_CHECKLIST.md`  | 22 November 2026 |

Index: `README.md`

## 2. Per-artefact review records

Complete one block per artefact. Status values: `Draft` | `Changes requested` | `Approved` | `Rejected`.

### Artefact A — Owner Panel IA

| Field                                 | Value                                                                                                          |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Document                              | `docs/design/phase-2/OWNER_PANEL_IA.md`                                                                        |
| Scope covered                         | Owner boundary, nav hierarchy, switcher, admin/billing/audit entry points, deactivated company limited surface |
| Governing requirements followed       | PRD §§4–5, 19; Rules §2; Design.md §§2, 8; DEC-002; PO-P2-DEACT-COMPANY; TB-OWN-001–005                        |
| Open questions                        | OQ-OP-01 … OQ-OP-04                                                                                            |
| Proposed decisions requiring approval | Switcher membership mix; default owned company; deactivated action list remains Pending (no invention)         |
| Approval status                       | Draft — Awaiting Product Owner Review                                                                          |
| Review date                           | _YYYY-MM-DD_                                                                                                   |
| Product Owner sign-off                | _Name / initials_                                                                                              |
| Notes                                 |                                                                                                                |

### Artefact B — Branch Panel IA

| Field                                 | Value                                                                                         |
| ------------------------------------- | --------------------------------------------------------------------------------------------- |
| Document                              | `docs/design/phase-2/BRANCH_PANEL_IA.md`                                                      |
| Scope covered                         | Branch chrome, Design.md §2 nav groups, role visibility, switch/return, empty/denied states   |
| Governing requirements followed       | Design.md §2; PRD §§5–6; TB-OWN-007/008; DEC-002; DEC-005 (Partload expense UI out)           |
| Open questions                        | OQ-BP-01 … OQ-BP-03                                                                           |
| Proposed decisions requiring approval | Company switcher location; hide vs “coming later” for undeveloped modules; optional role hint |
| Approval status                       | Draft — Awaiting Product Owner Review                                                         |
| Review date                           | _YYYY-MM-DD_                                                                                  |
| Product Owner sign-off                | _Name / initials_                                                                             |
| Notes                                 |                                                                                               |

### Artefact C — Workspace selection flow

| Field                                 | Value                                                                                          |
| ------------------------------------- | ---------------------------------------------------------------------------------------------- |
| Document                              | `docs/design/phase-2/WORKSPACE_SELECTION_FLOW.md`                                              |
| Scope covered                         | Ten post-login journeys; security rule against client tenant trust; ownership transfer effects |
| Governing requirements followed       | PRD §§3–5; ADR-002; TB-SEC-002; DEC-002; PO-P2-DEACT-*; PO-P2-FILE-MULTI; P2-WS-7              |
| Open questions                        | OQ-WS-01 … OQ-WS-03                                                                            |
| Proposed decisions requiring approval | Combined vs split company/branch selection; zero-access screen vs logout                       |
| Approval status                       | Draft — Awaiting Product Owner Review                                                          |
| Review date                           | _YYYY-MM-DD_                                                                                   |
| Product Owner sign-off                | _Name / initials_                                                                              |
| Notes                                 |                                                                                                |

### Artefact D — Owner Dashboard

| Field                                 | Value                                                                             |
| ------------------------------------- | --------------------------------------------------------------------------------- |
| Document                              | `docs/design/phase-2/OWNER_DASHBOARD_SPEC.md`                                     |
| Scope covered                         | Structure, regions, deactivated variant, responsive/a11y; no fabricated metrics   |
| Governing requirements followed       | Design.md §§1–3, 8, 13; DEC-007; DEC-002                                          |
| Open questions                        | OQ-OD-01 … OQ-OD-03                                                               |
| Proposed decisions requiring approval | Any widget beyond admin shortcuts + subscription visibility; recent activity feed |
| Approval status                       | Draft — Awaiting Product Owner Review                                             |
| Review date                           | _YYYY-MM-DD_                                                                      |
| Product Owner sign-off                | _Name / initials_                                                                 |
| Notes                                 |                                                                                   |

### Artefact E — Branch Dashboard

| Field                                 | Value                                                           |
| ------------------------------------- | --------------------------------------------------------------- |
| Document                              | `docs/design/phase-2/BRANCH_DASHBOARD_SPEC.md`                  |
| Scope covered                         | Branch chrome, module launch groups, placeholders for summaries |
| Governing requirements followed       | Design.md §§1–3, 13; PRD §5; DEC-002                            |
| Open questions                        | OQ-BD-01 … OQ-BD-03                                             |
| Proposed decisions requiring approval | Which summary widgets (if any) for Phase 2                      |
| Approval status                       | Draft — Awaiting Product Owner Review                           |
| Review date                           | _YYYY-MM-DD_                                                    |
| Product Owner sign-off                | _Name / initials_                                               |
| Notes                                 |                                                                 |

### Artefact F — Access state matrix

| Field                                 | Value                                                                             |
| ------------------------------------- | --------------------------------------------------------------------------------- |
| Document                              | `docs/design/phase-2/ACCESS_STATE_MATRIX.md`                                      |
| Scope covered                         | Owner/member/Internal/multi-company/deactivation/transfer/file/session matrix     |
| Governing requirements followed       | DEC-002; PO-P2-DEACT-_; PO-P2-FILE-_; TB-OWN-_; TB-SEC-_; TB-DEL-002; P2-WS-4/6/7 |
| Open questions                        | OQ-AX-01 … OQ-AX-02; commercial entitlement matrix Pending                        |
| Proposed decisions requiring approval | None beyond resolving Pending entitlement UX (AUD-CON-006)                        |
| Approval status                       | Draft — Awaiting Product Owner Review                                             |
| Review date                           | _YYYY-MM-DD_                                                                      |
| Product Owner sign-off                | _Name / initials_                                                                 |
| Notes                                 |                                                                                   |

### Artefact G — Checklist process

| Field                                 | Value                                                                                |
| ------------------------------------- | ------------------------------------------------------------------------------------ |
| Document                              | `docs/design/phase-2/DESIGN_REVIEW_CHECKLIST.md`                                     |
| Scope covered                         | Independent per-artefact approval workflow                                           |
| Governing requirements followed       | Design.md §14; PO-P2-DESIGN; PHASE_2_DESIGN_DELIVERABLES.md                          |
| Open questions                        | Whether package approval may proceed artefact-by-artefact for partial P2-WS-8 slices |
| Proposed decisions requiring approval | Partial implementation authorization model                                           |
| Approval status                       | Draft — Awaiting Product Owner Review                                                |
| Review date                           | _YYYY-MM-DD_                                                                         |
| Product Owner sign-off                | _Name / initials_                                                                    |
| Notes                                 |                                                                                      |

## 3. Consolidated open decisions for Product Owner

| ID                  | Decision needed                                                    | Blocks                   |
| ------------------- | ------------------------------------------------------------------ | ------------------------ |
| OQ-OP-01 / OQ-AX-02 | Company switcher: owned-only vs owned+member                       | Owner IA, workspace flow |
| OQ-OP-02 / OQ-WS-01 | Default company when Owner owns many                               | Landing                  |
| OQ-OP-03 / OQ-OD-02 | Exhaustive deactivated-company Owner Panel actions                 | Limited Owner Panel      |
| OQ-OP-04 / OQ-OD-03 | Owner Dashboard vs companies-list home                             | Owner Dashboard          |
| OQ-BP-01            | Company switcher inside Branch Panel?                              | Branch chrome            |
| OQ-BP-03 / OQ-BD-02 | Hide vs “coming later” for undeveloped modules                     | Branch nav/dashboard     |
| OQ-OD-01 / OQ-BD-01 | Any Phase 2 dashboard widgets beyond placeholders?                 | Dashboards               |
| OQ-WS-02            | Zero-access UX                                                     | Workspace flow           |
| OQ-WS-03            | Combined company+branch selection screen?                          | Workspace flow           |
| AUD-CON-006         | Trial/grace/restricted/suspended/deactivated entitlement UX matrix | Subscription chrome      |

## 4. Package-level gate statement

| Item                              | Status                                                |
| --------------------------------- | ----------------------------------------------------- |
| Artefacts produced                | Yes — Draft                                           |
| Formal PO screen/IA approval      | **Not Approved** — awaiting review                    |
| P2-WS-8 product UI implementation | **Blocked**                                           |
| Backend non-UI work               | May continue under Phase 2 plan without these screens |

## 5. Sign-off summary (package)

| Field                             | Value                                                       |
| --------------------------------- | ----------------------------------------------------------- |
| Package review date               | _YYYY-MM-DD_                                                |
| Product Owner                     | _Name_                                                      |
| Outcome                           | _Approve all / Approve subset / Changes requested / Reject_ |
| Approved artefact IDs (if subset) | _A–G_                                                       |
| Signature / initials              |                                                             |

---

_Engineering must update `PHASE_2_DESIGN_DELIVERABLES.md` to Approved only after this checklist records actual Product Owner approval._
