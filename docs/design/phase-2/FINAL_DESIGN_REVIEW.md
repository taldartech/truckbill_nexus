# Phase 2 Design Package — Final Design Review

**Review date:** 22 November 2026  
**Baseline HEAD reviewed:** `cb68ec14a2ebde77d56fb40ca3b3908b1cbafcdc`  
**Roles:** Principal Software Architect; Senior UI/UX Architect; Product Business Analyst; QA & Compliance Lead; Engineering Governance Reviewer  
**Scope:** Design review and documentation alignment only. **No** product UI implementation. **No** formal design approval granted by this document.

---

## 1. Verdict

### **READY WITH DOCUMENTED EXCEPTIONS**

The Phase 2 Customer Owner/Branch panel design package (artefacts A–G) is consistent with approved Product Owner baselines **PO-P2-DESIGN-SWITCHER**, **LANDING**, **DEACT-VIEW**, **DASH**, **MODULES**, and **SUB-UX**, and with governing Owner/membership/security rules.

Formal Product Owner sign-off on A–G may proceed **if** the Product Owner accepts the documented exceptions in §5. This review does **not** mark any artefact Approved and does **not** authorize P2-WS-8 product UI or P2-WS-9 certification.

### Why not “READY FOR FORMAL APPROVAL” (unconditional)?

| Blocking-class exception           | Why it matters                                                                                                                                                                                                                      |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **AUD-CON-006 / TB-SUB-STATE-001** | PRD §19 still Pending a consolidated trial/grace/restricted/suspended/deactivated UX matrix. High-level principles are approved (PO-P2-DESIGN-SUB-UX); exact trial/grace/restricted permissions must not be invented in Phase 2 UI. |

### Why not “NOT READY”?

- Unified switcher, landing, deactivated-company surface, dashboard limits, and module-hide rules are approved and cross-document consistent.
- Remaining open items (OQ-WS-02/03, OQ-BP-02, OQ-BD-03, MRU persistence) are non-blocking edge UX or engineering detail.
- No contradiction found that overturns D11–D16 or DEC-002 / TB-SEC-002.

---

## 2. Repository state at review

| Item                    | Value                                                           |
| ----------------------- | --------------------------------------------------------------- |
| Branch                  | `main`                                                          |
| HEAD                    | `cb68ec14a2ebde77d56fb40ca3b3908b1cbafcdc`                      |
| Upstream                | In sync with `origin/main` (0 ahead / 0 behind at review start) |
| Working tree            | Clean at review start                                           |
| Design baseline commits | `353e80e` (decisions) + `cb68ec1` (SHA record)                  |

---

## 3. Documents reviewed

| Area                                   | Documents                                                                                                                                                                                   |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Design package                         | `docs/design/phase-2/*` (README, A–G)                                                                                                                                                       |
| PO decisions                           | `PHASE_2_PRODUCT_OWNER_DECISIONS.md` D11–D16; `PHASE_2_AUTHORIZATION_RECORD.md`                                                                                                             |
| Execution / gate                       | `PHASE_2_EXECUTION_PLAN.md` P2-WS-8; `PHASE_2_DESIGN_DELIVERABLES.md`                                                                                                                       |
| Governing                              | PRD §§3–5, 19; Rules §§2, 15; Design.md §§1–3, 8–9, 13–14; Architecture tenancy/security; Memory                                                                                            |
| Traceability                           | `Requirements-Traceability-Starter.md` TB-OWN-_, TB-SEC-_, TB-SUB-STATE-001, AUD-CON-006                                                                                                    |
| Implemented behaviour (reference only) | Company Owner designation, memberships, deactivation scopes, session version, ownership transfer, RejectClientTenantIdentifiers — Phase 2 backend already present; **not** product panel UI |

---

## 4. Design consistency audit

### 4.1 Authentication and initial landing

| Journey                                               | Package coverage                          | Consistency                                                                           |
| ----------------------------------------------------- | ----------------------------------------- | ------------------------------------------------------------------------------------- |
| Customer login → Owner (1 company)                    | WORKSPACE + LANDING                       | Consistent                                                                            |
| Customer login → Owner (many) → MRU / owned selection | WORKSPACE + LANDING                       | Consistent; MRU storage = engineering detail                                          |
| Customer member-only → Branch path                    | SWITCHER + WORKSPACE                      | Consistent                                                                            |
| Owned + member companies                              | Unified switcher Owner vs Member          | Consistent                                                                            |
| No accessible companies                               | 4.10 + OQ-WS-02 Pending                   | Documented; edge UX Pending                                                           |
| Deactivated Owner identity                            | No login                                  | Consistent with PO-P2-DEACT-OWNER                                                     |
| Internal Team login landing                           | **Out of Phase 2 Customer panel package** | Documented exception (§5.2); Internal Team IA remains Not Started in Phase 0 register |

