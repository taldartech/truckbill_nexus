# G — Formal Product Owner Design Approval Package

**Status:** Draft — Awaiting Formal Design Package Approval  
**Package path:** `docs/design/phase-2/`  
**Gate:** PO-P2-DESIGN requires artefact review before P2-WS-8 product UI  
**Instruction:** Baseline workspace decisions (D11–D16) are **APPROVED**. Formal approval of each design artefact is still required before product UI. Do **not** mark artefacts Approved until the Product Owner completes this checklist. Engineering must not start product UI from Draft status alone.

## 1. Gate distinction (mandatory)

| Checkpoint                                                                                           | Status                                     |
| ---------------------------------------------------------------------------------------------------- | ------------------------------------------ |
| Product Owner decisions approved (PO-P2-DESIGN-SWITCHER, LANDING, DEACT-VIEW, DASH, MODULES, SUB-UX) | **Done** — recorded 22 November 2026       |
| Design artefacts updated to reflect those decisions                                                  | **Done** — this package revision           |
| Cross-document consistency verified (switcher, Owner vs Member, deactivation distinctions)           | **Done** — engineering consistency pass    |
| Remaining Pending decisions documented                                                               | **Yes** — see §3                           |
| Formal design package / per-artefact approval                                                        | **Still required**                         |
| P2-WS-8 product UI implementation                                                                    | **Blocked** until formal artefact approval |
| P2-WS-9 certification                                                                                | **Not complete** / not started             |

## 2. Package contents

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
Decision register: `docs/engineering/PHASE_2_PRODUCT_OWNER_DECISIONS.md` (D11–D16)

## 3. Remaining Pending decisions (not invented)

| ID                                        | Decision needed                                                          | Blocks                                                             |
| ----------------------------------------- | ------------------------------------------------------------------------ | ------------------------------------------------------------------ |
| AUD-CON-006 / TB-SUB-STATE-001 / OQ-AX-01 | Exact trial, grace, and restricted entitlement permissions               | Detailed subscription chrome beyond PO-P2-DESIGN-SUB-UX principles |
| OQ-WS-02                                  | Zero-access screen vs immediate logout                                   | Edge UX only                                                       |
| OQ-WS-03                                  | Combined vs split company/branch selection screen                        | Selection UI layout                                                |
| OQ-BP-02                                  | Branch Settings: prefs only vs limited company read-only links for Owner | Settings group                                                     |
| OQ-BD-03                                  | Owner Panel control on mobile sticky header                              | Mobile chrome (optional)                                           |
| —                                         | Persistence mechanism for MRU owned company                              | Implementation detail; behaviour rule already approved             |

## 4. Per-artefact review records

Complete one block per artefact. Status values: `Draft` | `Changes requested` | `Approved` | `Rejected`.

### Artefact A — Owner Panel IA

| Field                          | Value                                                                                    |
| ------------------------------ | ---------------------------------------------------------------------------------------- |
| Document                       | `docs/design/phase-2/OWNER_PANEL_IA.md`                                                  |
| Scope covered                  | Owner boundary; unified switcher; landing; admin/billing/audit; deactivated view surface |
| Governing / decisions followed | PRD §§4–5/19; DEC-002; PO-P2-DEACT-COMPANY; PO-P2-DESIGN-SWITCHER; LANDING; DEACT-VIEW   |
| Open questions                 | AUD-CON-006; MRU persistence implementation                                              |
| Approval status                | Draft — Awaiting Formal Design Approval                                                  |
| Review date                    | _YYYY-MM-DD_                                                                             |
| Product Owner sign-off         | _Name / initials_                                                                        |
| Notes                          |                                                                                          |

### Artefact B — Branch Panel IA

| Field                          | Value                                                                                                 |
| ------------------------------ | ----------------------------------------------------------------------------------------------------- |
| Document                       | `docs/design/phase-2/BRANCH_PANEL_IA.md`                                                              |
| Scope covered                  | Branch chrome; Design.md §2 groups; hide undeveloped modules; unified switcher; return to Owner Panel |
| Governing / decisions followed | Design.md §2; TB-OWN-007/008; PO-P2-DESIGN-SWITCHER; MODULES; DASH                                    |
| Open questions                 | OQ-BP-02                                                                                              |
| Approval status                | Draft — Awaiting Formal Design Approval                                                               |
| Review date                    | _YYYY-MM-DD_                                                                                          |
| Product Owner sign-off         | _Name / initials_                                                                                     |
| Notes                          |                                                                                                       |

### Artefact C — Workspace selection flow

