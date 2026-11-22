# G — Formal Product Owner Design Approval Package

**Status:** **APPROVED WITH DOCUMENTED EXCEPTIONS** — 22 November 2026  
**Package path:** `docs/design/phase-2/`  
**Gate:** PO-P2-DESIGN; formal artefact approval recorded herein  
**Instruction:** Formal design sign-off is recorded below. P2-WS-8 product UI remains **not started** and requires a **separate** Product Owner implementation authorization. Entitlement-dependent UI remains blocked (AUD-CON-006 / TB-SUB-STATE-001). P2-WS-9 remains unauthorized.

## 1. Gate distinction (mandatory)

| Checkpoint                                                                                           | Status                                                                          |
| ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Product Owner decisions approved (PO-P2-DESIGN-SWITCHER, LANDING, DEACT-VIEW, DASH, MODULES, SUB-UX) | **Done** — recorded 22 November 2026                                            |
| Design artefacts updated to reflect those decisions                                                  | **Done**                                                                        |
| Cross-document consistency verified                                                                  | **Done** — final review + this sign-off                                         |
| Final design review completed                                                                        | **Done** — `FINAL_DESIGN_REVIEW.md`                                             |
| Engineering readiness verdict                                                                        | Was **READY WITH DOCUMENTED EXCEPTIONS**                                        |
| Formal design package / per-artefact approval                                                        | **APPROVED WITH DOCUMENTED EXCEPTIONS** — 22 November 2026                      |
| PO-DEC-P2-REVIEW-001…006                                                                             | **Recorded** — see §3 and `PHASE_2_PRODUCT_OWNER_DECISIONS.md` D17–D23          |
| P2-WS-8 product UI implementation                                                                    | **Eligible for separate authorization only** — not started; not auto-authorized |
| Entitlement-dependent UI / interactions                                                              | **Blocked** — AUD-CON-006 / TB-SUB-STATE-001                                    |
| P2-WS-9 certification                                                                                | **Unauthorized** / not started                                                  |

## 2. Package contents

| Artefact ID | Name                                  | Document                      | Formal status                           |
| ----------- | ------------------------------------- | ----------------------------- | --------------------------------------- |
| A           | Owner Panel information architecture  | `OWNER_PANEL_IA.md`           | **Approved with documented exceptions** |
| B           | Branch Panel information architecture | `BRANCH_PANEL_IA.md`          | **Approved**                            |
| C           | Workspace / company selection flow    | `WORKSPACE_SELECTION_FLOW.md` | **Approved**                            |
| D           | Owner Dashboard key-screen design     | `OWNER_DASHBOARD_SPEC.md`     | **Approved with documented exceptions** |
| E           | Branch Dashboard key-screen design    | `BRANCH_DASHBOARD_SPEC.md`    | **Approved**                            |
| F           | Access restriction / state matrix     | `ACCESS_STATE_MATRIX.md`      | **Approved with documented exceptions** |
| G           | This approval checklist               | `DESIGN_REVIEW_CHECKLIST.md`  | **Approved** (process record)           |

Index: `README.md`  
Decision register: `docs/engineering/PHASE_2_PRODUCT_OWNER_DECISIONS.md` (D11–D16 baselines; D17–D23 formal approval decisions)  
Review: `FINAL_DESIGN_REVIEW.md`

## 3. Product Owner decisions recorded at formal approval

| ID                   | Topic                               | Status                                | Notes                                                                 |
| -------------------- | ----------------------------------- | ------------------------------------- | --------------------------------------------------------------------- |
| PO-DEC-P2-REVIEW-001 | Subscription entitlement matrix     | **APPROVED WITH EXCEPTION**           | High-level SUB-UX only; AUD-CON-006 / TB-SUB-STATE-001 remain Pending |
| PO-DEC-P2-REVIEW-002 | Zero-access UX (OQ-WS-02)           | **APPROVED**                          | Dedicated no-access screen for authenticated Customer with no company |
| PO-DEC-P2-REVIEW-003 | Company/branch selection (OQ-WS-03) | **APPROVED**                          | Sequential Company → Branch; server-validated                         |
| PO-DEC-P2-REVIEW-004 | Branch Settings (OQ-BP-02)          | **APPROVED**                          | Branch-scoped preferences only                                        |
| PO-DEC-P2-REVIEW-005 | Mobile Owner control (OQ-BD-03)     | **APPROVED**                          | Owner access must remain discoverable on mobile                       |
| PO-DEC-P2-REVIEW-006 | MRU persistence                     | **ACCEPT ENGINEERING RECOMMENDATION** | Server-side; revalidate; never client-authorize                       |

