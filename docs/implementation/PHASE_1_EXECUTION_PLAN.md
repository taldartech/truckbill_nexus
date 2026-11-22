# TruckBill Nexus — Phase 1 Engineering Foundation Execution Plan

**Status:** Approved — Phase 1 Implementation Authorized  
**Date:** 21 November 2026 (authorized for implementation)  
**Repository:** `/Users/ttc/Sites/truckbill/nexus`  
**Authorization:** **PO-P1-AUTH APPROVED** (21 November 2026) — Phase 1 Engineering Foundation implementation authorized under this plan. Satisfies DEC-004 separate implementation authorization.  
**Phase 0:** Formally approved and closed (PO-APPROVE-001, 21 November 2026).  
**Authorization record:** `docs/engineering/PHASE_1_AUTHORIZATION_RECORD.md`  
**PO-EXCEPT-001:** Pending, Deferred, Excluded and Proposed product-requirement statuses remain unchanged.  
**Remaining Pending ADRs:** ADR-006 (no Sanctum), ADR-010 (no accounting posting architecture).

---

## 1. Executive summary

Phase 1 builds the **engineering foundation** on the Laravel 13 + React/Inertia starter: PostgreSQL/Redis baseline, **early mandatory CI** (migrations + Pest on Postgres), tenant-context primitives (not Phase 2 identity), atomic audit + outbox transaction boundaries, private file/scan pipeline, observability, and a **technical UI shell** only.

**Starter-kit reality (must be corrected in Phase 1):**

| Issue                                                         | Phase 1 disposition                                                                                                                             |
| ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `ProfileController::destroy` hard-deletes users               | **Remove** routes, controller action, form requests, Inertia UI, Wayfinder actions, and tests that assert hard delete — align DEC-003 / DEC-006 |
| Self-service profile email change                             | **Remove** self-service email update path — align DEC-009                                                                                       |
| Registration                                                  | Keep disabled                                                                                                                                   |
| `.env.example` SQLite + database drivers; CI without Postgres | Align to ADR-007 once Accepted; CI must migrate+test on PostgreSQL                                                                              |
| No tenant/audit/outbox/file-scan                              | Build foundations only after formal ADR gate                                                                                                    |

**Critical path (serial foundation):**  
Per-ADR acceptances as each workstream needs them → **WS-1** baseline + hard-delete/email removal → **WS-2** CI (mandatory early) → **WS-3** tenant context → **WS-4** audit / **WS-5** files / **WS-6** outbox (shared TX rules where applicable) → **WS-9** exit certification.

**Parallel after WS-1 (not on the critical path):** **WS-7** observability and **WS-8** technical UI shell may proceed once WS-1 starter security corrections are done; they must still join WS-9 certification.

**Not Phase 1:** Phase 2 identity/membership/sessions/RBAC; Sanctum (`ADR-006` Pending); accounting posting architecture (`ADR-010` Pending); Owner/Branch product UI; Razorpay; business modules; microservices.

---

## 2. Phase 1 scope and boundaries

### 2.1 In scope

| Area                         | In scope                                                                                                       |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Engineering baseline         | Laravel 13 structure, React/Inertia, PostgreSQL, Redis, cross-OS setup, env conventions, migration conventions |
| Starter security corrections | Remove self-service hard-delete (all surfaces); remove self-service email change; keep registration off        |
| CI                           | Early mandatory gate: migrate + Pest on PostgreSQL; Pint; Larastan; frontend build/types                       |
| Tenant foundation            | Explicit company/branch/owner/internal context on HTTP, jobs, events, cache, storage                           |
| Audit                        | Append-only; atomic with business mutations; actor/tenant; remarks where required                              |
| Files                        | Private storage; 2 MB; MIME/extension validation; malware-scan interface; authorized download                  |
| Async                        | Outbox; queues; idempotency; retry/DLQ; correlation IDs; tenant on jobs                                        |
| Observability                | Structured logs; correlation; health; masking; runbooks                                                        |
| UI                           | Technical shell only — layouts, tokens, Poppins, placeholders; auth pages under DEC-006/009                    |

### 2.2 Phase 1 vs Phase 2 (hard split)

