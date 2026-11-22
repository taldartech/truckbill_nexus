# Phase 2 Readiness Assessment — Identity / Company / Access

**Assessment date:** 22 November 2026 (remediated for post–P2-WS-0 consistency)  
**Authority:** `docs/governing/Phases.md` (Phase 2 scope and exit); PRD §§3–6; Rules §§2–4; Architecture §§3–5,12; ADR-002/004/005/006/007  
**Baseline tip at original assessment:** `f5edb9e56465825a8dc2a00193a9935dc787c673`  
**Phase 1 status:** Implementation complete; formal closure pending `PO-P1-DEPLOY` **DEFERRED** (not waived) — see `PHASE_1_CLOSURE_ASSESSMENT.md`  
**Formal authorization:** Recorded separately and **APPROVED** in `docs/engineering/PHASE_2_AUTHORIZATION_RECORD.md` (PO-P2-AUTH, 22 November 2026).

This document records **readiness and gap analysis**. It is not itself the authorization instrument. Formal Phase 2 implementation authority is `PO-P2-AUTH` in the authorization record.

**Policy approval ≠ implementation complete.** Approved file-access, session and invitation policies still require Phase 2 implementation and test evidence.

---

## 1. Readiness classification

**PO-P2-AUTH APPROVED.** P2-WS-0 (authorization/decision recording) is complete. Next authorized implementation workstream: **P2-WS-1**. P2-WS-8 product UI remains blocked until design artefacts are produced and Product Owner–approved.

| Dimension                                              | Classification                                                                                          |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------- |
| Formal Phase 2 authorization                           | **Approved** (`PHASE_2_AUTHORIZATION_RECORD.md`)                                                        |
| Engineering foundation reuse                           | Ready                                                                                                   |
| Product model clarity (ADR-005 Accepted product model) | Ready                                                                                                   |
| Authentication stack (ADR-006)                         | **Accepted web-only** Fortify + Laravel sessions; Sanctum/mobile **deferred**                           |
| Owner/Branch product UI                                | **Blocked** until design artefacts reviewed (gate **requirement** approved; artefacts Not Started)      |
| Invitation lifecycle (AUD-GAP-003)                     | **Approved** (PO-P2-INVITE) — implementation still required                                             |
| File access (Internal / multi-company)                 | **Decided** — Internal **Blocked**; active-company scoping — implementation/verification still required |
| ADR-010 accounting posting                             | **Not a Phase 2 blocker** — remains Pending                                                             |

---

## 2. Phase 2 scope (authoritative)

From `Phases.md`:

> Global identity, internal/customer separation, auth, customer single session, company/branch, Owner/Branch Panels, membership, branch roles, Permission Masters, ownership transfer, deactivation/suspension, dashboards.  
> Exit: cross-company/branch negative tests, Owner isolation, session revocation, immediate permission invalidation.

### Explicitly out of scope

- Phase 3 masters, documents, subscription/Razorpay billing
- Broker / OwnFleet / Partload operational modules
- Accounting posting architecture (ADR-010)
- Sanctum / mobile authentication / API token infrastructure (ADR-006 Phase 2 exclusion)
- Microservices / unapproved dependencies
- Changing Pending/Deferred/Proposed governing statuses without PO authority

---

## 3. What Phase 2 can safely reuse from Phase 1

| Foundation                          | Location / artefact              | Phase 2 use                                                                                                                |
| ----------------------------------- | -------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Tenant context carrier              | `app/Support/Tenancy/*`, ADR-002 | Membership-resolved company/branch binding                                                                                 |
| Client ID rejection                 | `RejectClientTenantIdentifiers`  | Preserve; never trust client tenant IDs                                                                                    |
| Job/cache/storage helpers           | Support helpers + tests          | Jobs/reports/files must carry resolved context                                                                             |
| Audit writer + `AuditedTransaction` | ADR-007                          | Atomic identity/membership/ownership mutations                                                                             |
| Private files + fail-closed access  | ADR-008 + PO-P2-FILE-*           | Enforce Internal Blocked + active-company scoping on all paths                                                             |
| Outbox + idempotency                | ADR-009                          | Invitation emails; optional session side effects                                                                           |
| Observability / correlation         | WS-7                             | Authz failures, transfer flows                                                                                             |
| Technical UI shell + tokens         | WS-8, `UI_SHELL.md`              | Chrome only until design artefacts approved for product panels                                                             |
| Fortify web login/password reset    | Starter                          | **Accepted** Phase 2 auth approach (ADR-006 web-only); extend for Customer single web session — **do not** install Sanctum |
| CI / Pest / Larastan / Pint         | WS-2                             | Mandatory gates for every Phase 2 change                                                                                   |

