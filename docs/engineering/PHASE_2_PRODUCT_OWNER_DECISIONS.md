# Phase 2 Product Owner Decision Register

**Date:** 22 November 2026 (updated with Product Owner approvals)  
**Purpose:** Consolidated Phase 2 decisions.  
**Authority:** Product Owner approvals recorded in `docs/engineering/PHASE_2_AUTHORIZATION_RECORD.md`.  
**Rule:** Do not change Pending / Deferred / Proposed / Excluded / Accepted statuses without explicit Product Owner authority.  
**Important:** Approval of a policy does **not** mean implementation or testing is complete. Phase 2 workstreams must still build and prove each approved behaviour.

---

## Decision index (current)

| ID                               | Topic                                            | Status                                  | Blocks                                                   |
| -------------------------------- | ------------------------------------------------ | --------------------------------------- | -------------------------------------------------------- |
| PO-P2-AUTH                       | Phase 2 implementation authorization             | **APPROVED**                            | — (implementation authorized)                            |
| ADR-006 / PO-P2-ADR-006          | Web authentication strategy                      | **Accepted** (web-only)                 | Mobile/Sanctum deferred                                  |
| PO-P2-INVITE                     | Invitation lifecycle                             | **APPROVED**                            | —                                                        |
| PO-P2-DEACT-COMPANY              | Company deactivation                             | **APPROVED**                            | —                                                        |
| PO-P2-DEACT-OWNER                | Owner identity deactivation                      | **APPROVED**                            | —                                                        |
| PO-P2-FILE-INTERNAL              | Internal Team customer-file access               | **APPROVED — BLOCKED**                  | —                                                        |
| PO-P2-FILE-MULTI                 | Multi-company file access                        | **APPROVED** (active company only)      | —                                                        |
| PO-P2-DESIGN                     | Design gate requirement                          | **APPROVED** (requirement)              | —                                                        |
| PO-P2-DESIGN-FORMAL              | Formal design artefact approval                  | **APPROVED WITH DOCUMENTED EXCEPTIONS** | P2-WS-8 needs separate impl authorization                |
| PO-P2-DESIGN-SWITCHER            | Unified company / workspace switcher             | **APPROVED**                            | Design package A/C/F                                     |
| PO-P2-DESIGN-LANDING             | Default Owner Panel landing                      | **APPROVED**                            | Design package C                                         |
| PO-P2-DESIGN-DEACT-VIEW          | Deactivated company Owner Panel view             | **APPROVED**                            | Clarifies PO-P2-DEACT-COMPANY                            |
| PO-P2-DESIGN-DASH                | Phase 2 dashboard content limits                 | **APPROVED**                            | Design package D/E                                       |
| PO-P2-DESIGN-MODULES             | Undeveloped module navigation                    | **APPROVED**                            | Design package B/E                                       |
| PO-P2-DESIGN-SUB-UX              | Subscription entitlement presentation principles | **APPROVED** (high-level only)          | Exact trial/grace/restricted entitlements remain Pending |
| PO-DEC-P2-REVIEW-001             | Subscription entitlement matrix exception        | **APPROVED WITH EXCEPTION**             | AUD-CON-006 / TB-SUB-STATE-001 remain Pending            |
| PO-DEC-P2-REVIEW-002             | Zero-access UX                                   | **APPROVED**                            | Dedicated no-access screen                               |
| PO-DEC-P2-REVIEW-003             | Company → Branch selection                       | **APPROVED**                            | Sequential selection                                     |
| PO-DEC-P2-REVIEW-004             | Branch Settings scope                            | **APPROVED**                            | Branch prefs only                                        |
| PO-DEC-P2-REVIEW-005             | Mobile Owner control                             | **APPROVED**                            | Discoverable on mobile                                   |
| PO-DEC-P2-REVIEW-006             | MRU persistence                                  | **ACCEPT ENGINEERING RECOMMENDATION**   | Server-side; revalidate                                  |
| PO-P1-DEPLOY                     | Phase 1 non-prod smoke                           | **DEFERRED** (not waived)               | Phase 1 formal closure                                   |
| ADR-010                          | Accounting posting                               | **Pending**                             | Phase 8 only                                             |
| Email change verification polish | Admin email details                              | **Pending**                             | Polish only                                              |
| AUD-CON-006 / TB-SUB-STATE-001   | Exact trial/grace/restricted entitlement matrix  | **Pending**                             | Entitlement-dependent UI blocked                         |
| PO-P2-WS8-ACCEPT                 | Accept P2-WS-8 + Owner Panel follow-on           | **APPROVED WITH DOCUMENTED EXCEPTIONS** | Exceptions retained; not a waiver                        |
| PO-P2-WS9-AUTH                   | Authorize P2-WS-9 security certification         | **APPROVED**                            | Phase 3 / deferred product still blocked                 |

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

