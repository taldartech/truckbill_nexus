# Phase 2 Readiness Assessment — Identity / Company / Access

**Assessment date:** 22 November 2026  
**Authority:** `docs/governing/Phases.md` (Phase 2 scope and exit); PRD §§3–6; Rules §§2–4; Architecture §§3–5,12; ADR-002/004/005/006/007  
**Baseline tip:** `f5edb9e56465825a8dc2a00193a9935dc787c673`  
**Phase 1 status:** Implementation complete; formal closure pending `PO-P1-DEPLOY` (see `PHASE_1_CLOSURE_ASSESSMENT.md`)

**This document does not authorize Phase 2 implementation.**

---

## 1. Readiness classification

**Authorized to implement (PO-P2-AUTH, 22 November 2026).** P2-WS-0 complete. Next: P2-WS-1. P2-WS-8 product UI remains blocked until design artefacts are approved.

| Dimension                                              | Classification                                                          |
| ------------------------------------------------------ | ----------------------------------------------------------------------- |
| Engineering foundation reuse                           | Ready                                                                   |
| Product model clarity (ADR-005 Accepted product model) | Ready                                                                   |
| Authentication stack (ADR-006)                         | **Accepted web-only**; mobile/Sanctum deferred                          |
| Owner/Branch product UI                                | **Blocked** until design artefacts reviewed (gate requirement approved) |
| Invitation lifecycle (AUD-GAP-003)                     | **Approved** (PO-P2-INVITE)                                             |
| File access (Internal / multi-company)                 | **Decided** — Internal blocked; active-company scoping                  |
| ADR-010 accounting posting                             | **Not a Phase 2 blocker** — defer to Phase 8                            |

---

## 2. Phase 2 scope (authoritative)

From `Phases.md`:

> Global identity, internal/customer separation, auth, customer single session, company/branch, Owner/Branch Panels, membership, branch roles, Permission Masters, ownership transfer, deactivation/suspension, dashboards.  
> Exit: cross-company/branch negative tests, Owner isolation, session revocation, immediate permission invalidation.

### Explicitly out of scope

- Phase 3 masters, documents, subscription/Razorpay billing
- Broker / OwnFleet / Partload operational modules
- Accounting posting architecture (ADR-010)
- Microservices / unapproved dependencies
- Changing Pending/Deferred/Proposed governing statuses without PO authority

---

## 3. What Phase 2 can safely reuse from Phase 1

| Foundation                          | Location / artefact              | Phase 2 use                                        |
| ----------------------------------- | -------------------------------- | -------------------------------------------------- |
| Tenant context carrier              | `app/Support/Tenancy/*`, ADR-002 | Membership-resolved company/branch binding         |
| Client ID rejection                 | `RejectClientTenantIdentifiers`  | Preserve; never trust client tenant IDs            |
| Job/cache/storage helpers           | Support helpers + tests          | Jobs/reports/files must carry resolved context     |
| Audit writer + `AuditedTransaction` | ADR-007                          | Atomic identity/membership/ownership mutations     |
| Private files + fail-closed access  | ADR-008                          | Attachments for identity/company docs if needed    |
| Outbox + idempotency                | ADR-009                          | Invitation emails, session-revocation side effects |
| Observability / correlation         | WS-7                             | Authz failures, transfer flows                     |
| Technical UI shell + tokens         | WS-8, `UI_SHELL.md`              | Chrome only until design gate opens product panels |
| Fortify web login/password reset    | Starter                          | Base web auth until ADR-006 expands mobile         |
| CI / Pest / Larastan / Pint         | WS-2                             | Mandatory gates for every Phase 2 PR               |

---

## 4. Architecture and security review by domain

### 4.1 Identity and authentication

| Topic                                           | Governing source         | Foundation state                        | Gap / risk                                                  |
| ----------------------------------------------- | ------------------------ | --------------------------------------- | ----------------------------------------------------------- |
| Global identity; Internal vs Customer exclusive | TB-AUTH-001, ADR-005     | Product model Accepted; schema Proposed | Implement schema + type enforcement                         |
| Registration disabled                           | TB-AUTH-002              | Fortify registration off                | Preserve; admin/invite only                                 |
| Invitation / onboarding                         | TB-AUTH-003, AUD-GAP-003 | Not implemented                         | **PO decision** on invite lifecycle before coding           |
| Customer single active session (web+mobile)     | TB-AUTH-004              | Not enforceable across mobile           | **ADR-006 required**                                        |
| Internal multi-session                          | TB-AUTH-005              | Web sessions only                       | ADR-006 for consistent registry                             |
| Admin-only email change                         | TB-AUTH-006, DEC-009     | Self-service removed                    | Verification/notification details Pending                   |
| No self-service account delete                  | TB-AUTH-007, DEC-006     | Hard-delete removed                     | Soft-delete/deactivation in Phase 2                         |
| Last Super Admin protection                     | TB-AUTH-008              | Not implemented                         | Critical invariant tests                                    |
| Password change / deactivation → session revoke | PRD §3                   | Partial (web)                           | Full revocation depends on session/token registry (ADR-006) |

