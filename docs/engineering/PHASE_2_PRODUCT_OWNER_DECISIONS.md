# Phase 2 Product Owner Decision Register

**Date:** 22 November 2026  
**Purpose:** Consolidated decisions required for Phase 2 Identity / Company / Access.  
**Rule:** Do not change Pending / Deferred / Proposed / Excluded / Accepted statuses without explicit Product Owner authority. This document records **questions and recommendations only**.

**Approval fields:** Each decision ends with blank `PO Decision`, `Date`, `Signature/initials` for Product Owner use.

---

## Decision index

| ID                                                          | Topic                                         | Blocks Phase 2 auth?                              | Blocks specific WS                 |
| ----------------------------------------------------------- | --------------------------------------------- | ------------------------------------------------- | ---------------------------------- |
| ADR-006                                                     | Web/mobile authentication strategy            | **Yes** for mobile + cross-channel single-session | P2-WS-4 (+ session effects in 5/7) |
| PO-P2-AUTH                                                  | Explicit Phase 2 implementation authorization | **Yes** (all implementation)                      | All P2-WS-*                        |
| PO-P2-INVITE                                                | Invitation lifecycle                          | No (prep OK)                                      | P2-WS-5                            |
| PO-P2-DESIGN                                                | Owner/Branch/dashboard design artefacts       | No (backend prep OK)                              | P2-WS-8 product UI                 |
| TB-OWN-006                                                  | Exhaustive deactivated Owner Panel actions    | No (minimal DEC-002 OK)                           | Full deactivated Owner UX          |
| Pending file-access (Internal branch / multi-company Owner) | File semantics                                | No if fail-closed retained                        | File features using those paths    |
| PO-P1-DEPLOY                                                | Non-prod deploy smoke                         | Phase **1** closure                               | Not a Phase 2 code blocker         |
| ADR-010                                                     | Accounting posting                            | **No**                                            | Phase 8                            |
| Email change verification details                           | Admin email change UX                         | No                                                | Admin email flows polish           |

---

## D1 — ADR-006: Web and mobile authentication strategy

### Exact question

Which authentication stack will TruckBill use for **web** and **TruckBill mobile** so that:

- Customers have a **single active session across web and mobile** (TB-AUTH-004)?
- Internal Team may hold **multiple concurrent sessions** (TB-AUTH-005)?
- Password change, deactivation and ownership-transfer rules can **revoke** the correct sessions/tokens?

### Why it matters

Without a chosen session/token registry, Phase 2 cannot correctly implement cross-channel single-session or reliable revocation. Architecture mentions Sanctum “where appropriate”; Sanctum is **not** installed; ADR-006 is **Pending**.

### Related requirements / ADRs / findings

TB-AUTH-004, TB-AUTH-005; ADR-006; AUD-GAP-004; AUD-REPO-009; Architecture §4.

### Options

| Option                                            | Summary                                                                                                                                                                                                          |
| ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A. Sanctum (recommended)**                      | Cookie/session SPA auth for first-party web (Inertia); Sanctum personal access / API tokens for mobile; custom application layer enforces customer single-session by revoking prior tokens/sessions on new login |
| **B. First-party token registry without Sanctum** | Custom tables for web session tracking + mobile tokens; more ownership, more maintenance                                                                                                                         |
| **C. Separate mobile auth provider**              | External IdP for mobile; higher operational complexity and session-sync risk                                                                                                                                     |

### Recommendation: Option A (Laravel Sanctum) — rationale

1. Fits modular monolith + first-party Inertia web already using Laravel sessions/Fortify.
2. Sanctum is the Laravel-native path for SPA cookies + mobile API tokens without a full OAuth server.
3. Single-session and Internal multi-session are **application policy** on top of Sanctum, not reasons to avoid it.
4. Option B duplicates framework capability; Option C adds vendor and sync risk for Phase 2.

### Security / operational consequences

| Topic                | Implication under Option A                                                                                   |
| -------------------- | ------------------------------------------------------------------------------------------------------------ |
| CSRF                 | Web cookie auth must keep CSRF protection (Inertia/Axios-or-XHR defaults)                                    |
| Cookie flags         | Secure, HttpOnly, SameSite appropriate to domain split                                                       |
| Mobile token storage | OS-secure storage; no localStorage for long-lived secrets if avoidable                                       |
| Revocation           | Central revoke on password change, deactivation, new customer login, ownership transfer (new Owner sessions) |
| Theft                | Short-lived tokens + refresh policy TBD in implementation design after Accept                                |
| Tests                | TEST-AUTH-004/005; revocation matrix; cross-channel login                                                    |

### What can proceed without Accepting ADR-006

- Identity/company/membership/permission **schema design** and much of P2-WS-1–3 implementation planning
- Isolation and RBAC test design
- **Cannot:** install Sanctum; ship mobile tokens; claim TB-AUTH-004 done

### Workstream blocked

**P2-WS-4** (and session-dependent parts of P2-WS-5/7).

### Mandatory before Phase 2 authorization?

**Strongly recommended before authorizing full Phase 2.** PO may authorize Phase 2 with ADR-006 still Pending **only if** P2-WS-4/mobile/single-session are explicitly deferred in the authorization record — not silently skipped.

```
PO Decision (Accept A / Accept B / Accept C / Defer with narrowed scope): ________
Date: ________    Initials: ________
Notes: ________
```

---

## D2 — PO-P2-AUTH: Phase 2 implementation authorization

### Exact question

Does the Product Owner authorize Phase 2 Identity / Company / Access implementation under `docs/implementation/PHASE_2_EXECUTION_PLAN.md`?

### Why it matters

`Phases.md` / DEC-004 pattern: phase documentation ≠ implementation authority. PO-P1-AUTH covered Phase 1 only.