---

## 4. Architecture and security review by domain

### 4.1 Identity and authentication

| Topic                                           | Governing source                   | Foundation / decision state              | Gap / risk                                                               |
| ----------------------------------------------- | ---------------------------------- | ---------------------------------------- | ------------------------------------------------------------------------ |
| Global identity; Internal vs Customer exclusive | TB-AUTH-001, ADR-005               | Product model Accepted; schema Proposed  | Implement schema + type enforcement                                      |
| Registration disabled                           | TB-AUTH-002                        | Fortify registration off                 | Preserve; admin/invite only                                              |
| Invitation / onboarding                         | TB-AUTH-003, AUD-GAP-003           | **PO-P2-INVITE Approved**                | Implement single-use/outbox/audit; not yet coded                         |
| Customer single active **web** session          | TB-AUTH-004 (web portion), ADR-006 | **Accepted** web-only                    | Implement Customer single web session; mobile/cross-channel **Deferred** |
| Internal multi-session (web)                    | TB-AUTH-005, ADR-006               | **Accepted** for concurrent web sessions | Preserve multi-session for Internal                                      |
| Mobile / cross-channel single-session           | TB-AUTH-004 mobile portion         | **Deferred** (future phase)              | Out of Phase 2                                                           |
| Admin-only email change                         | TB-AUTH-006, DEC-009               | Self-service removed                     | Verification/notification field details still **Pending**                |
| No self-service account delete                  | TB-AUTH-007, DEC-006               | Hard-delete removed                      | Soft-delete/deactivation in Phase 2                                      |
| Last Super Admin protection                     | TB-AUTH-008                        | Not implemented                          | Critical invariant tests                                                 |
| Password change / deactivation → session revoke | PRD §3, ADR-006                    | Web session revocation required          | Implement on Laravel sessions (no mobile token registry)                 |

### 4.2 Company and branch tenancy

| Topic                                               | Source                                 | Gap                                                                                                                                             |
| --------------------------------------------------- | -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Company / branch entities                           | TB-OWN-*                               | Not in schema yet                                                                                                                               |
| Exactly one Owner; protected designation            | TB-OWN-001                             | Implement + transfer                                                                                                                            |
| Explicit branch access; Owner future branches       | TB-OWN-007/008                         | Membership model                                                                                                                                |
| Company deactivation vs Owner identity deactivation | DEC-002, TB-OWN-002–005, PO-P2-DEACT-* | Implement distinct policies                                                                                                                     |
| Deactivated-company Owner Panel action scope        | TB-OWN-006 **Phase 2 scope Resolved**  | Limited to **company administration and billing information** only; do not invent further actions; Owner Panel **IA/screens** still Not Started |
| Multi-company customer identities                   | PRD/Rules                              | Membership table design                                                                                                                         |
| Cross-company/branch isolation                      | TB-SEC-001                             | Extend TenantIsolationTest with real memberships                                                                                                |

### 4.3 Roles and permissions

| Topic                                         | Source           | Gap                                                    |
| --------------------------------------------- | ---------------- | ------------------------------------------------------ |
| Separate Internal/Customer permission domains | TB-RBAC-001      | Permission Master + role tables                        |
| Branch-specific role assignment               | PRD §6           | Explicit assignments                                   |
| Explicit Delete permission                    | Rules §3         | Never infer from role name                             |
| Server-side authorization everywhere          | Architecture §12 | Policies/gates for HTTP, jobs, reports, exports, files |
| Immediate invalidation                        | Phase 2 exit     | Cache/session/permission cache bust                    |

### 4.4 Ownership transfer

| Topic                                             | Source     | Gap                                                                                       |
| ------------------------------------------------- | ---------- | ----------------------------------------------------------------------------------------- |
| Authorized actors (Internal SA/Admin)             | TB-OWN-001 | Workflow + authz                                                                          |
| Existing Customer User requirement                | PRD        | Validation                                                                                |
| Old Owner access revocation; new Owner privileges | PRD §3     | Atomic + audit                                                                            |
| Session invalidation for new Owner                | PRD §3     | Revoke applicable **Laravel web sessions** (ADR-006); no mobile token registry in Phase 2 |
| History retention; other memberships unaffected   | PRD        | Audit + membership rules                                                                  |