Logout / session fail-closed: referenced via P2-WS-4 session version behaviour in workspace error states — adequate for design handoff; not a product screen invent.

### 4.2 Unified company switcher

Aligned across OWNER_PANEL_IA, BRANCH_PANEL_IA, WORKSPACE_SELECTION_FLOW, ACCESS_STATE_MATRIX:

- All accessible companies listed
- Owner vs Member distinction required
- Owned → Owner Panel; member-only → Branch path with explicit accessible branch
- Never Owner chrome on member-only
- Server validation; no client tenant ID authority

**Finding:** Prior switcher inconsistency in ACCESS_STATE_MATRIX was corrected in the D11–D16 baseline commit. No remaining contradiction.

### 4.3 Module navigation (Broker / OwnFleet / Partload)

| Topic                                        | Finding                                                                                                                                    |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Design.md §2 groups                          | Reserved as IA labels in Branch Panel                                                                                                      |
| Phase 2 product module screens               | Correctly **Out of scope**                                                                                                                 |
| Unavailable modules                          | **Hide** (PO-P2-DESIGN-MODULES) — consistent                                                                                               |
| Entitlements when module inactive/restricted | Credits unusable while inactive/restricted (Rules §15) — do not invent chrome beyond hide/unavailable; detailed matrix Pending AUD-CON-006 |
| Company/branch restrictions                  | Owner future branches vs member explicit assignments — consistent with TB-OWN-007/008                                                      |

### 4.4 Subscription UX

| State                                   | Documented                                        | Governing basis                                              | Gap                                                                 |
| --------------------------------------- | ------------------------------------------------- | ------------------------------------------------------------ | ------------------------------------------------------------------- |
| Active                                  | Normal access subject to entitlements             | PO-P2-DESIGN-SUB-UX                                          | None for Phase 2 panel IA                                           |
| Trial                                   | Principles + Rules (limits; OP remains; no grace) | Rules §15; SUB-UX                                            | Exact limit chrome Pending                                          |
| Grace                                   | View-only; OP remains                             | Rules §15; SUB-UX                                            | Exact Branch chrome Pending where not stated                        |
| Restricted                              | Principles only                                   | SUB-UX; AUD-CON-006 Pending                                  | **Exact permissions Pending — documented exception**                |
| Suspended                               | Branch blocked; Owner billing remains             | Rules §15; SUB-UX                                            | Consistent                                                          |
| Company deactivated                     | Branch blocked; limited OP view                   | DEACT-VIEW; DEC-002                                          | Consistent                                                          |
| Expired                                 | Not a separate named state in SUB-UX              | Maps via grace → restriction / suspension rules in Rules §15 | Clarify in handoff: do not invent “Expired” as a seventh panel mode |
| Common renewal / proration / module add | Commercial rules in Rules §15                     | Phase 3 billing delivery                                     | **Out of scope** for Phase 2 panel product UI depth                 |

### 4.5 Dashboards and navigation

| Check                             | Result                                                            |
| --------------------------------- | ----------------------------------------------------------------- |
| Admin/context-only dashboards     | Consistent with PO-P2-DESIGN-DASH                                 |
| No charts/KPIs/fabricated metrics | Consistent                                                        |
| Empty / loading / error / denied  | Present in A–E                                                    |
| Deactivated company capabilities  | Limited to approved view surfaces                                 |
| Responsive / a11y / brand         | Stated; aligned with Design.md §§1, 13 and technical shell tokens |

### 4.6 Design-to-implementation gaps (handoff, not contradictions)

| Gap                                   | Classification                              |
| ------------------------------------- | ------------------------------------------- |
| MRU owned-company persistence store   | Engineering implementation detail           |
| Inertia routes / Wayfinder for panels | Blocked until formal artefact approval      |
| Permission-aware Branch nav filtering | Backend RBAC exists; product nav UI blocked |
| Subscription state machine UI         | Phase 3 + AUD-CON-006                       |

---

## 5. Documented exceptions (required for “READY WITH DOCUMENTED EXCEPTIONS”)

### 5.1 AUD-CON-006 / TB-SUB-STATE-001 — exact entitlement matrix

- **Governing requirement:** PRD §19 Pending consolidated UX/entitlement matrix; Rules §15 fragments for trial/grace/suspension/deactivation.
- **Current docs:** PO-P2-DESIGN-SUB-UX high-level principles approved; exact trial/grace/restricted permissions marked Pending.
- **Contradiction?** No — intentionally unresolved.
- **Recommendation:** Accept formal approval of A–G **with exception** that Phase 2 product UI (when later authorized) must render only approved high-level states and must not invent restricted/trial/grace permission matrices until AUD-CON-006 is decided. Detailed subscription checkout remains Phase 3.
- **Blocks unconditional approval:** Yes (hence exceptions).
- **Blocks approval-with-exceptions:** No.

