# Phase 2 Product Owner Decision Register

**Date:** 22 November 2026 (updated with Product Owner approvals)  
**Purpose:** Consolidated Phase 2 decisions.  
**Authority:** Product Owner approvals recorded in `docs/engineering/PHASE_2_AUTHORIZATION_RECORD.md`.  
**Rule:** Do not change Pending / Deferred / Proposed / Excluded / Accepted statuses without explicit Product Owner authority.

---

## Decision index (current)

| ID                               | Topic                                | Status                                            | Blocks                        |
| -------------------------------- | ------------------------------------ | ------------------------------------------------- | ----------------------------- |
| PO-P2-AUTH                       | Phase 2 implementation authorization | **APPROVED**                                      | — (implementation authorized) |
| ADR-006 / PO-P2-ADR-006          | Web authentication strategy          | **Accepted** (web-only)                           | Mobile/Sanctum deferred       |
| PO-P2-INVITE                     | Invitation lifecycle                 | **APPROVED**                                      | —                             |
| PO-P2-DEACT-COMPANY              | Company deactivation                 | **APPROVED**                                      | —                             |
| PO-P2-DEACT-OWNER                | Owner identity deactivation          | **APPROVED**                                      | —                             |
| PO-P2-FILE-INTERNAL              | Internal Team customer-file access   | **APPROVED — BLOCKED**                            | —                             |
| PO-P2-FILE-MULTI                 | Multi-company file access            | **APPROVED** (active company only)                | —                             |
| PO-P2-DESIGN                     | Design gate requirement              | **APPROVED** (requirement); artefacts Not Started | P2-WS-8 product UI            |
| PO-P1-DEPLOY                     | Phase 1 non-prod smoke               | **DEFERRED** (not waived)                         | Phase 1 formal closure        |
| ADR-010                          | Accounting posting                   | **Pending**                                       | Phase 8 only                  |
| Email change verification polish | Admin email details                  | **Pending**                                       | Polish only                   |

---

## D1 — ADR-006: Web and mobile authentication strategy

### PO Decision — **ACCEPTED (web-only for Phase 2)**

**Date:** 22 November 2026  
**Decision ID:** PO-P2-ADR-006

**Approved architecture:**

- Laravel Fortify + Laravel session-based authentication for web.
- Do **not** install Sanctum in Phase 2.
- Do **not** implement mobile authentication or API token infrastructure in Phase 2.
- Customer Users: one active web session at a time.
- Internal Team: multiple concurrent web sessions allowed.
- Session revocation on password change, deactivation and applicable security events.
- Keep modular enough for future mobile auth; do not invent mobile abstractions now.

**Prior recommendation (Sanctum Option A):** Superseded for Phase 2 by this Product Owner decision. Sanctum remains a future option when mobile is authorized.

**Document:** `docs/architecture-decisions/ADR-006-web-mobile-authentication-strategy.md`

---

## D2 — PO-P2-AUTH: Phase 2 implementation authorization

### PO Decision — **APPROVED**

**Date:** 22 November 2026

Authorize Phase 2 Identity / Company / Access under `docs/implementation/PHASE_2_EXECUTION_PLAN.md`. Does not authorize Phase 3+. Phase 1 `PO-P1-DEPLOY` deferral does not block Phase 2.

**Record:** `docs/engineering/PHASE_2_AUTHORIZATION_RECORD.md`

---

## D3 — PO-P2-INVITE: Invitation lifecycle

### PO Decision — **APPROVED**

**Date:** 22 November 2026

- Secure, time-limited, single-use invitation links.
- Deliver via application email/outbox infrastructure.
- Authorized administrators may revoke pending invitations.
- Expired invitations cannot be accepted.
- Existing Customer Users retain identity and password; acceptance adds membership only (TB-AUTH-003).
- Audit creation, delivery, acceptance, expiry and revocation.
- Protect against duplicate acceptance, replay and concurrent processing.
- No public self-registration.

