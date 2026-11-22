# Phase 2 Execution Plan — Identity / Company / Access

**Status:** **AUTHORIZED** — PO-P2-AUTH APPROVED (22 November 2026)  
**Authority:** `docs/governing/Phases.md` (sole phase-numbering and Phase 2 scope authority)  
**Authorization record:** `docs/engineering/PHASE_2_AUTHORIZATION_RECORD.md`  
**Related:** `docs/engineering/PHASE_2_READINESS_ASSESSMENT.md`, `docs/engineering/PHASE_2_PRODUCT_OWNER_DECISIONS.md`, `docs/engineering/PHASE_2_DESIGN_DELIVERABLES.md`  
**Baseline tip at planning:** `f5edb9e56465825a8dc2a00193a9935dc787c673`

**PO-P2-AUTH APPROVED.** Implementation of P2-WS-1+ may proceed under this plan. ADR-006 Accepted as **web-only** Fortify/session auth (no Sanctum/mobile in Phase 2). P2-WS-8 product UI remains gated on design artefact approval (`PO-P2-DESIGN`). Phase 1 `PO-P1-DEPLOY` is **DEFERRED** (not waived) and does not block Phase 2.

---

## 1. Scope

### In scope (Phases.md)

1. Global identity
2. Internal Team and Customer separation
3. Authentication (within ADR-006 decision)
4. Customer single active session
5. Company and branch management
6. Owner Panel and Branch Panel
7. Membership and branch assignments
8. Branch-specific roles
9. Permission Masters
10. Ownership transfer
11. Deactivation and suspension
12. Dashboards

### Exit criteria (Phases.md)

- Cross-company and cross-branch negative tests
- Owner isolation
- Session revocation
- Immediate permission invalidation

### Explicitly out of scope

- Phase 3 masters, common document service, subscription catalogue, Razorpay
- Broker / OwnFleet / Partload modules
- ADR-010 accounting posting implementation
- Installing packages without PO case-by-case approval (`PO-P1-DEPS` pattern)
- Hard delete / restore APIs
- Public self-registration; self-service email change; self-service account deletion
- Sibling `website/` changes

---

## 2. Authorization gate

| Gate      | Requirement                                                                                              | Status (22 Nov 2026)                                   |
| --------- | -------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| G-P2-AUTH | Explicit Product Owner Phase 2 authorization record (`PO-P2-AUTH`)                                       | **Satisfied**                                          |
| G-ADR-006 | ADR-006 **Accepted** for Phase 2 auth approach                                                           | **Satisfied** (web-only; Sanctum/mobile deferred)      |
| G-DESIGN  | Design artefacts reviewed before Owner/Branch/dashboard **product UI**                                   | **Open** — requirement approved; artefacts Not Started |
| G-INVITE  | Invitation lifecycle decisions resolved before onboarding coding                                         | **Satisfied** (PO-P2-INVITE)                           |
| G-CI      | Every workstream: migrate + Pest (Postgres) + Pint + Larastan + frontend check/types/build as applicable | Ongoing                                                |

---

## 3. Workstreams

### P2-WS-0 — Authorization, decisions and design intake

| Field               | Content                                                                                        |
| ------------------- | ---------------------------------------------------------------------------------------------- |
| Objective           | Obtain PO authorization; lock ADR-006; collect design artefacts; freeze Pending exceptions     |
| Scope               | Decision register closure for blockers; design package intake; no product schema yet           |
| Traceability        | DEC-004 pattern; ADR-006; Design.md §14                                                        |
| Prerequisites       | Phase 1 implementation tip green; PO awareness of `PO-P1-DEPLOY` outstanding                   |
| Dependencies        | None                                                                                           |
| ADRs / PO decisions | ADR-006; invite lifecycle; design artefacts; optional `PO-P1-DEPLOY` parallel                  |
| Schema / API / FE   | None                                                                                           |
| Authz / audit risks | N/A                                                                                            |
| Test strategy       | Documentation review only                                                                      |
| Acceptance          | Written `PO-P2-AUTH`; ADR-006 Accepted (web-only); design inventory listed; decisions recorded |
| Definition of done  | Authorization record committed under `docs/engineering/`                                       |
| Rollback            | N/A                                                                                            |
| **P2-WS-0 status**  | **Complete** (22 November 2026) — see authorization record and decision register               |