| Concern                                              | Phase 1                                | Phase 2                                                                                                                                                                 |
| ---------------------------------------------------- | -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Login / password reset (Fortify)                     | Keep / harden                          | Extend as needed                                                                                                                                                        |
| Self-service account delete                          | **Remove completely**                  | Later account lifecycle only via the relevant module’s approved requirements, permissions, dependency checks and audit rules — **not** a universal soft-delete workflow |
| Self-service email change                            | **Remove completely**                  | **Authorized administration only** (DEC-009; remaining Pending details stay Pending)                                                                                    |
| Registration                                         | Remains off                            | Invite/provisioning (`TB-INVITE-001` Pending)                                                                                                                           |
| Company/branch **context carrier**                   | Yes (injected/explicit)                | Yes + membership resolution                                                                                                                                             |
| Membership, roles, Owner transfer, Permission Master | **No**                                 | **Yes**                                                                                                                                                                 |
| Customer single-session / mobile tokens              | **No** (`ADR-006` Pending; no Sanctum) | After ADR-006                                                                                                                                                           |
| Policies for product resources                       | Harness-only as needed                 | Full RBAC                                                                                                                                                               |
| Owner/Branch product panels                          | **No**                                 | After Design Deliverables Gate + Phase 2                                                                                                                                |

### 2.3 Exclusions and later-phase dependencies

| Exclusion                                       | Depends on / defer to                                 |
| ----------------------------------------------- | ----------------------------------------------------- |
| Owner/Branch product IA & workflows             | Design Deliverables Gate (`TB-DES-002`); Phase 2+     |
| Identity provisioning, RBAC, ownership transfer | Phase 2; `ADR-005` schema; `TB-INVITE-001`            |
| Sanctum / mobile session registry               | Phase 2; **ADR-006 remains Pending**                  |
| Razorpay, catalogue, entitlements               | Phase 3                                               |
| Enquiry webhook contract detail                 | Phase 4                                               |
| Business modules                                | Phases 4–8                                            |
| Accounting sync vs async posting                | Phase 8; **ADR-010 remains Pending**                  |
| Load personas / RPO-RTO                         | Phase 11; `TB-NFR-001/002` Pending                    |
| Partload Expense Management                     | Excluded (`TB-PTL-EXP-001`)                           |
| Microservices                                   | Forbidden (Architecture: modular monolith)            |
| Unnecessary new dependencies                    | Require Product Owner approval at implementation time |

---

## 3. Architecture and technology baseline

| Layer                   | Direction                                                   | Phase 1 constraint                                        |
| ----------------------- | ----------------------------------------------------------- | --------------------------------------------------------- |
| Application             | Laravel 13 modular monolith                                 | No microservices; layout per **Accepted** ADR-001         |
| UI                      | React + Inertia; Poppins                                    | Technical shell only                                      |
| Database                | PostgreSQL                                                  | Required for local intent + CI after ADR-007              |
| Cache / queue / session | Redis (phpredis)                                            | After ADR-007; tests may use array/sync where appropriate |
| Auth                    | Fortify web                                                 | No Sanctum until ADR-006 Accepted                         |
| Files                   | Private object storage + scan gate                          | After ADR-008                                             |
| Events                  | Transactional outbox + idempotent consumers                 | After ADR-009                                             |
| Quality                 | Pint, Larastan, Pest; Playwright optional with dep approval | CI mandatory early                                        |

---

## 4. Formal ADR decision gate

### 4.1 Gate rule

**Each blocking ADR must be Accepted (or the Product Owner must authorize “implement this Proposed default” in writing) before that ADR’s related irreversible implementation decisions.** Unrelated preparatory work that does not lock in the ADR’s decision may proceed without waiting for every Phase 1 ADR.

| Rule                  | Enforcement                                                                                                                                                      |
| --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Proposed ≠ Accepted   | Agents/implementers must not treat Proposed as decided                                                                                                           |
| Per-ADR, not blanket  | Accept ADR-00N before irreversible work that depends on ADR-00N; do not block unrelated prep (e.g. docs, lint wiring, UI shell without layout package decisions) |
| Pending stays Pending | ADR-006, ADR-010 — no premature Sanctum or accounting posting architecture                                                                                       |
| Status changes        | Product Owner / architecture authority only — not this plan                                                                                                      |

### 4.2 ADR decision register