---

## D4 — PO-P2-DESIGN: Design Deliverables Gate

### PO Decision — **APPROVED as gate requirement**

**Date:** 22 November 2026

Prepare and obtain Product Owner approval of IA and key screen designs for:

1. Owner Panel
2. Branch Panel
3. Workspace/company selection
4. Owner Dashboard
5. Branch Dashboard

**Do not** implement product-specific UI before the relevant deliverable is completed and approved. Backend may proceed. Approval of this requirement is **not** approval of unreviewed screens.

**Register:** `docs/engineering/PHASE_2_DESIGN_DELIVERABLES.md` (artefacts Not Started).

---

## D5 — Company vs Owner deactivation (TB-OWN-006 scope)

### PO Decision — **APPROVED**

**Date:** 22 November 2026  
**IDs:** PO-P2-DEACT-COMPANY, PO-P2-DEACT-OWNER

**Company deactivation:**

- Branch Panel blocked for Owner and all company users.
- Active Company Owner retains **limited** Owner Panel access for company administration and billing information only.
- Do not invent additional Owner Panel capabilities while deactivated.
- Does **not** automatically deactivate the Owner’s global identity.

**Owner identity deactivation:**

- Block login and access completely, including Owner Panel.
- Revoke applicable active sessions.
- Distinct from company deactivation.

Phase 2 treats exhaustive additional deactivated Owner Panel actions as **out of invention scope** — limited to admin/billing information per DEC-002 and this decision.

---

## D6 — Internal Team access to customer files

### PO Decision — **BLOCKED**

**Date:** 22 November 2026  
**ID:** PO-P2-FILE-INTERNAL

Internal Team users must **not** access customer-uploaded documents through administrative access or ordinary application interfaces. No implicit administrator bypass. Enforce server-side across preview, download, export, API, signed URL and other applicable paths.

---

## D7 — Multi-company Owner file access

### PO Decision — **APPROVED (active company context only)**

**Date:** 22 November 2026  
**ID:** PO-P2-FILE-MULTI

File access is always scoped to the currently active company context resolved server-side. No cross-company file access. Never trust client-supplied company IDs. Enforce company and branch restrictions consistently.

---

## D8 — PO-P1-DEPLOY

### PO Decision — **DEFERRED**

**Date:** 22 November 2026

- Do not provision staging infrastructure.
- Do not deploy to a new environment for Phase 1 smoke.
- Do not claim the Phase 1 deployment smoke test passed.
- Phase 1 remains conditionally certified with operational closure pending.
- Requirement is **deferred, not waived**.
- Does not prevent authorized Phase 2 development.

---

## D9 — ADR-010

### PO Decision for Phase 2 — **Defer**

ADR-010 remains **Pending**. Not a Phase 2 blocker.

---

## D10 — Admin email-change verification details

### Status — **Pending**

Verification/notification/uniqueness/audit field polish for DEC-009 remains Pending. Self-service email change remains prohibited.

---

## Consistency checklist (P2-WS-0)

| Document                           | Updated for PO decisions                    |
| ---------------------------------- | ------------------------------------------- |
| PHASE_2_AUTHORIZATION_RECORD.md    | Yes                                         |
| ADR-006                            | Accepted web-only                           |
| PHASE_2_PRODUCT_OWNER_DECISIONS.md | This file                                   |
| PHASE_2_EXECUTION_PLAN.md          | Authorized                                  |
| PHASE_2_READINESS_ASSESSMENT.md    | Reflects decisions                          |
| PHASE_2_DESIGN_DELIVERABLES.md     | Gate requirement; artefacts Not Started     |
| PHASE_0_DELIVERABLES.md            | Cross-reference Phase 2 register            |
| Memory.md                          | Verified facts + decision log               |
| PHASE_1_* deploy status            | Deferred, not waived                        |
| truckbill-project.mdc              | Phase 2 authorized; Sanctum still forbidden |