### 5.2 Internal Team product surfaces

- **Governing:** Design.md §14 / Phase 0 deliverables still list Internal Team IA as Not Started.
- **Current package:** Customer Owner/Branch only.
- **Recommendation:** Explicit out-of-scope exception for this package. Internal Team landing is not required to approve Customer panel artefacts A–G.
- **Blocks A–G approval-with-exceptions:** No.

### 5.3 Broker / OwnFleet / Partload operational screens

- **Governing:** Later phases per Phases.md; Design.md module screens.
- **Current package:** Nav groups + hide unavailable; no module workflows.
- **Recommendation:** Accept as Phase 2 scope boundary, not a defect.
- **Blocks A–G approval-with-exceptions:** No.

### 5.4 Notification centre depth

- **Governing:** Design.md §9 notification patterns; mandatory subscription alerts.
- **Current package:** Branch Panel/Dashboard expose a centre **entry** only; full centre depth Out of scope.
- **Recommendation:** Accept entry-only for Phase 2 panels; do not invent notification product workflows.
- **Blocks A–G approval-with-exceptions:** No.

---

## 6. Product Owner Decision Register (unresolved)

Recommendations below are **not** approvals. Product Owner must decide.

### PO-DEC-P2-REVIEW-001 — AUD-CON-006 / TB-SUB-STATE-001

| Field                       | Content                                                                                                                                                                                                                                                         |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Context                     | Exact panel behaviour for trial, grace, and restricted states beyond high-level principles                                                                                                                                                                      |
| Options                     | **A)** Defer detailed matrix to Phase 3 and approve Phase 2 panels with SUB-UX principles only; **B)** Decide full matrix now before any panel approval; **C)** Decide matrix only for Branch Panel operational visibility now, leave billing chrome to Phase 3 |
| Recommended                 | **A** — keeps Phase 2 focused; avoids inventing commercial UX; matches Phases.md (billing Phase 3) and existing Pending status                                                                                                                                  |
| Business justification      | Transport operators need clear Owner/Branch shells now; fine-grained entitlement chrome belongs with subscription delivery                                                                                                                                      |
| UX impact                   | Phase 2 shows state labels + approved blocked/limited behaviours only                                                                                                                                                                                           |
| Technical impact            | Avoids premature entitlement engine UI coupling                                                                                                                                                                                                                 |
| Security                    | Fail closed: unknown entitlement → deny Branch ops; never trust client state                                                                                                                                                                                    |
| Subscription                | No change to Rules §15 commercial maths                                                                                                                                                                                                                         |
| Deferral consequence        | Phase 2 UI cannot show invented restricted/trial matrices; engineers must gate on Pending                                                                                                                                                                       |
| Blocks formal A–G approval? | Only if PO refuses exceptions; otherwise exception #5.1                                                                                                                                                                                                         |

### PO-DEC-P2-REVIEW-002 — OQ-WS-02 Zero-access UX

| Field                       | Content                                                                                                                                                                                                |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Context                     | Authenticated Customer with no remaining company/branch access                                                                                                                                         |
| Options                     | **A)** Dedicated “No workspace access” screen with support/contact and logout; **B)** Immediate logout to login with flash message; **C)** Soft-delete already applied → treat as unauthenticated only |
| Recommended                 | **A** for active identities that still exist without memberships pending soft-delete timing; **B** if identity is already soft-deleted/deactivated                                                     |
| Business justification      | Clear fail-closed messaging without implying recoverable tenant context                                                                                                                                |
| UX impact                   | Avoids blank dashboard                                                                                                                                                                                 |
| Technical impact            | Small route; no tenant context injection                                                                                                                                                               |
| Security                    | Must not retain stale company chrome                                                                                                                                                                   |
| Deferral consequence        | Engineers pick A or B at implementation; low risk if fail-closed                                                                                                                                       |
| Blocks formal A–G approval? | **No** (non-blocking)                                                                                                                                                                                  |

### PO-DEC-P2-REVIEW-003 — OQ-WS-03 Combined vs split selection

| Field                       | Content                                                                                                                            |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Context                     | Company then branch selection UI                                                                                                   |
| Options                     | **A)** Sequential screens (company → branch); **B)** Combined single screen; **C)** Adaptive (combine only when both counts small) |
| Recommended                 | **A** — simpler mental model for multi-company transport users; matches server two-step validation                                 |
| Business justification      | Reduces wrong-branch mistakes                                                                                                      |
| UX impact                   | One extra step when both multi                                                                                                     |
| Technical impact            | Simpler authorization messaging                                                                                                    |
| Deferral consequence        | Layout choice at implementation                                                                                                    |
| Blocks formal A–G approval? | **No**                                                                                                                             |

### PO-DEC-P2-REVIEW-004 — OQ-BP-02 Branch Settings scope