| ADR                                          | Status                             | Blocks                      | Required before                       | Recommended decision                                                        | Latest safe point         |
| -------------------------------------------- | ---------------------------------- | --------------------------- | ------------------------------------- | --------------------------------------------------------------------------- | ------------------------- |
| **ADR-001** Modular monolith structure       | **Accepted** (PO-P1-ADR-001)       | App folder layout           | WS-1 structure / first domain dirs    | Accepted concrete `app/` layout                                             | Accepted 21 November 2026 |
| **ADR-002** Tenant context propagation       | **Accepted** (PO-P1-ADR-002)       | WS-3                        | WS-3 start                            | Accepted explicit context on HTTP/jobs/events/cache/storage; RLS later only | Accepted 21 November 2026 |
| **ADR-003** Audit log + atomic mutation      | **Accepted** (PO-P1-ADR-003)       | WS-4                        | WS-4 start                            | Accepted append-only + same-TX audit write                                  | Accepted 21 November 2026 |
| **ADR-007** PostgreSQL + Redis local/CI      | **Accepted** (PO-P1-ADR-007)       | WS-1 env, **WS-2 CI**       | WS-1/WS-2                             | Accepted pgsql+redis baseline; document overrides                           | Accepted 21 November 2026 |
| **ADR-008** Private files + malware scanning | **Accepted** (PO-P1-ADR-008)       | WS-5 production scan choice | WS-5 non-stub drivers                 | Accepted private storage + scan interface; CI stub OK                       | Accepted 21 November 2026 |
| **ADR-009** Outbox + idempotency             | **Accepted** (PO-P1-ADR-009)       | WS-6                        | WS-6 start                            | Accepted outbox + idempotency primitives                                    | Accepted 21 November 2026 |
| ADR-004 Soft delete / no restore             | Accepted                           | —                           | Follow                                | N/A                                                                         | N/A                       |
| ADR-005 Identity model                       | Accepted product / Proposed schema | Phase 2 schema              | Do not implement Phase 2 schema in P1 | Keep                                                                        | Phase 2                   |
| **ADR-006** Web/mobile auth                  | **Pending**                        | Sanctum/mobile              | **Do not install Sanctum**            | Leave Pending                                                               | Phase 2                   |
| **ADR-010** Accounting posting               | **Pending**                        | Accounting architecture     | **Do not finalize**                   | Leave Pending                                                               | Phase 8                   |
| ADR-011 Phase numbering                      | Accepted                           | Sequencing                  | Follow                                | N/A                                                                         | N/A                       |
| ADR-012 Cursor rule versioning               | Accepted                           | Process                     | Follow                                | N/A                                                                         | N/A                       |

### 4.3 Product Owner decisions (Phase 1 authorization)

| Decision ID       | Topic                                                | Status (21 November 2026)              |
| ----------------- | ---------------------------------------------------- | -------------------------------------- |
| **PO-P1-AUTH**    | Authorize Phase 1 **implementation**                 | **APPROVED**                           |
| **PO-P1-ADR-001** | Accept ADR-001                                       | **ACCEPTED**                           |
| **PO-P1-ADR-002** | Accept ADR-002                                       | **ACCEPTED**                           |
| **PO-P1-ADR-003** | Accept ADR-003                                       | **ACCEPTED**                           |
| **PO-P1-ADR-007** | Accept ADR-007                                       | **ACCEPTED**                           |
| **PO-P1-ADR-008** | Accept ADR-008                                       | **ACCEPTED**                           |
| **PO-P1-ADR-009** | Accept ADR-009                                       | **ACCEPTED**                           |
| **PO-P1-DEPS**    | Approve any new packages when requested              | **CASE-BY-CASE** (no blanket approval) |
| **PO-P1-DEPLOY**  | Name non-production deployment target for exit smoke | **DEFERRED** (22 Nov 2026; not waived) |

---

## 5. Transaction boundaries (mutations, audit, outbox)

### 5.1 Required atomic unit

For any foundation harness mutation that emits side effects:

```text
BEGIN
  1. Apply business mutation (create/update/lifecycle fields as allowed by the module’s approved rules)
  2. Write append-only audit row(s) for that mutation
  3. Insert outbox row(s) for events that must be published (if any)
COMMIT
```

| Rule                      | Detail                                                  |
| ------------------------- | ------------------------------------------------------- |
| Same database transaction | Steps 1–3 succeed or fail together                      |
| No orphan audit           | Failed business write ⇒ no audit row                    |
| No orphan outbox          | Failed business/audit write ⇒ no outbox row             |
| Publish after commit      | Outbox dispatcher reads committed rows only             |
| Consumers idempotent      | Duplicate delivery must not double-apply (`TB-EVT-001`) |
| Idempotency keys          | Separate store; reserve/complete around side effects    |

### 5.2 What is outside the business transaction

| Concern                | Boundary                                                                         |
| ---------------------- | -------------------------------------------------------------------------------- |
| Queue worker execution | After commit                                                                     |
| External malware scan  | After private store; availability flip after scan result                         |
| Email/notifications    | Via outbox/consumers — not inline in request TX unless explicitly designed later |
| Cache updates          | Prefer post-commit; never use cache as authz source (`TB-SEC-004`)               |

### 5.3 Negative tests (mandatory)

- Abort mid-mutation ⇒ zero audit, zero outbox.
- Audit writer failure ⇒ business row rolled back.
- Outbox insert failure ⇒ business + audit rolled back.
- Re-delivered outbox message ⇒ single observable side effect.