### Documented exceptions / unresolved dependencies (not fully approved)

| Dependency                               | Owner                  | Status       | Affects                                                                               |
| ---------------------------------------- | ---------------------- | ------------ | ------------------------------------------------------------------------------------- |
| AUD-CON-006 / TB-SUB-STATE-001           | Product Owner          | **Pending**  | Exact trial/grace/restricted/expired entitlements; entitlement-dependent interactions |
| Internal Team product UI                 | Design / Product Owner | Out of scope | Not in this package                                                                   |
| Broker / OwnFleet / Partload ops         | Later phases           | Out of scope | Module screens beyond hide/nav principles                                             |
| Undecided subscription-dependent actions | Product Owner          | **Blocked**  | Must not be inferred from high-level SUB-UX                                           |

## 4. Per-artefact review records

Status values: `Draft` | `Changes requested` | `Approved` | `Approved with documented exceptions` | `Rejected`.

### Artefact A — Owner Panel IA

| Field                          | Value                                                                                                        |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------ |
| Document                       | `docs/design/phase-2/OWNER_PANEL_IA.md`                                                                      |
| Scope covered                  | Owner boundary; unified switcher; landing; admin/billing/audit; deactivated view surface                     |
| Governing / decisions followed | PRD §§4–5/19; DEC-002; PO-P2-DEACT-COMPANY; PO-P2-DESIGN-SWITCHER; LANDING; DEACT-VIEW; PO-DEC-P2-REVIEW-006 |
| Open / excluded                | Exact trial/grace/restricted entitlements (AUD-CON-006) — **not** approved for implementation                |
| Approval status                | **Approved with documented exceptions**                                                                      |
| Review date                    | 22 November 2026                                                                                             |
| Product Owner sign-off         | Product Owner — formal instruction recorded this date                                                        |
| Notes                          | High-level subscription presentation only; no invented write capabilities                                    |

### Artefact B — Branch Panel IA

| Field                          | Value                                                                                                 |
| ------------------------------ | ----------------------------------------------------------------------------------------------------- |
| Document                       | `docs/design/phase-2/BRANCH_PANEL_IA.md`                                                              |
| Scope covered                  | Branch chrome; Design.md §2 groups; hide undeveloped modules; unified switcher; return to Owner Panel |
| Governing / decisions followed | Design.md §2; TB-OWN-007/008; PO-P2-DESIGN-SWITCHER; MODULES; DASH; PO-DEC-P2-REVIEW-004; 005         |
| Open / excluded                | Operational Broker/OwnFleet/Partload screens — Out of scope                                           |
| Approval status                | **Approved**                                                                                          |
| Review date                    | 22 November 2026                                                                                      |
| Product Owner sign-off         | Product Owner — formal instruction recorded this date                                                 |
| Notes                          | Branch Settings = branch-scoped preferences only                                                      |

### Artefact C — Workspace selection flow

| Field                          | Value                                                                              |
| ------------------------------ | ---------------------------------------------------------------------------------- |
| Document                       | `docs/design/phase-2/WORKSPACE_SELECTION_FLOW.md`                                  |
| Scope covered                  | Journeys; unified switcher; landing; zero-access; sequential selection             |
| Governing / decisions followed | PRD §5; TB-SEC-002; PO-P2-DESIGN-SWITCHER; LANDING; PO-DEC-P2-REVIEW-002; 003; 006 |
| Open / excluded                | Internal Team workspace routing — Out of scope                                     |
| Approval status                | **Approved**                                                                       |
| Review date                    | 22 November 2026                                                                   |
| Product Owner sign-off         | Product Owner — formal instruction recorded this date                              |
| Notes                          | Dedicated no-access screen; Company → Branch sequential; server MRU                |

### Artefact D — Owner Dashboard

| Field                          | Value                                                                                 |
| ------------------------------ | ------------------------------------------------------------------------------------- |
| Document                       | `docs/design/phase-2/OWNER_DASHBOARD_SPEC.md`                                         |
| Scope covered                  | Approved admin/billing regions only; no charts/KPIs                                   |
| Governing / decisions followed | Design.md §§1/3/8/13; PO-P2-DESIGN-DASH; DEACT-VIEW; SUB-UX; PO-DEC-P2-REVIEW-001     |
| Open / excluded                | Exact entitlement chrome (AUD-CON-006) — **Pending**; not approved for implementation |
| Approval status                | **Approved with documented exceptions**                                               |
| Review date                    | 22 November 2026                                                                      |
| Product Owner sign-off         | Product Owner — formal instruction recorded this date                                 |
| Notes                          | Do not invent trial/grace/restricted permissions or actions                           |