| Field                       | Content                                                                                                                                                |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Context                     | Branch Panel Settings contents for Owners vs members                                                                                                   |
| Options                     | **A)** Branch/user preferences only; company admin only via Owner Panel; **B)** Owner also sees read-only company billing links inside Branch Settings |
| Recommended                 | **A** — preserves Owner vs Branch distinction (PO-P2-DESIGN-LANDING)                                                                                   |
| Business justification      | Avoids mixing operational and company-admin surfaces                                                                                                   |
| UX impact                   | Owners use Return to Owner Panel for billing                                                                                                           |
| Security                    | Reduces accidental exposure of admin links in Branch chrome                                                                                            |
| Deferral consequence        | Engineers default to A                                                                                                                                 |
| Blocks formal A–G approval? | **No**                                                                                                                                                 |

### PO-DEC-P2-REVIEW-005 — OQ-BD-03 Mobile Owner Panel control

| Field                       | Content                                                                               |
| --------------------------- | ------------------------------------------------------------------------------------- |
| Context                     | Sticky header control for Owners on mobile Branch Panel                               |
| Options                     | **A)** Always show Owner Panel control for Owners; **B)** Place only in overflow menu |
| Recommended                 | **A** — Design.md requires return without re-login; critical mobile                   |
| Classification              | May be accepted as **Design interpretation** without new PO ID if PO concurs          |
| Blocks formal A–G approval? | **No**                                                                                |

### PO-DEC-P2-REVIEW-006 — MRU persistence mechanism

| Field                       | Content                                                                                                  |
| --------------------------- | -------------------------------------------------------------------------------------------------------- |
| Context                     | How “most recently used owned company” is stored                                                         |
| Options                     | Server-side user preference vs session-only vs last successful Owner Panel company_id in trusted profile |
| Recommended                 | **Server-side trusted preference** on the Customer identity (not client localStorage as authority)       |
| Classification              | **Engineering implementation detail** — behaviour already approved (PO-P2-DESIGN-LANDING)                |
| Blocks formal A–G approval? | **No**                                                                                                   |

---

## 7. Blocking vs non-blocking summary

| Item                               | Classification                                 | Blocks unconditional approval | Blocks approval-with-exceptions |
| ---------------------------------- | ---------------------------------------------- | ----------------------------- | ------------------------------- |
| AUD-CON-006 exact entitlements     | Product Owner decision required                | Yes                           | No (if exception accepted)      |
| OQ-WS-02                           | Product Owner decision / Design interpretation | No                            | No                              |
| OQ-WS-03                           | Product Owner decision / Design interpretation | No                            | No                              |
| OQ-BP-02                           | Product Owner decision / Design interpretation | No                            | No                              |
| OQ-BD-03                           | Design interpretation                          | No                            | No                              |
| MRU persistence                    | Engineering implementation detail              | No                            | No                              |
| Internal Team IA absence           | Documented out-of-scope exception              | No                            | No                              |
| Module operational screens absence | Out of Phase 2 scope                           | No                            | No                              |
| Switcher Owner/Member consistency  | Documentation correction (already done)        | No                            | No                              |

---

## 8. Formal design approval readiness checklist

| Criterion                                       | Result                              |
| ----------------------------------------------- | ----------------------------------- |
| Complete Customer Owner/Branch user journeys    | Met (with OQ-WS-02 edge Pending)    |
| Consistent navigation                           | Met                                 |
| Approved access rules                           | Met                                 |
| Empty / error / denied states                   | Met                                 |
| Responsive considerations                       | Met (stated)                        |
| Accessibility considerations                    | Met (stated)                        |
| No contradictions with governing docs / D11–D16 | Met                                 |
| No unauthorized Phase 2 scope expansion         | Met                                 |
| Clear engineering handoff                       | Met (this review + artefacts)       |
| Traceability to PO decisions                    | Met                                 |
| Subscription detail complete                    | **Exception** — AUD-CON-006 Pending |

---

## 9. Exact next action required from Product Owner

1. Review artefacts A–G under `docs/design/phase-2/`.
2. Accept or reject the **documented exceptions** in §5 (especially AUD-CON-006 deferral for Phase 2 panel surfaces).
3. Optionally decide PO-DEC-P2-REVIEW-002…005 now, or defer as non-blocking.
4. Complete sign-off fields in `DESIGN_REVIEW_CHECKLIST.md` (Approve all / Approve subset / Changes requested / Reject).
5. Do **not** treat this review document as formal approval.
6. After formal sign-off only, authorize a separate P2-WS-8 product UI implementation task.

---

## 10. Reviewer confirmation

- No application code, migrations, routes, or product UI were changed by this review task beyond documentation updates listed in the accompanying commit.
- No formal design approval was granted.
- P2-WS-8 and P2-WS-9 remain blocked / not started.