| Field                          | Value                                                                         |
| ------------------------------ | ----------------------------------------------------------------------------- |
| Document                       | `docs/design/phase-2/WORKSPACE_SELECTION_FLOW.md`                             |
| Scope covered                  | Ten journeys; unified switcher; landing; security against client tenant trust |
| Governing / decisions followed | PRD §5; TB-SEC-002; PO-P2-DESIGN-SWITCHER; LANDING; P2-WS-7                   |
| Open questions                 | OQ-WS-02; OQ-WS-03                                                            |
| Approval status                | Draft — Awaiting Formal Design Approval                                       |
| Review date                    | _YYYY-MM-DD_                                                                  |
| Product Owner sign-off         | _Name / initials_                                                             |
| Notes                          |                                                                               |

### Artefact D — Owner Dashboard

| Field                          | Value                                                       |
| ------------------------------ | ----------------------------------------------------------- |
| Document                       | `docs/design/phase-2/OWNER_DASHBOARD_SPEC.md`               |
| Scope covered                  | Approved admin/billing regions only; no charts/KPIs         |
| Governing / decisions followed | Design.md §§1/3/8/13; PO-P2-DESIGN-DASH; DEACT-VIEW; SUB-UX |
| Open questions                 | AUD-CON-006                                                 |
| Approval status                | Draft — Awaiting Formal Design Approval                     |
| Review date                    | _YYYY-MM-DD_                                                |
| Product Owner sign-off         | _Name / initials_                                           |
| Notes                          |                                                             |

### Artefact E — Branch Dashboard

| Field                          | Value                                                                   |
| ------------------------------ | ----------------------------------------------------------------------- |
| Document                       | `docs/design/phase-2/BRANCH_DASHBOARD_SPEC.md`                          |
| Scope covered                  | Context + available module shortcuts; hide undeveloped modules; no KPIs |
| Governing / decisions followed | PO-P2-DESIGN-DASH; MODULES                                              |
| Open questions                 | OQ-BD-03                                                                |
| Approval status                | Draft — Awaiting Formal Design Approval                                 |
| Review date                    | _YYYY-MM-DD_                                                            |
| Product Owner sign-off         | _Name / initials_                                                       |
| Notes                          |                                                                         |

### Artefact F — Access state matrix

| Field                          | Value                                                                                                   |
| ------------------------------ | ------------------------------------------------------------------------------------------------------- |
| Document                       | `docs/design/phase-2/ACCESS_STATE_MATRIX.md`                                                            |
| Scope covered                  | Owner/member/Internal/multi-company/deactivation/transfer/file/session; unified switcher row consistent |
| Governing / decisions followed | DEC-002; PO-P2-DEACT-_; FILE-_; DESIGN-SWITCHER; LANDING; DEACT-VIEW; SUB-UX                            |
| Open questions                 | AUD-CON-006                                                                                             |
| Approval status                | Draft — Awaiting Formal Design Approval                                                                 |
| Review date                    | _YYYY-MM-DD_                                                                                            |
| Product Owner sign-off         | _Name / initials_                                                                                       |
| Notes                          |                                                                                                         |

### Artefact G — Checklist process

| Field                          | Value                                                               |
| ------------------------------ | ------------------------------------------------------------------- |
| Document                       | `docs/design/phase-2/DESIGN_REVIEW_CHECKLIST.md`                    |
| Scope covered                  | Distinguishes baseline decisions vs formal artefact approval        |
| Governing / decisions followed | Design.md §14; PO-P2-DESIGN; D11–D16                                |
| Open questions                 | Whether partial artefact approval may unlock partial P2-WS-8 slices |
| Approval status                | Draft — Awaiting Formal Design Approval                             |
| Review date                    | _YYYY-MM-DD_                                                        |
| Product Owner sign-off         | _Name / initials_                                                   |
| Notes                          |                                                                     |

## 5. Package-level gate statement

| Item                            | Status                                                |
| ------------------------------- | ----------------------------------------------------- |
| Baseline PO decisions (D11–D16) | **APPROVED**                                          |
| Artefacts produced / updated    | Yes — Draft reflecting D11–D16                        |
| Formal PO artefact approval     | **Not Approved** — awaiting sign-off below            |
| P2-WS-8 product UI              | **Blocked**                                           |
| P2-WS-9 certification           | **Not complete**                                      |
| Backend non-UI work             | May continue under Phase 2 plan without these screens |

## 6. Sign-off summary (package)

| Field                             | Value                                                       |
| --------------------------------- | ----------------------------------------------------------- |
| Package review date               | _YYYY-MM-DD_                                                |
| Product Owner                     | _Name_                                                      |
| Outcome                           | _Approve all / Approve subset / Changes requested / Reject_ |
| Approved artefact IDs (if subset) | _A–G_                                                       |
| Signature / initials              |                                                             |

---

_Engineering must update `PHASE_2_DESIGN_DELIVERABLES.md` to Approved only after this checklist records actual Product Owner formal approval. Baseline decision approval alone does not unlock product UI._