---

## 6. Tenant context requirements (HTTP, jobs, events, cache, storage)

After **ADR-002** is Accepted, Phase 1 must provide an explicit tenant context carrier:

| Surface             | Requirement                                                                                  | Negative test                    |
| ------------------- | -------------------------------------------------------------------------------------------- | -------------------------------- |
| **HTTP**            | Resolve/bind context server-side; never trust client `company_id`/`branch_id` (`TB-SEC-002`) | Spoofed IDs rejected / empty     |
| **Jobs**            | Job payload or middleware carries company/branch/owner/internal flags (`TB-SEC-003`)         | Job without context fails closed |
| **Events / outbox** | Payload includes tenant identifiers minimally; consumers re-validate                         | Cross-tenant consume denied      |
| **Cache**           | Keys include tenant scope; cache ≠ authorization (`TB-SEC-004`)                              | Cross-tenant key collision tests |
| **Storage / files** | Metadata includes tenant; download authz checks tenant                                       | Cross-tenant download 403        |

**Phase 1 limitation:** Full membership resolution is Phase 2. Foundation uses **explicit injected context** for harnesses and infrastructure; product membership UI/workflows are forbidden.

Also represent **Owner-level** and **Internal Team** context separation at the carrier level without implementing Permission Masters.

---

## 7. Workstream sequence (complete)

**Critical path** vs **parallel after WS-1** are distinct. WS-7 and WS-8 are **not** on the critical path and must not be drawn as dependents of WS-3.

```text
PO-P1-AUTH
        │
        ▼
 Per-ADR acceptances as each irreversible decision is reached
 (ADR-001/007 before related WS-1/WS-2; ADR-002 before WS-3; …)
        │
        ▼
 WS-1  Baseline + remove hard-delete/email self-service
        │
        ├──────────────────────────────┐
        ▼                              ▼
 WS-2  CI EARLY MANDATORY          WS-7  Observability
 (Postgres migrate + Pest)         WS-8  Technical UI shell
        │                          (parallel after WS-1;
        ▼                           not on critical path)
 WS-3  Tenant context
        │
        ├──────────────┬──────────────┐
        ▼              ▼              ▼
      WS-4           WS-6           WS-5
      Audit          Outbox/Q       Files
      (ADR-003)      (ADR-009)      (ADR-008)
        │              │              │
        └──────────────┴──────────────┘
                       │
                       ▼
              WS-9 Certification
              (also requires WS-7 + WS-8 complete)
```

| Order | ID                | Name                                                          | Path                    | Notes                                                       |
| ----- | ----------------- | ------------------------------------------------------------- | ----------------------- | ----------------------------------------------------------- |
| —     | **Per-ADR gates** | Accept each blocking ADR before its related irreversible work | Gate                    | Not a single all-or-nothing gate                            |
| 1     | **WS-1**          | Repository baseline + starter security corrections            | Critical                | Needs ADR-001/007 before irreversible layout/env/CI choices |
| 2     | **WS-2**          | CI/CD early mandatory gate                                    | Critical                | After WS-1; ADR-007                                         |
| 3     | **WS-3**          | Tenant context foundation                                     | Critical                | After WS-2; ADR-002                                         |
| 4a    | **WS-4**          | Audit foundation                                              | Critical                | After WS-3; ADR-003                                         |
| 4b    | **WS-6**          | Outbox / queues / idempotency                                 | Critical                | After WS-3; ADR-009; TX pattern with WS-4                   |
| 4c    | **WS-5**          | Files / scan                                                  | Critical                | After WS-3; ADR-008                                         |
| P     | **WS-7**          | Observability                                                 | **Parallel after WS-1** | Not blocked by WS-2/WS-3                                    |
| P     | **WS-8**          | Technical UI shell                                            | **Parallel after WS-1** | Not blocked by WS-2/WS-3; no product UI                     |
| 5     | **WS-9**          | Testing & Phase 1 exit certification                          | Exit                    | After critical path **and** WS-7/WS-8                       |

---

## 8. Workstream specifications

### WS-1 — Repository and engineering baseline (+ starter security)