**Register:** `docs/engineering/PHASE_2_DESIGN_DELIVERABLES.md` (artefacts Draft — Awaiting Product Owner Review; formal screen/IA approval still required).

**Follow-up baseline decisions (22 November 2026):** PO-P2-DESIGN-SWITCHER, PO-P2-DESIGN-LANDING, PO-P2-DESIGN-DEACT-VIEW, PO-P2-DESIGN-DASH, PO-P2-DESIGN-MODULES, PO-P2-DESIGN-SUB-UX — see D11–D16. These approve design behaviour baselines; they do **not** by themselves authorize P2-WS-8 product UI implementation.

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

## D11 — PO-P2-DESIGN-SWITCHER: Unified company switcher

### PO Decision — **APPROVED**

**Date:** 22 November 2026  
**Decision ID:** PO-P2-DESIGN-SWITCHER

- The unified workspace switcher lists all companies the signed-in Customer User can access.
- Clearly distinguish companies where the user is the designated Owner from companies where the user has membership access only.
- Selecting an owned company opens its Owner Panel.
- Selecting a member-only company proceeds to Branch Panel access, requiring an explicitly accessible branch.
- Member-only access must never expose Owner Panel controls.
- Every company and branch selection must be validated server-side.
- A client-supplied company or branch ID must never establish authorization.

---

## D12 — PO-P2-DESIGN-LANDING: Default landing behaviour

### PO Decision — **APPROVED**

**Date:** 22 November 2026  
**Decision ID:** PO-P2-DESIGN-LANDING

- If the user owns exactly one company, open that company's Owner Panel by default.
- If the user owns multiple companies, open the most recently used owned company.
- If no previous owned-company selection exists, show an owned-company selection screen.
- A member-only company must never be selected as the default Owner Panel context.
- Preserve the distinction between Owner Panel and Branch Panel navigation.

---

## D13 — PO-P2-DESIGN-DEACT-VIEW: Deactivated company Owner Panel surface

### PO Decision — **APPROVED**

**Date:** 22 November 2026  
**Decision ID:** PO-P2-DESIGN-DEACT-VIEW  
**Related:** PO-P2-DEACT-COMPANY, DEC-002

For a deactivated company:

- Permit viewing company identity and status information.
- Permit viewing subscription, billing, and invoice information.
- Block Branch Panel and operational modules.
- Do not introduce additional write actions unless separately approved.
- Do not confuse company deactivation with Owner identity deactivation.

Existing company vs Owner identity deactivation rules remain authoritative.

---

## D14 — PO-P2-DESIGN-DASH: Phase 2 dashboard content

### PO Decision — **APPROVED**

**Date:** 22 November 2026  
**Decision ID:** PO-P2-DESIGN-DASH

**Owner Dashboard may show:** company identity and status; branch administration entry points; user and membership administration entry points; company settings entry points; subscription and billing summary.

**Branch Dashboard may show:** current company and branch context; available module navigation shortcuts.

Do **not** introduce operational charts, KPIs, fabricated figures, placeholder metrics, or unapproved business reporting in Phase 2.

---

## D15 — PO-P2-DESIGN-MODULES: Undeveloped module navigation

### PO Decision — **APPROVED**

**Date:** 22 November 2026  
**Decision ID:** PO-P2-DESIGN-MODULES