---

### P2-WS-1 — Identity persistence and type boundaries

| Field         | Content                                                                                                                   |
| ------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Objective     | Persist global identity with mutually exclusive Internal/Customer type                                                    |
| Scope         | Users/identity schema extensions; type enum; soft-delete/deactivation columns; protect last Super Admin scaffolding hooks |
| Traceability  | TB-AUTH-001, TB-AUTH-002, TB-AUTH-007, TB-AUTH-008, TB-DEL-001/002; ADR-005                                               |
| Prerequisites | P2-WS-0                                                                                                                   |
| Dependencies  | —                                                                                                                         |
| ADRs          | ADR-005 (Accepted product / Proposed schema → resolve schema)                                                             |
| Schema        | Identity type; status; soft-delete; internal role flags as designed                                                       |
| Backend       | Models, factories, policies stubs; registration remains disabled                                                          |
| Frontend      | Minimal profile surfaces only if already in shell; no product panels                                                      |
| Authz risks   | Type confusion (Internal acting as Customer)                                                                              |
| Audit         | Create/update/deactivate identity with remarks                                                                            |
| Tests         | TEST-AUTH-001; type exclusivity; no register; no self-delete                                                              |
| Acceptance    | Identity cannot be both types; Fortify registration still off                                                             |
| DoD           | Migrations + Pest green; Memory/traceability updated                                                                      |
| Rollback      | Reverse migration only if no production data (non-prod first)                                                             |

---

### P2-WS-2 — Company, branch and membership

| Field         | Content                                                                                                  |
| ------------- | -------------------------------------------------------------------------------------------------------- |
| Objective     | Company/branch tenancy with explicit membership and Owner designation                                    |
| Scope         | Companies, branches, memberships, Owner uniqueness, Owner future-branch access, multi-company membership |
| Traceability  | TB-OWN-001, TB-OWN-007, TB-OWN-008, TB-SEC-001/002; ADR-002                                              |
| Prerequisites | P2-WS-1                                                                                                  |
| Dependencies  | P2-WS-1                                                                                                  |
| Schema        | companies, branches, company_memberships, branch_assignments, owner designation                          |
| Backend       | Resolve tenant from membership — never from client IDs                                                   |
| Frontend      | Workspace switcher scaffolding only if design allows; else API/Inertia props only                        |
| Authz risks   | Cross-company leakage; Owner vs member confusion                                                         |
| Audit         | Company/branch/membership mutations                                                                      |
| Tests         | Cross-company/branch negatives; Owner sees future branches; non-Owner explicit only                      |
| Acceptance    | Exactly one Owner per company; spoofed IDs rejected with real memberships                                |
| DoD           | Isolation suite extended and green                                                                       |
| Rollback      | Soft-deactivate entities; no hard delete                                                                 |

---

### P2-WS-3 — Permission Master and branch roles

| Field         | Content                                                                                            |
| ------------- | -------------------------------------------------------------------------------------------------- |
| Objective     | Separate Internal/Customer permission domains; branch-specific roles; explicit Delete              |
| Scope         | Permission Master CRUD (Internal-managed); roles; branch role assignment; cache invalidation hooks |
| Traceability  | TB-RBAC-001; PRD §6; Rules §3                                                                      |
| Prerequisites | P2-WS-2                                                                                            |
| Dependencies  | P2-WS-2                                                                                            |
| Schema        | permissions, roles, role_permissions, branch_role_assignments                                      |
| Backend       | Gates/policies; immediate invalidation of permission caches                                        |
| Frontend      | Admin Permission Master UI **after** design gate for those screens                                 |
| Authz risks   | Inferred Delete; stale permission caches                                                           |
| Audit         | Role/permission/assignment changes with remarks                                                    |
| Tests         | TEST-RBAC-001; Delete explicit; deactivated role/permission rules                                  |
| Acceptance    | Server denies unauthorized actions; changes apply immediately                                      |
| DoD           | Matrix tests green; no client-only authz                                                           |
| Rollback      | Deactivate roles/permissions; retain history                                                       |