| Field                                      | Content                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| ------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Objective**                              | Reproducible baseline; remove starter hard-delete and self-service email change                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| **Requirement IDs**                        | `TB-DEL-001`, `TB-AUTH-006`, `TB-AUTH-007`, `TB-AUTH-002`, `TB-PLT-002`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| **ADRs**                                   | **ADR-007** (blocking), **ADR-001** (blocking for layout), ADR-004 (follow)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| **Scope — hard-delete removal (explicit)** | Delete/disable **all** exposure of account self-destruction: `DELETE settings/profile` route; `ProfileController::destroy`; `ProfileDeleteRequest`; Inertia profile delete UI; Wayfinder generated delete actions; Pest tests that expect hard delete (replace with “forbidden/absent”); any API-equivalent if introduced. Phase 1 does **not** implement account lifecycle workflows. Later lifecycle operations must follow the **relevant module’s** approved requirements, permissions, dependency checks and audit rules — do **not** assume a universal soft-delete workflow. |
| **Scope — email**                          | Remove self-service email update from profile update validation/UI; later email changes are **authorized administration only** (DEC-009; Pending details stay Pending).                                                                                                                                                                                                                                                                                                                                                                                                             |
| **Scope — baseline**                       | Env conventions; pgsql/redis alignment post-ADR-007; cross-OS setup docs; migration conventions; coding boundaries; no microservices                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| **Acceptance criteria**                    | (1) `route:list` shows no profile destroy; (2) HTTP DELETE profile → 404/405/403 as designed; (3) UI has no delete-account control; (4) PATCH profile cannot change email; (5) registration still off; (6) setup doc boots pgsql+redis per ADR-007; (7) Pint/Larastan clean on touched PHP                                                                                                                                                                                                                                                                                          |
| **Negative tests**                         | Attempt delete account; attempt email change; attempt register                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| **Exit evidence**                          | Updated Pest auth/settings tests; setup guide; diff removing destroy path                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |

### WS-2 — CI/CD early mandatory engineering gate

| Field                   | Content                                                                                                                                                                                                    |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Objective**           | CI is a **mandatory early gate**: every PR runs PostgreSQL migrations + Pest before foundation merges proceed                                                                                              |
| **Requirement IDs**     | Engineering under Architecture §15; enables verification of `TB-SEC-*`, `TB-AUD-*`, `TB-EVT-001`, `TB-DOC-001` later                                                                                       |
| **ADRs**                | **ADR-007** (blocking)                                                                                                                                                                                     |
| **Scope**               | GitHub Actions: Postgres service; `php artisan migrate --force`; Pest; Pint; Larastan; npm types/build; fail closed                                                                                        |
| **Acceptance criteria** | (1) CI uses PostgreSQL not SQLite; (2) migrations apply cleanly; (3) Pest green on `nexus_test` (or CI DB name); (4) red Pint/Larastan/tests fail the job; (5) documented as required before merging WS-3+ |
| **Negative tests**      | Workflow proves failure on intentional lint/test break (then revert)                                                                                                                                       |
| **Exit evidence**       | Green Actions run; workflow YAML; note in Memory when implemented                                                                                                                                          |

**Sequencing rule:** WS-2 completes **before** merging WS-3+ foundation schemas to `main`.

### WS-3 — Tenant context foundation

| Field                   | Content                                                                                                                                                                                  |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Objective**           | Explicit tenant context on HTTP, jobs, events, cache, storage — without Phase 2 membership product                                                                                       |
| **Requirement IDs**     | `TB-SEC-001`, `TB-SEC-002`, `TB-SEC-003`, `TB-SEC-004`                                                                                                                                   |
| **ADRs**                | **ADR-002** (blocking); ADR-001                                                                                                                                                          |
| **Acceptance criteria** | Context carrier exists; HTTP rejects spoofed tenant IDs; jobs fail closed without context; outbox/event payloads carry tenant ids; cache keys tenant-scoped; file metadata tenant-scoped |
| **Negative tests**      | Cross-company read/write on harness; job missing context; cache key collision across tenants; storage download cross-tenant                                                              |
| **Exit evidence**       | Pest `TenantIsolation*` suite                                                                                                                                                            |

### WS-4 — Audit foundation

| Field                   | Content                                                                                                                                                               |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Objective**           | Append-only audit atomic with business mutations                                                                                                                      |
| **Requirement IDs**     | `TB-AUD-001`, `TB-AUD-002`, `TB-AUD-003`                                                                                                                              |
| **ADRs**                | **ADR-003** (blocking); ADR-004                                                                                                                                       |
| **Acceptance criteria** | Create needs no remark; update/delete require remark on harness; cancel reason when cancellation used; audit immutable; TX rules in §5 hold with outbox when combined |
| **Negative tests**      | Update without remark rejected; TX abort ⇒ no audit; audit failure ⇒ business rollback                                                                                |
| **Exit evidence**       | Pest `AuditAtomicity*`                                                                                                                                                |

### WS-5 — File/document infrastructure