### 4.5 Audit and lifecycle

Reuse `AuditedTransaction` for: membership changes, role assignments, ownership transfer, deactivation, email admin changes. Remarks required on updates/deletions (TB-AUD-*). Soft-delete / deactivation only; **no hard delete or restore** (TB-DEL-001, DEC-003).

Session revocation for password change, identity deactivation and applicable ownership-transfer rules uses **Laravel web sessions** under ADR-006. Do not depend on a mobile token registry in Phase 2.

### 4.6 Frontend

| Topic                                 | Gate                                                                                    |
| ------------------------------------- | --------------------------------------------------------------------------------------- |
| Owner Panel / Branch Panel product IA | Design Deliverables Gate — artefacts **Not Started** (`PHASE_2_DESIGN_DELIVERABLES.md`) |
| Auth UI refinements                   | May extend starter surfaces within shell rules                                          |
| Dashboards                            | Product design artefacts required before UI                                             |
| Poppins + brand tokens                | Reuse WS-8                                                                              |

Do **not** invent UI workflows where design artefacts remain Not Started / unapproved.

---

## 5. ADR-006 relevance (summary)

**Accepted (PO-P2-ADR-006):** web-only Fortify + Laravel sessions. Customer single **web** session; Internal multi-session; revoke on security events. Sanctum/mobile deferred. See ADR-006 and `PHASE_2_PRODUCT_OWNER_DECISIONS.md`.

---

## 6. ADR-010 relevance

ADR-010 (accounting posting consistency) is **Pending** for Phase 8 / external validation. Phase 2 identity/company/access has **no direct dependency** on sync-vs-async posting. Preserve Pending status; do not treat as a Phase 2 blocker.

---

## 7. Phase 2 exit requirements (preview)

Must be proven with **implementation and tests** before Phase 2 closure (policy approval alone is insufficient):

1. Cross-company negative tests
2. Cross-branch negative tests
3. Owner isolation (Owner Panel Owner-only; non-Owner denied)
4. Session revocation (password change, deactivation, ownership transfer rules) on web sessions
5. Immediate permission invalidation
6. File-access enforcement: Internal Blocked; active-company-only multi-company scoping

---

## 8. Recommended execution sequence

See `docs/implementation/PHASE_2_EXECUTION_PLAN.md`. **PO-P2-AUTH and ADR-006 are completed decisions** (P2-WS-0 done). Authorized sequence:

1. ~~PO Phase 2 authorization + ADR-006~~ — **Complete** (P2-WS-0)
2. **P2-WS-1** Identity schema + Internal/Customer type enforcement
3. **P2-WS-2** Company/branch + membership + Owner designation
4. **P2-WS-3** Roles / Permission Master + server-side authz
5. **P2-WS-4** Laravel web session policy + Customer single web session (no Sanctum)
6. **P2-WS-5** Invitations (PO-P2-INVITE)
7. **P2-WS-6 / P2-WS-7** Deactivation + ownership transfer
8. **P2-WS-8** Owner/Branch Panels + dashboards **only after** design artefact PO approval
9. **P2-WS-9** Exit certification with test evidence

---

## 9. Risks

| Risk                                                                         | Severity | Mitigation                                                                |
| ---------------------------------------------------------------------------- | -------- | ------------------------------------------------------------------------- |
| Building product panels without approved design artefacts                    | High     | Gate requirement approved; artefacts Not Started — block P2-WS-8 UI       |
| Installing Sanctum or mobile auth in Phase 2                                 | High     | ADR-006 forbids; mobile/Sanctum deferred                                  |
| Inventing Owner Panel actions beyond admin/billing while company deactivated | Medium   | Phase 2 TB-OWN-006 scope resolved to admin/billing information only       |
| Treating approved file policy as already enforced everywhere                 | High     | Implement and test Internal Blocked + active-company scoping on all paths |
| Soft-delete vs deactivation edge cases                                       | High     | Follow DEC-002/003, PO-P2-DEACT-_, TB-DEL-_ exactly                       |
| Invitation lifecycle bugs (replay, password overwrite)                       | High     | PO-P2-INVITE approved — still must implement with Pest coverage           |