### Options

1. Authorize full Phase 2 per execution plan
2. Authorize narrowed Phase 2 (list excluded workstreams)
3. Deny / defer until Phase 1 deploy smoke closed

### Recommendation

Authorize **after** reviewing Phase 1 closure assessment; preferably Accept ADR-006 in the same sitting or record explicit deferral of P2-WS-4. Phase 1 `PO-P1-DEPLOY` outstanding should be acknowledged but need not block authorization if PO accepts parallel tracks.

```
PO Decision: ________
Date: ________    Initials: ________
Scope notes: ________
```

---

## D3 — PO-P2-INVITE: Invitation lifecycle (AUD-GAP-003)

### Exact question

What is the approved invitation lifecycle for Internal and Customer onboarding (create, deliver, expire, accept, revoke, identity-reuse password rules, email verification)?

### Why it matters

TB-AUTH-003 forbids overwriting passwords on identity reuse; invitation details are underspecified (AUD-GAP-003). Coding onboarding without this invents product behavior.

### Related

TB-AUTH-002, TB-AUTH-003; ADR-005; P2-WS-5.

### Options (illustrative — PO must specify)

1. Time-limited signed invite links + outbox email; accept creates or attaches membership; never resets password if identity exists
2. Admin-set temporary password (discouraged)
3. Hybrid Internal vs Customer flows

### Recommendation

Option 1 with outbox (ADR-009), expiry, single-use tokens, audit on issue/accept/revoke; existing identity → membership attach only.

### Blocks

P2-WS-5 implementation. Not mandatory before all Phase 2 authorization if WS-5 deferred.

```
PO Decision: ________
Date: ________    Initials: ________
```

---

## D4 — PO-P2-DESIGN: Design Deliverables Gate artefacts

### Exact question

Which design artefacts are approved for Owner Panel, Branch Panel, workspace selection and dashboards so product UI may be implemented?

### Why it matters

`Phases.md` Design Deliverables Gate and `Design.md` §14 forbid product screens without reviewed artefacts. Phase 1 technical shell must not be treated as permission to build panels.

### Related

Design.md; PHASE_0_DELIVERABLES; P2-WS-8.

### Recommendation

Require reviewed IA + key screen designs before P2-WS-8. Backend can proceed earlier.

```
PO Decision (artefacts approved / still Not Started): ________
Date: ________    Initials: ________
Artefact list: ________
```

---

## D5 — TB-OWN-006: Exhaustive Owner Panel actions while company Deactivated

### Exact question

Beyond company-level administrative and billing **visibility** (DEC-002), which Owner Panel **actions** are permitted while the company is Deactivated?

### Why it matters

Status is **Pending**. Inventing actions creates unauthorized product scope.

### Related

TB-OWN-002–006; DEC-002; P2-WS-6/8.

### Recommendation

Implement DEC-002 minimum (Branch Panel blocked; Owner Panel accessible for stated admin/billing information). Leave additional actions Pending until listed and Confirmed.

### Blocks

Full deactivated Owner UX only — not core membership work.

```
PO Decision: ________
Date: ________    Initials: ________
```

---

## D6 — Internal Team branch-scoped file access

### Exact question

May Internal Team users access private files scoped to a specific customer branch, and under which permission?

### Why it matters

Phase 1 fail-closes this Pending case. Phase 2 membership may need a Confirmed rule before enabling access.

### Related

ADR-008; FileSecurity tests; TB-SEC-001.

### Recommendation

Deny until Confirmed; if allowed, require explicit Internal permission + audit + tenant context.

```
PO Decision (Deny remain / Allow with rules): ________
Date: ________    Initials: ________
```

---

## D7 — Multi-company Owner file-access semantics

### Exact question

When a Customer identity is Owner of company A and member of company B, how may file access resolve across companies?

### Why it matters

Pending; Phase 1 fail-closed. Incorrect resolution causes cross-tenant leakage.

### Recommendation

Keep fail-closed until Confirmed; likely require active company workspace context for every file operation.

```
PO Decision: ________
Date: ________    Initials: ________
```

---

## D8 — PO-P1-DEPLOY (Phase 1 closure; not Phase 2 feature)

### Exact question

What is the non-production deployment target for Phase 1 smoke, or is smoke waived?

### Why it matters

`Phases.md` Phase 1 exit requires non-production deployment. Blocks **formal Phase 1 closure**, not necessarily Phase 2 planning.

### Options

1. Laravel Cloud non-prod
2. Other host
3. Explicit waiver

```
PO Decision: ________
Date: ________    Initials: ________
```

---

## D9 — ADR-010: Accounting posting consistency

### Exact question

Sync vs async posting per document type (Phase 8).

### Phase 2 dependency

**None.** Do not block Phase 2. Preserve **Pending** until Phase 8 / external validation.

```
PO Decision for Phase 2: Defer (recommended) / ________
Date: ________    Initials: ________
```

---

## D10 — Admin email-change verification details

### Exact question

Verification, notification, uniqueness and audit field details for administrative email changes (DEC-009 / TB-AUTH-006 Remaining Pending details).

### Blocks

Polish of admin email flows — not core tenancy schema.

```
PO Decision: ________
Date: ________    Initials: ________
```

---

## Summary for Product Owner

1. **Accept or explicitly defer ADR-006** before mobile/single-session work. Recommendation: **Sanctum (Option A)**.
2. Issue **PO-P2-AUTH** before any Phase 2 code.
3. Decide invite lifecycle before P2-WS-5.
4. Supply design artefacts before P2-WS-8.
5. Keep Pending file-access and TB-OWN-006 fail-closed / minimal until decided.
6. **ADR-010 is not a Phase 2 blocker.**
7. Close Phase 1 operationally via **PO-P1-DEPLOY** (parallel track).