| Field                   | Content                                                                                                                                                                |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Objective**           | Private uploads; validate; scan-before-availability; authorized download                                                                                               |
| **Requirement IDs**     | `TB-DOC-001`, `TB-DOC-002` (prepare retention counting; full quota Phase 3)                                                                                            |
| **ADRs**                | **ADR-008** (blocking for strategy)                                                                                                                                    |
| **Acceptance criteria** | Private disk only; 2 MB enforced; bad MIME/extension rejected; unscanned/failed scan not downloadable; tenant on metadata; replace-then-cleanup ordering               |
| **Negative tests**      | Public URL access fails; cross-tenant download; oversize; unscanned fetch                                                                                              |
| **Exit evidence**       | Pest file/security suite (`tests/Feature/FileSecurityTest.php`); `docs/engineering/FILE_STORAGE_FOUNDATION.md`                                                         |
| **Implementation**      | **Complete locally** (2026-11-22); remediation applied for production scanner refusal, scan exception fail-closed, post-commit cleanup — pending re-review before WS-6 |

### WS-6 — Outbox, queues, idempotency

| Field                   | Content                                                                                                                                       |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| **Objective**           | Reliable async with §5 transaction boundaries                                                                                                 |
| **Requirement IDs**     | `TB-EVT-001`, `TB-SEC-003`                                                                                                                    |
| **ADRs**                | **ADR-009** (blocking); ADR-007 for Redis queues                                                                                              |
| **Acceptance criteria** | Business+audit+outbox atomic; publish after commit; idempotent consumers; bounded retry; DLQ/failed-job path; correlation IDs; tenant on jobs |
| **Negative tests**      | Duplicate delivery; orphan outbox on rollback; job without tenant                                                                             |
| **Exit evidence**       | Pest outbox/idempotency suite (`tests/Feature/OutboxIdempotencyTest.php`); `docs/engineering/OUTBOX_IDEMPOTENCY_FOUNDATION.md`                |
| **Implementation**      | **Complete locally** (2026-11-22)                                                                                                             |

### WS-7 — Observability

| Field                   | Content                                                                                                                    |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| **Objective**           | Operable logs/health with masking                                                                                          |
| **Requirement IDs**     | Architecture §§13–14 (engineering NFR — no new Confirmed TB invented)                                                      |
| **ADRs**                | None blocking; avoid undeclared Sentry/OTel deps without PO-P1-DEPS                                                        |
| **Acceptance criteria** | Correlation/request IDs on logs; PII/secrets masked in tests; DB/Redis/queue health signals; runbooks for restart/fail-job |
| **Negative tests**      | Password/token absent from captured logs                                                                                   |
| **Exit evidence**       | Runbooks (`OPERATIONS_RUNBOOK.md`); `tests/Feature/ObservabilityTest.php`                                                  |
| **Implementation**      | **Complete locally** (2026-11-22)                                                                                          |

### WS-8 — Technical UI shell

| Field                   | Content                                                                                                                                          |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Objective**           | Non-product shell; Poppins; tokens; auth surfaces without delete/email self-service                                                              |
| **Requirement IDs**     | `TB-DES-001`, `TB-DES-002` (boundary), `TB-AUTH-006`, `TB-AUTH-007`                                                                              |
| **ADRs**                | —                                                                                                                                                |
| **Acceptance criteria** | Shell builds; Poppins applied; **no** Owner/Branch product screens; **no** delete-account or email-change UI; Design Deliverables Gate respected |
| **Negative tests**      | Assert product panel routes absent; delete/email controls absent                                                                                 |
| **Exit evidence**       | Build/typecheck; `tests/Feature/TechnicalUiShellTest.php`; `docs/engineering/UI_SHELL.md`                                                        |
| **Implementation**      | **Complete locally** (2026-11-22)                                                                                                                |

### WS-9 — Testing and Phase 1 exit certification

| Field                   | Content                                                                                               |
| ----------------------- | ----------------------------------------------------------------------------------------------------- |
| **Objective**           | Prove exit checklist; backfill acceptance-test IDs for implemented Phase 1 requirements only          |
| **Requirement IDs**     | All Phase 1 mapped IDs in §11                                                                         |
| **ADRs**                | —                                                                                                     |
| **Acceptance criteria** | §10 checklist mandatory items complete; traceability test IDs updated without status reclassification |
| **Negative tests**      | Full isolation/audit/file/outbox negative suites green                                                |
| **Exit evidence**       | Certification appendix; CI URL; deploy smoke log                                                      |

---

## 9. Dependency matrix