### 4.2 Company and branch tenancy

| Topic                                               | Source                  | Gap                                              |
| --------------------------------------------------- | ----------------------- | ------------------------------------------------ |
| Company / branch entities                           | TB-OWN-*                | Not in schema yet                                |
| Exactly one Owner; protected designation            | TB-OWN-001              | Implement + transfer                             |
| Explicit branch access; Owner future branches       | TB-OWN-007/008          | Membership model                                 |
| Company deactivation vs Owner identity deactivation | DEC-002, TB-OWN-002–005 | Branch Panel blocked; Owner Panel exception      |
| Exhaustive Owner Panel actions while deactivated    | TB-OWN-006 **Pending**  | Do not invent actions                            |
| Multi-company customer identities                   | PRD/Rules               | Membership table design                          |
| Cross-company/branch isolation                      | TB-SEC-001              | Extend TenantIsolationTest with real memberships |

### 4.3 Roles and permissions

| Topic                                         | Source           | Gap                                                    |
| --------------------------------------------- | ---------------- | ------------------------------------------------------ |
| Separate Internal/Customer permission domains | TB-RBAC-001      | Permission Master + role tables                        |
| Branch-specific role assignment               | PRD §6           | Explicit assignments                                   |
| Explicit Delete permission                    | Rules §3         | Never infer from role name                             |
| Server-side authorization everywhere          | Architecture §12 | Policies/gates for HTTP, jobs, reports, exports, files |
| Immediate invalidation                        | Phase 2 exit     | Cache/session/permission cache bust                    |

### 4.4 Ownership transfer

| Topic                                             | Source     | Gap                      |
| ------------------------------------------------- | ---------- | ------------------------ |
| Authorized actors (Internal SA/Admin)             | TB-OWN-001 | Workflow + authz         |
| Existing Customer User requirement                | PRD        | Validation               |
| Old Owner access revocation; new Owner privileges | PRD §3     | Atomic + audit           |
| Session invalidation for new Owner                | PRD §3     | ADR-006 registry         |
| History retention; other memberships unaffected   | PRD        | Audit + membership rules |

### 4.5 Audit and lifecycle

Reuse `AuditedTransaction` for: membership changes, role assignments, ownership transfer, deactivation, email admin changes. Remarks required on updates/deletions (TB-AUD-*). Soft-delete / deactivation only; **no hard delete or restore** (TB-DEL-001, DEC-003).

### 4.6 Frontend

| Topic                                 | Gate                                           |
| ------------------------------------- | ---------------------------------------------- |
| Owner Panel / Branch Panel product IA | Design Deliverables Gate (`Design.md` §14)     |
| Auth UI refinements                   | May extend starter surfaces within shell rules |
| Dashboards                            | Product design artefacts required              |
| Poppins + brand tokens                | Reuse WS-8                                     |

Do **not** invent UI workflows where design artefacts or PO decisions are Pending.

---

## 5. ADR-006 relevance (summary)

**Accepted (PO-P2-ADR-006):** web-only Fortify + Laravel sessions. Customer single **web** session; Internal multi-session; revoke on security events. Sanctum/mobile deferred. See ADR-006 and `PHASE_2_PRODUCT_OWNER_DECISIONS.md`.

---

## 6. ADR-010 relevance

ADR-010 (accounting posting consistency) is **Pending** for Phase 8 / external validation. Phase 2 identity/company/access has **no direct dependency** on sync-vs-async posting. Preserve Pending status; do not treat as a Phase 2 authorization blocker.

---

## 7. Phase 2 exit requirements (preview)

Must be proven before Phase 2 closure:

1. Cross-company negative tests
2. Cross-branch negative tests
3. Owner isolation (Owner Panel Owner-only; non-Owner denied)
4. Session revocation (password change, deactivation, ownership transfer rules)
5. Immediate permission invalidation

---

## 8. Recommended execution sequence

See `docs/implementation/PHASE_2_EXECUTION_PLAN.md` for full workstreams. High-level critical path:

1. PO Phase 2 authorization + ADR-006 decision (auth stack)
2. Identity schema + Internal/Customer type enforcement
3. Company/branch + membership + Owner designation
4. Roles / Permission Master + server-side authz
5. Session/token registry per ADR-006 + single-session rules
6. Ownership transfer + deactivation/suspension
7. Owner/Branch Panels + dashboards **after** design gate
8. Exit certification (negative tests, revocation, invalidation)

---

## 9. Risks

| Risk                                                 | Severity | Mitigation                                           |
| ---------------------------------------------------- | -------- | ---------------------------------------------------- |
| Building product panels without design artefacts     | High     | Enforce Design Deliverables Gate                     |
| Implementing mobile auth before ADR-006              | High     | Keep Sanctum out until Accepted                      |
| Inventing TB-OWN-006 deactivated Owner Panel actions | Medium   | Leave Pending; minimal admin/billing visibility only |
| File-access Pending semantics silently “fixed”       | High     | Keep fail-closed until PO decides                    |
| Soft-delete vs deactivation edge cases               | High     | Follow DEC-002/003 and TB-DEL-* exactly              |