---

### P2-WS-4 — Authentication and session enforcement (ADR-006 web-only)

| Field         | Content                                                                                                                                    |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Objective     | Enforce TB-AUTH-004 (web) / TB-AUTH-005 and revocation on Laravel sessions                                                                 |
| Scope         | Fortify + session auth; Customer one active web session; Internal multi-session; password/deactivation invalidation; **no** Sanctum/mobile |
| Traceability  | TB-AUTH-004 (web portion), TB-AUTH-005; ADR-006 Accepted                                                                                   |
| Prerequisites | P2-WS-0 (complete); P2-WS-1                                                                                                                |
| Dependencies  | Soft-depends on P2-WS-2 for membership-aware login redirects                                                                               |
| Schema        | Session tracking for Customer single-session as needed; **no** mobile device token tables                                                  |
| Backend       | Login hooks; revocation service; CSRF; secure cookies; session fixation controls                                                           |
| Frontend      | Auth UI within shell; **no** mobile client contract                                                                                        |
| Authz risks   | Session fixation; incomplete web revocation                                                                                                |
| Audit         | Revocation on admin/security actions audited where required                                                                                |
| Tests         | TEST-AUTH-004/005 (web); new Customer login revokes prior web session; Internal concurrent OK                                              |
| Acceptance    | Phase 2 exit session-revocation proofs for web auth events                                                                                 |
| DoD           | Web-only ADR-006 behaviour implemented; Sanctum **not** installed                                                                          |
| Rollback      | Retain Fortify web path                                                                                                                    |

**Parallel note:** P2-WS-1–3 may proceed after P2-WS-0. Mobile/Sanctum remain out of scope until a future ADR revision.

---

### P2-WS-5 — Administrative onboarding and invitations

| Field         | Content                                                                                                     |
| ------------- | ----------------------------------------------------------------------------------------------------------- |
| Objective     | Admin/invite provisioning without public registration; never overwrite passwords on identity reuse          |
| Scope         | Invite create/accept; Internal user creation by Super Admin; email uniqueness/verification per PO decisions |
| Traceability  | TB-AUTH-002, TB-AUTH-003; AUD-GAP-003; ADR-005                                                              |
| Prerequisites | Invite lifecycle PO decisions; P2-WS-1; P2-WS-4 for session on accept                                       |
| Dependencies  | P2-WS-1, P2-WS-2 (membership attach), P2-WS-4                                                               |
| Schema        | invitations table; expiry; status                                                                           |
| Backend       | Outbox for invite email (reuse ADR-009)                                                                     |
| Frontend      | Invite accept pages within shell/design                                                                     |
| Authz risks   | Invite token leakage; privilege escalation on accept                                                        |
| Audit         | Invite issued/accepted/revoked                                                                              |
| Tests         | Password unchanged on reuse; expired invite denied                                                          |
| Acceptance    | No public register; invite-only join paths                                                                  |
| DoD           | Outbox-backed invites; Pest coverage                                                                        |
| Rollback      | Expire invites; deactivate memberships                                                                      |

---

### P2-WS-6 — Deactivation, suspension and Owner Panel exception