| Workstream    | Depends on                                                           | Blocks                                              | Path                    |
| ------------- | -------------------------------------------------------------------- | --------------------------------------------------- | ----------------------- |
| Per-ADR gates | PO-P1-AUTH; each ADR before its related irreversible decisions       | Related irreversible work only (not unrelated prep) | Gate                    |
| WS-1          | ADR-007 / ADR-001 before irreversible env/layout choices; PO-P1-AUTH | WS-2; enables WS-7/WS-8 parallel                    | Critical                |
| WS-2          | WS-1; ADR-007                                                        | Merging WS-3+ foundation schemas to `main`          | Critical                |
| WS-3          | WS-2; ADR-002                                                        | WS-4, WS-5, WS-6                                    | Critical                |
| WS-4          | WS-3; ADR-003                                                        | Combined TX with WS-6                               | Critical                |
| WS-6          | WS-3; ADR-009; TX pattern with WS-4                                  | Reliable async                                      | Critical                |
| WS-5          | WS-3; ADR-008                                                        | Upload features later                               | Critical                |
| WS-7          | WS-1 only                                                            | Operability (joins WS-9)                            | **Parallel after WS-1** |
| WS-8          | WS-1 only (starter security done)                                    | Shell only (joins WS-9)                             | **Parallel after WS-1** |
| WS-9          | Critical path WS-1–6 **and** WS-7 + WS-8                             | Phase 1 exit                                        | Exit                    |

---

## 10. Phase 1 exit certification checklist

### Mandatory

