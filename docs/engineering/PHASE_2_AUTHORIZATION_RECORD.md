# TruckBill — Phase 2 Authorization Record

**Date:** 22 November 2026  
**Repository:** `/Users/ttc/Sites/truckbill/nexus`  
**Decision authority:** Product Owner  
**Governing plan:** `docs/implementation/PHASE_2_EXECUTION_PLAN.md`

> **FORMAL PHASE 2 AUTHORIZATION RECORDED (22 November 2026)**
>
> - **PO-P2-AUTH:** APPROVED — Phase 2 Identity / Company / Access implementation authorized under the current Phase 2 Execution Plan. Satisfies DEC-004 separate implementation authorization for Phase 2.
> - **ADR-006:** ACCEPTED — **web-only** Fortify + Laravel session authentication for Phase 2. Do **not** install Sanctum. Do **not** implement mobile/API token auth in Phase 2.
> - **PO-P2-INVITE:** APPROVED — secure time-limited single-use invitations via outbox; identity reuse without password overwrite.
> - **PO-P2-DEACT-COMPANY / PO-P2-DEACT-OWNER:** APPROVED — company deactivation and Owner identity deactivation are distinct (DEC-002 preserved).
> - **PO-P2-FILE-INTERNAL:** APPROVED — Internal Team access to customer-uploaded files is **Blocked** (no admin bypass).
> - **PO-P2-FILE-MULTI:** APPROVED — multi-company file access scoped to server-resolved active company context only.
> - **PO-P2-DESIGN:** APPROVED as a **gate requirement** — IA and key screen designs for Owner/Branch panels, workspace selection and dashboards must be prepared and Product Owner–approved before product UI implementation. This does **not** approve any specific unreviewed screen design. Artefacts remain Not Started until produced and reviewed.
> - **PO-P1-DEPLOY:** **DEFERRED** — do not provision staging; do not claim smoke passed; Phase 1 remains conditionally certified with operational closure pending. Deferral is **not** a waiver.
> - **ADR-010:** remains **Pending** — not a Phase 2 blocker; do not implement accounting posting architecture.
> - **PO-P1-DEPS:** remains CASE-BY-CASE for new packages.

---

## 1. Phase 2 authorization

| Decision ID    | Decision                                                                                                     | Status       | Date             | Authority     |
| -------------- | ------------------------------------------------------------------------------------------------------------ | ------------ | ---------------- | ------------- |
| **PO-P2-AUTH** | Authorize implementation of Phase 2 — Identity / Company / Access — under the current Phase 2 Execution Plan | **APPROVED** | 22 November 2026 | Product Owner |

**Implementation consequences:** Agents may implement P2-WS-1 through P2-WS-9 within the approved plan scope, sequencing, acceptance criteria and exclusions, subject to ADR-006 (web-only), the Design Deliverables Gate for P2-WS-8 product UI, and preserved Pending items that remain out of invention scope. **Approved policies (sessions, invitations, deactivation, file access) are not complete until implemented and tested.**

**Remaining restrictions:**

- Phase 3+ business modules, Razorpay, masters/documents catalogue, accounting posting (ADR-010) remain out of scope.
- Sanctum / mobile authentication remain out of Phase 2 scope.
- Absolute no-push / no remote publishing remains in force.
- Preserve Pending/Deferred/Excluded/Proposed statuses except where this record explicitly Accepts or Confirms a decision (PO-EXCEPT-001).

---

## 2. Accepted ADR for Phase 2

| Decision ID       | ADR     | Decision                                                                 | Status       | Date             | Authority     |
| ----------------- | ------- | ------------------------------------------------------------------------ | ------------ | ---------------- | ------------- |
| **PO-P2-ADR-006** | ADR-006 | Accept web-only Fortify + session auth for Phase 2; defer mobile/Sanctum | **ACCEPTED** | 22 November 2026 | Product Owner |

See `docs/architecture-decisions/ADR-006-web-mobile-authentication-strategy.md`.

---

## 3. Binding product decisions (Phase 2)

| Decision ID             | Topic                                       | Status                     | Summary                                                                                                                       |
| ----------------------- | ------------------------------------------- | -------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| **PO-P2-INVITE**        | Customer invitation lifecycle               | **APPROVED**               | Time-limited single-use links; outbox email; revoke; no password overwrite on identity reuse; audit trail                     |
| **PO-P2-DEACT-COMPANY** | Company deactivation                        | **APPROVED**               | Branch Panel blocked; active Owner retains limited Owner Panel (admin/billing info only); Owner identity not auto-deactivated |
| **PO-P2-DEACT-OWNER**   | Owner identity deactivation                 | **APPROVED**               | Login and Owner Panel blocked; sessions revoked; distinct from company deactivation                                           |
| **PO-P2-FILE-INTERNAL** | Internal Team → customer files              | **APPROVED — BLOCKED**     | No administrative or ordinary interface access; enforce server-side on all paths                                              |
| **PO-P2-FILE-MULTI**    | Multi-company Owner/member file access      | **APPROVED**               | Active company context only; never trust client company_id                                                                    |
| **PO-P2-DESIGN**        | Design Deliverables Gate for Phase 2 panels | **APPROVED (requirement)** | Prepare IA + key screens; PO must approve artefacts before product UI; backend may proceed                                    |
| **TB-OWN-006 (scope)**  | Exhaustive deactivated Owner Panel actions  | **Resolved for Phase 2**   | Limited to company administration and billing information only; do not invent further actions                                 |

---

## 4. Phase 1 deployment (related)

| Decision ID      | Decision                                                   | Status       | Date             | Authority     |
| ---------------- | ---------------------------------------------------------- | ------------ | ---------------- | ------------- |
| **PO-P1-DEPLOY** | Non-production deployment smoke for Phase 1 formal closure | **DEFERRED** | 22 November 2026 | Product Owner |

Deferral means: no staging provision, no smoke execution, no claim of pass, **not waived**. Phase 1 remains conditionally certified / operational closure pending. This does **not** block authorized Phase 2 development.

---

## 5. Explicitly unchanged

| Item                                                           | Status                                                    |
| -------------------------------------------------------------- | --------------------------------------------------------- |
| ADR-010                                                        | Pending                                                   |
| PO-P1-DEPS                                                     | Case-by-case                                              |
| Admin email-change verification field details (DEC-009 polish) | Pending                                                   |
| Mobile portion of TB-AUTH-004 (cross-channel with mobile app)  | Deferred to a future phase after mobile auth ADR revision |
| Unreviewed Owner/Branch/dashboard screen designs               | Not approved (artefacts Not Started)                      |

---

## 6. Related records

- Decision register: `docs/engineering/PHASE_2_PRODUCT_OWNER_DECISIONS.md`
- Execution plan: `docs/implementation/PHASE_2_EXECUTION_PLAN.md`
- Design register: `docs/implementation/PHASE_0_DELIVERABLES.md` and `docs/engineering/PHASE_2_DESIGN_DELIVERABLES.md`
- Phase 1 authorization: `docs/engineering/PHASE_1_AUTHORIZATION_RECORD.md`
- Phase 1 closure: `docs/engineering/PHASE_1_CLOSURE_ASSESSMENT.md`