| Field         | Content                                                                                                                          |
| ------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Objective     | Company/identity deactivation and suspension rules including DEC-002 Owner Panel exception                                       |
| Scope         | Company deactivate; Branch Panel blocked; Owner Panel admin/billing visibility; identity deactivate; last Super Admin protection |
| Traceability  | TB-OWN-002–005, TB-OWN-006 (Pending — do not invent), TB-AUTH-008, TB-DEL-*                                                      |
| Prerequisites | P2-WS-2, P2-WS-3, P2-WS-4                                                                                                        |
| Dependencies  | P2-WS-2–4                                                                                                                        |
| Schema        | Status enums; effective dates                                                                                                    |
| Backend       | Middleware/policies for panel access                                                                                             |
| Frontend      | Deactivated banners; Owner Panel limited surface **per design + Pending TB-OWN-006**                                             |
| Authz risks   | Blanket Owner deny incorrectly blocking Owner Panel                                                                              |
| Audit         | All deactivation transitions                                                                                                     |
| Tests         | TEST-OWN-002–005; Branch blocked; Owner Panel allowed for stated scope                                                           |
| Acceptance    | DEC-002 behavior proven                                                                                                          |
| DoD           | Negative + positive panel tests green                                                                                            |
| Rollback      | Reactivate only via authorized admin (not “restore deleted”)                                                                     |

---

### P2-WS-7 — Ownership transfer

| Field         | Content                                                                                                                                                                                        |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Objective     | Transfer company ownership with access and session effects                                                                                                                                     |
| Scope         | Internal SA/Admin transfer; new Owner must be existing Customer; revoke old Owner company access; grant new Owner; invalidate new Owner sessions; retain history; other memberships unaffected |
| Traceability  | TB-OWN-001; PRD ownership transfer rules                                                                                                                                                       |
| Prerequisites | P2-WS-2, P2-WS-3, P2-WS-4                                                                                                                                                                      |
| Dependencies  | P2-WS-2–4                                                                                                                                                                                      |
| Schema        | ownership_transfers history                                                                                                                                                                    |
| Backend       | Single audited transaction; session revoke via P2-WS-4 service                                                                                                                                 |
| Frontend      | Internal transfer UI after design gate                                                                                                                                                         |
| Authz risks   | Incomplete revocation; Owner uniqueness violation                                                                                                                                              |
| Audit         | Mandatory transfer record + remarks                                                                                                                                                            |
| Tests         | Transfer matrix; session invalidation; old Owner denied company                                                                                                                                |
| Acceptance    | Exactly one Owner after transfer; history immutable                                                                                                                                            |
| DoD           | Transfer feature tests green                                                                                                                                                                   |
| Rollback      | Compensating transfer only via new authorized transfer (no hard undo)                                                                                                                          |

---

### P2-WS-8 — Owner Panel, Branch Panel and dashboards (product UI)

| Field         | Content                                                                                              |
| ------------- | ---------------------------------------------------------------------------------------------------- |
| Objective     | Product panels and dashboards within Design Deliverables Gate                                        |
| Scope         | Owner Panel; Branch Panel; workspace selection; dashboards; responsive/accessible UI; Poppins/tokens |
| Traceability  | Design.md §14; TB-OWN-*; TB-DES-001 tokens already in shell                                          |
| Prerequisites | **G-DESIGN** artefacts reviewed; P2-WS-2–6 for data/authz                                            |
| Dependencies  | P2-WS-2, P2-WS-3, P2-WS-6                                                                            |
| Schema        | As needed for dashboard aggregates (read models only)                                                |
| Backend       | Inertia controllers; authorization on every action                                                   |
| Frontend      | React pages under `resources/js/pages`                                                               |
| Authz risks   | Panel confusion (non-Owner Owner Panel)                                                              |
| Audit         | Mutations from panels                                                                                |
| Tests         | Owner isolation UI+HTTP; a11y smoke as practical                                                     |
| Acceptance    | Non-Owner cannot open Owner Panel; Branch Panel respects membership                                  |
| DoD           | Design-reviewed screens shipped; TechnicalUiShell constraints not violated for undeveloped areas     |
| Rollback      | Feature flags / route disable                                                                        |

---

### P2-WS-9 — Phase 2 exit certification