- [x] **ADR-001, 002, 003, 007, 008, 009** Accepted (PO-P1-ADR-*) — 21 November 2026
- [x] **ADR-006** later Accepted web-only for Phase 2 (PO-P2-ADR-006); Sanctum **not** installed in Phase 1 or Phase 2
- [x] **ADR-010** still Pending; accounting posting architecture **not** finalized
- [x] CI green: **PostgreSQL migrate + Pest**, Pint, Larastan, frontend build/types — tip `f5edb9e56465825a8dc2a00193a9935dc787c673`; Actions run [`44970560094`](https://github.com/taldartech/truckbill_nexus/actions/runs/44970560094) **success** (22 Nov 2026)
- [x] Cross-OS setup doc published (`docs/engineering/LOCAL_SETUP.md`)
- [x] Profile **hard-delete** removed from routes, controller, UI, tests (Wayfinder regenerated locally)
- [x] Self-service **email change** removed
- [x] Registration remains disabled
- [x] Tenant isolation negative tests pass (HTTP/jobs/events/cache/storage)
- [x] Audit atomicity + remark rules verified (`AuditAtomicityTest`; see `docs/engineering/AUDIT_FOUNDATION.md`)
- [x] Outbox TX boundaries + idempotency verified (`OutboxIdempotencyTest`)
- [x] Upload validation + scan-before-availability verified; no public files (`FileSecurity*`)
- [x] Technical UI shell only; Design Deliverables Gate not violated (`TechnicalUiShellTest`)
- [x] Security gates (§12) complete for Phase 1 foundation scope
- [ ] Non-production deployment smoke verified — **DEFERRED** (PO-P1-DEPLOY, 22 Nov 2026; not waived; Phase 1 formal closure still pending)
- [x] Runbooks present (`OPERATIONS_RUNBOOK.md`)
- [x] Memory updated with verified facts only
- [x] Traceability acceptance-test IDs filled for implemented Phase 1 reqs **without** changing Pending/Deferred/Excluded/Proposed statuses

### Recommended (not mandatory exit)

- [ ] Playwright CI smoke
- [ ] Real malware engine in non-prod (beyond stub)
- [ ] Dependency vulnerability report archived

---

## 11. Requirement traceability mapping

| Requirement ID | Status                    | Workstreams                                            |
| -------------- | ------------------------- | ------------------------------------------------------ |
| TB-SEC-001     | Confirmed                 | WS-3, WS-9                                             |
| TB-SEC-002     | Confirmed                 | WS-3                                                   |
| TB-SEC-003     | Confirmed                 | WS-3, WS-6                                             |
| TB-SEC-004     | Confirmed                 | WS-3                                                   |
| TB-AUD-001     | Confirmed                 | WS-4, WS-6, WS-9                                       |
| TB-AUD-002     | Confirmed                 | WS-4                                                   |
| TB-AUD-003     | Confirmed                 | WS-4                                                   |
| TB-DEL-001     | Resolved by User Decision | WS-1                                                   |
| TB-AUTH-006    | Resolved by User Decision | WS-1, WS-8                                             |
| TB-AUTH-007    | Resolved by User Decision | WS-1, WS-8                                             |
| TB-AUTH-002    | Confirmed                 | WS-1                                                   |
| TB-DOC-001     | Confirmed                 | WS-5, WS-9                                             |
| TB-DOC-002     | Confirmed                 | WS-5 (prepare)                                         |
| TB-EVT-001     | Confirmed                 | WS-5 TX with WS-6, WS-9                                |
| TB-DES-001     | Resolved by User Decision | WS-8                                                   |
| TB-DES-002     | Confirmed                 | WS-8 boundary                                          |
| TB-PLT-001     | Resolved by User Decision | Phase 0 done; **PO-P1-AUTH APPROVED** 21 November 2026 |
| TB-PLT-002     | Resolved by User Decision | Sequencing                                             |

**Do not reclassify:** Pending `TB-OWN-006`, `TB-SUB-STATE-001`, `TB-ACC-003`, `TB-TAX-001`, `TB-NFR-001`, `TB-NFR-002`, `TB-INVITE-001`; Deferred `TB-SUP-001`, `TB-DEF-001`; Excluded `TB-PTL-EXP-001`.

**Gaps (engineering, not invented Confirmed product reqs):** CI Postgres service; observability vendor choice; non-prod deploy target name.

---

## 12. Testing and quality gates

| Gate                                  | Tool                               | Mandatory?                     |
| ------------------------------------- | ---------------------------------- | ------------------------------ |
| Pint                                  | pint                               | Yes                            |
| Larastan                              | phpstan/larastan                   | Yes                            |
| Pest (incl. negatives)                | php artisan test on **PostgreSQL** | Yes                            |
| Tenant / audit / file / outbox suites | Pest                               | Yes                            |
| Frontend types + build                | npm                                | Yes                            |
| CI migrate on Postgres                | GitHub Actions                     | Yes — **early**                |
| Playwright shell smoke                | Playwright                         | Recommended (needs PO-P1-DEPS) |
| k6 / DR drills                        | —                                  | No (Phase 11)                  |

---

## 13. Security and tenant-isolation gates

| Gate                            | Evidence                         |
| ------------------------------- | -------------------------------- |
| No profile destroy route/UI/API | `route:list` + Pest + UI absence |
| No self-service email change    | Pest + UI absence                |
| Registration off                | Fortify + Pest                   |
| No public file ACL              | Config + Pest                    |
| Scan before availability        | Pest                             |
| Untrusted client tenant IDs     | Pest                             |
| Jobs require tenant context     | Pest                             |
| Tenant-scoped cache keys        | Pest                             |
| Secrets masked in logs          | Pest                             |
| No hard-delete APIs introduced  | Review + Pest                    |

---

## 14. Risks and mitigations

| Risk                              | Mitigation                                             |
| --------------------------------- | ------------------------------------------------------ |
| Treating Proposed ADR as Accepted | Per-ADR gate §4 (block related irreversible work only) |
| Phase 2 identity creep            | §2.2 hard split                                        |
| CI remains sqlite                 | WS-2 early mandatory                                   |
| Hard-delete left in Wayfinder/UI  | Explicit multi-surface removal checklist in WS-1       |
| Orphan audit/outbox               | §5 TX rules + negatives                                |
| Product UI scope creep            | Design Deliverables Gate; WS-8 negatives               |
| Sanctum installed “just in case”  | ADR-006 Pending — forbid                               |
| New dependencies without approval | PO-P1-DEPS                                             |

---

## 15. Outstanding Product Owner decisions

| Decision ID                                     | Status                              | Notes                                               |
| ----------------------------------------------- | ----------------------------------- | --------------------------------------------------- |
| **PO-P1-AUTH**                                  | **APPROVED** (21 November 2026)     | Phase 1 implementation authorized                   |
| **PO-P1-ADR-001 / 002 / 003 / 007 / 008 / 009** | **ACCEPTED**                        | See authorization record                            |
| **ADR-006**                                     | **Accepted web-only** (22 Nov 2026) | Phase 2; Sanctum still not installed                |
| **ADR-010**                                     | **Pending**                         | No accounting posting architecture                  |
| **PO-P1-DEPS**                                  | **CASE-BY-CASE**                    | No blanket dependency approval                      |
| **PO-P1-DEPLOY**                                | **DEFERRED** (not waived)           | Exit smoke outstanding; Phase 1 not formally closed |

---

## Appendix — Document control

| Field                     | Value                                                                            |
| ------------------------- | -------------------------------------------------------------------------------- |
| Plan status               | **Approved — Phase 1 Implementation Authorized**                                 |
| Implementation authorized | **Yes** (PO-P1-AUTH, 21 November 2026)                                           |
| Authorization record      | `docs/engineering/PHASE_1_AUTHORIZATION_RECORD.md`                               |
| Refinement                | Principal Architecture Review + clarifications; formal PO authorization recorded |
| Next step                 | Execute WS-1 → WS-2 → remaining workstreams per this plan                        |