- Hide modules that are not yet available.
- Do not display misleading disabled navigation items.
- Use “Coming soon” only where a delivery plan has been explicitly approved.
- Navigation visibility must never be treated as authorization.

---

## D16 — PO-P2-DESIGN-SUB-UX: Subscription entitlement presentation principles

### PO Decision — **APPROVED (high-level principles only)**

**Date:** 22 November 2026  
**Decision ID:** PO-P2-DESIGN-SUB-UX

High-level presentation principles:

| State               | Presentation principle                                                                              |
| ------------------- | --------------------------------------------------------------------------------------------------- |
| Active              | Normal access, subject to applicable entitlements                                                   |
| Trial               | Access governed by approved trial entitlements                                                      |
| Grace               | Access governed by approved grace-period permissions                                                |
| Restricted          | Access governed by applicable restriction rules                                                     |
| Suspended           | Branch Panel blocked; Owner billing and subscription information remains accessible                 |
| Company deactivated | Branch Panel blocked; Owner Panel limited to approved company administration and billing visibility |

**Do not invent** exact permissions for trial, grace, or restricted states. Reconcile detailed entitlements with governing subscription requirements (Rules §15; PRD §19). Where governing documents do not define an exact entitlement, record **Pending Product Owner Decision** (AUD-CON-006 / TB-SUB-STATE-001) and keep it out of implementation scope.

---

## D17 — PO-P2-DESIGN-FORMAL: Formal design artefact approval

### PO Decision — **APPROVED WITH DOCUMENTED EXCEPTIONS**

**Date:** 22 November 2026  
**Decision ID:** PO-P2-DESIGN-FORMAL  
**Authority:** Product Owner formal instruction

**Approval scope:**

- Shared workspace layouts
- Unified company switcher
- Owner/Member navigation
- Company and branch selection
- Approved landing behaviour
- Owner Panel and Branch Settings boundaries
- Admin/context-focused dashboards
- Module navigation principles (hide unavailable)
- High-level subscription UI presentation (PO-P2-DESIGN-SUB-UX)

**Explicitly excluded / not approved for implementation:**

- Exact trial / grace / restricted / expired entitlement behaviour (AUD-CON-006 / TB-SUB-STATE-001)
- Undecided subscription-dependent actions or write capabilities inferred from high-level states
- Internal Team UI outside this package
- Operational Broker / OwnFleet / Partload modules outside Phase 2 scope
- Any other unresolved dependency identified in `FINAL_DESIGN_REVIEW.md`

**Artefacts:** A–G under `docs/design/phase-2/` — A, D, F approved **with documented exceptions** for Pending entitlement cells; B, C, E, G approved within scope above.

**Implementation gate:** Formal design approval does **not** start P2-WS-8. P2-WS-8 is eligible only for a **separate** Product Owner implementation authorization and must exclude entitlement-dependent interactions until AUD-CON-006 is resolved. P2-WS-9 remains unauthorized. Do not weaken existing authorization enforcement.

---

## D18 — PO-DEC-P2-REVIEW-001: Subscription entitlement matrix

### PO Decision — **APPROVED WITH EXCEPTION**

**Date:** 22 November 2026  
**Decision ID:** PO-DEC-P2-REVIEW-001

- Accept documented high-level subscription UX principles (PO-P2-DESIGN-SUB-UX).
- Defer the detailed trial, grace, restricted, and expired entitlement matrix.
- Do not infer permissions or write capabilities.
- Do not implement entitlement-dependent interactions without separate approval.
- Do not weaken any existing authorization enforcement.
- Maintain **AUD-CON-006 / TB-SUB-STATE-001** as unresolved dependencies.

---

## D19 — PO-DEC-P2-REVIEW-002: Zero-access UX

### PO Decision — **APPROVED**

**Date:** 22 November 2026  
**Decision ID:** PO-DEC-P2-REVIEW-002  
**Resolves:** OQ-WS-02

- Display a dedicated no-access screen for an authenticated Customer who has no accessible company.
- Do not allow unauthorized company or branch access.
- Provide appropriate recovery/navigation actions.
- Follow existing authentication behaviour when the user identity or session is no longer valid.

---