| Field         | Content                                                                                                                                    |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Objective     | Prove Phase 2 exit criteria and document closure                                                                                           |
| Scope         | Full negative isolation; Owner isolation; session revocation; immediate permission invalidation; Memory/traceability; certification report |
| Traceability  | All Phase 2 TB-AUTH/OWN/RBAC/SEC IDs in scope                                                                                              |
| Prerequisites | P2-WS-1–8 complete for authorized scope                                                                                                    |
| Dependencies  | All prior                                                                                                                                  |
| Tests         | Dedicated Phase 2 certification suite                                                                                                      |
| Acceptance    | Phases.md exit sentence satisfied                                                                                                          |
| DoD           | `PHASE_2_FINAL_CERTIFICATION_REPORT.md` (future) + CI green                                                                                |
| Rollback      | N/A                                                                                                                                        |

---

## 4. Dependency graph and critical path

```text
P2-WS-0 (authz + ADR-006 + design intake)
    ├─► P2-WS-1 Identity
    │       └─► P2-WS-2 Company/branch/membership
    │               ├─► P2-WS-3 Permissions/roles
    │               │       ├─► P2-WS-6 Deactivation
    │               │       └─► P2-WS-7 Ownership transfer
    │               └─► P2-WS-5 Invitations (also needs P2-WS-4)
    └─► P2-WS-4 Sessions (ADR-006) ──┬──► P2-WS-5
                                     ├──► P2-WS-6
                                     └──► P2-WS-7
P2-WS-8 Panels (after G-DESIGN + WS-2/3/6)
P2-WS-9 Certification
```

### Critical path

`P2-WS-0 → P2-WS-1 → P2-WS-2 → P2-WS-3 → P2-WS-6/7 → P2-WS-8 → P2-WS-9`  
with **`P2-WS-4` joining before transfer/session-dependent features**.

### Parallelizable

- Design artefact production (outside code) // P2-WS-1–3 schema work after P2-WS-0
- `PO-P1-DEPLOY` smoke (Phase 1 closure) // Phase 2 planning
- Permission Master seed data design // membership model

### Decision blockers (updated 22 November 2026)

| Decision                                     | Status                                       | Blocks                                                           |
| -------------------------------------------- | -------------------------------------------- | ---------------------------------------------------------------- |
| ADR-006 web-only                             | **Accepted**                                 | Mobile/Sanctum only (future); P2-WS-4 unblocked for web sessions |
| Invite lifecycle                             | **Approved**                                 | —                                                                |
| Design artefacts (screen approval)           | **Not Started**                              | P2-WS-8 product screens                                          |
| Deactivated Owner Panel beyond admin/billing | **Resolved** — limited to admin/billing info | Inventing extra actions                                          |
| Internal Team customer files                 | **Blocked** by PO                            | Any Internal access path (enforce deny)                          |
| Multi-company file access                    | **Approved** — active company only           | Cross-company access                                             |
| PO-P1-DEPLOY                                 | **Deferred** (not waived)                    | Phase 1 formal closure only                                      |

---

## 5. Testing and security gates

For every implementing workstream:

1. Cross-tenant negative tests for any new HTTP/job/file path
2. Authorization on API, jobs, reports, exports, files
3. Audit atomicity for mutations (`AuditedTransaction`)
4. No hard delete/restore
5. Immediate permission/session invalidation where applicable
6. CI: Postgres migrate + Pest, Pint, Larastan, frontend checks

Phase 2 exit suite must explicitly cover Phases.md exit bullets.

---

## 6. Dependency approvals

- Any new Composer/NPM package: case-by-case PO approval (same as PO-P1-DEPS).
- Sanctum or mobile token packages: **forbidden in Phase 2**; require a future ADR revision / Product Owner authorization.

---

## 7. Memory and traceability

Update `docs/governing/Memory.md` and `Requirements-Traceability-Starter.md` only with verified implementation facts. Do not convert Pending → Confirmed without PO authority. Proposed new test IDs may be added as Pending until implemented.