### Artefact E — Branch Dashboard

| Field                          | Value                                                                   |
| ------------------------------ | ----------------------------------------------------------------------- |
| Document                       | `docs/design/phase-2/BRANCH_DASHBOARD_SPEC.md`                          |
| Scope covered                  | Context + available module shortcuts; hide undeveloped modules; no KPIs |
| Governing / decisions followed | PO-P2-DESIGN-DASH; MODULES; PO-DEC-P2-REVIEW-005                        |
| Open / excluded                | Module operational screens — Out of scope                               |
| Approval status                | **Approved**                                                            |
| Review date                    | 22 November 2026                                                        |
| Product Owner sign-off         | Product Owner — formal instruction recorded this date                   |
| Notes                          | Owner control must remain discoverable on mobile                        |

### Artefact F — Access state matrix

| Field                          | Value                                                                                                       |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------- |
| Document                       | `docs/design/phase-2/ACCESS_STATE_MATRIX.md`                                                                |
| Scope covered                  | Owner/member/Internal/multi-company/deactivation/transfer/file/session; unified switcher; high-level SUB-UX |
| Governing / decisions followed | DEC-002; PO-P2-DEACT-_; FILE-_; DESIGN-SWITCHER; LANDING; DEACT-VIEW; SUB-UX; PO-DEC-P2-REVIEW-001          |
| Open / excluded                | Exact trial/grace/restricted entitlement cells — **Pending** (AUD-CON-006); not marked fully approved       |
| Approval status                | **Approved with documented exceptions**                                                                     |
| Review date                    | 22 November 2026                                                                                            |
| Product Owner sign-off         | Product Owner — formal instruction recorded this date                                                       |
| Notes                          | Suspended/deactivated rows remain approved; restricted exact permissions do not                             |

### Artefact G — Checklist process

| Field                          | Value                                                              |
| ------------------------------ | ------------------------------------------------------------------ |
| Document                       | `docs/design/phase-2/DESIGN_REVIEW_CHECKLIST.md`                   |
| Scope covered                  | Formal approval record; baselines vs formal approval distinguished |
| Governing / decisions followed | Design.md §14; PO-P2-DESIGN; D11–D23                               |
| Open / excluded                | P2-WS-8 implementation still requires separate PO authorization    |
| Approval status                | **Approved** (process / gate record)                               |
| Review date                    | 22 November 2026                                                   |
| Product Owner sign-off         | Product Owner — formal instruction recorded this date              |
| Notes                          | This document records formal design approval with exceptions       |

## 5. Package-level gate statement

| Item                            | Status                                                                                                                                                                                                         |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Baseline PO decisions (D11–D16) | **APPROVED**                                                                                                                                                                                                   |
| Formal design approval          | **APPROVED WITH DOCUMENTED EXCEPTIONS** — 22 November 2026                                                                                                                                                     |
| Approval scope                  | Shared layouts; unified switcher; Owner/Member nav; company/branch selection; landing; Owner/Branch Settings boundaries; admin/context dashboards; module nav principles; high-level subscription presentation |
| Explicitly excluded             | Exact trial/grace/restricted entitlements; undecided subscription-dependent actions; Internal Team UI; Broker/OwnFleet/Partload ops outside Phase 2                                                            |
| P2-WS-8 product UI              | **Eligible for separate implementation authorization** — **not started**; not auto-authorized                                                                                                                  |
| Entitlement-dependent work      | **Blocked**                                                                                                                                                                                                    |
| P2-WS-9 certification           | **Unauthorized**                                                                                                                                                                                               |
| Backend non-UI work             | May continue under Phase 2 plan without these screens                                                                                                                                                          |

## 6. Sign-off summary (package)

| Field                     | Value                                            |
| ------------------------- | ------------------------------------------------ |
| Package review date       | 22 November 2026                                 |
| Product Owner             | Product Owner                                    |
| Outcome                   | **Approve all (A–G) with documented exceptions** |
| Approved artefact IDs     | A–G (A, D, F with exceptions as noted)           |
| Signature / initials      | Product Owner formal instruction (this session)  |
| Formal design approval ID | **PO-P2-DESIGN-FORMAL**                          |

---

_Engineering must not start P2-WS-8 product UI until a separate Product Owner implementation authorization is recorded. Formal design approval with exceptions does not authorize entitlement-dependent interactions or P2-WS-9._