## D20 — PO-DEC-P2-REVIEW-003: Company and branch selection

### PO Decision — **APPROVED**

**Date:** 22 November 2026  
**Decision ID:** PO-DEC-P2-REVIEW-003  
**Resolves:** OQ-WS-03

- Use sequential selection: Company → Branch.
- Validate company access server-side.
- Validate branch access within the selected company.
- Preserve approved Owner/Member distinctions.
- Do not bypass access checks through remembered selections.

---

## D21 — PO-DEC-P2-REVIEW-004: Branch Settings

### PO Decision — **APPROVED**

**Date:** 22 November 2026  
**Decision ID:** PO-DEC-P2-REVIEW-004  
**Resolves:** OQ-BP-02

- Branch Settings covers branch-scoped preferences only.
- Company-level administration belongs to the Owner Panel.
- Do not duplicate company ownership, billing, or subscription administration inside Branch Settings.
- Respect existing access permissions.

---

## D22 — PO-DEC-P2-REVIEW-005: Mobile Owner control

### PO Decision — **APPROVED**

**Date:** 22 November 2026  
**Decision ID:** PO-DEC-P2-REVIEW-005  
**Resolves:** OQ-BD-03

- Owner access must remain discoverable and accessible on mobile.
- Do not hide essential Owner controls behind an inaccessible or ambiguous interface.
- Responsive implementation may choose the appropriate presentation pattern without changing the approved access behaviour.

---

## D23 — PO-DEC-P2-REVIEW-006: MRU persistence

### PO Decision — **ACCEPT ENGINEERING RECOMMENDATION**

**Date:** 22 November 2026  
**Decision ID:** PO-DEC-P2-REVIEW-006

- Persist the most recently used eligible company on the server.
- Revalidate ownership and access when restoring context.
- Fall back safely when the remembered company becomes inaccessible.
- Do not allow client-stored values to authorize access.

---

## D24 — PO-P2-WS8-ACCEPT: Accept P2-WS-8 and Owner Panel follow-on

### PO Decision — **APPROVED WITH DOCUMENTED EXCEPTIONS**

**Date:** 22 November 2026  
**Decision ID:** PO-P2-WS8-ACCEPT

Accepts P2-WS-8 core workspace and Owner Panel follow-on for implemented, tested, approved scope.

**Retained open exceptions (not waived):**

- Legal/tax/contact company fields — schema/policy not approved
- Visibility, numbering, and document settings — deferred
- Membership reactivation — API/policy not approved
- AUD-CON-006 / TB-SUB-STATE-001 — Pending
- Unexecuted interactive browser QA — verification outstanding

---

## D25 — PO-P2-WS9-AUTH: Authorize P2-WS-9 certification

### PO Decision — **APPROVED**

**Date:** 22 November 2026  
**Decision ID:** PO-P2-WS9-AUTH

Authorizes P2-WS-9 security and Phase 2 exit certification, including dedicated security tests and corrections of confirmed Phase 2 defects.

Does **not** authorize Phase 3, Broker/OwnFleet/Partload operations, deferred settings masters, checkout/proration, or invented subscription entitlements.

---

## Consistency checklist (P2-WS-0)

| Document                           | Updated for PO decisions                        |
| ---------------------------------- | ----------------------------------------------- |
| PHASE_2_AUTHORIZATION_RECORD.md    | Yes — formal design approval with exceptions    |
| ADR-006                            | Accepted web-only                               |
| PHASE_2_PRODUCT_OWNER_DECISIONS.md | This file — D17–D23                             |
| PHASE_2_EXECUTION_PLAN.md          | P2-WS-8 eligible for separate auth; not started |
| PHASE_2_READINESS_ASSESSMENT.md    | Reflects decisions                              |
| PHASE_2_DESIGN_DELIVERABLES.md     | Artefacts Approved with documented exceptions   |
| PHASE_0_DELIVERABLES.md            | Cross-reference Phase 2 register                |
| Memory.md                          | Verified facts + decision log                   |
| PHASE_1_* deploy status            | Deferred, not waived                            |
| truckbill-project.mdc              | Phase 2 authorized; Sanctum still forbidden     |
