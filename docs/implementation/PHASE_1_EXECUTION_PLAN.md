# TruckBill Nexus — Phase 1 Engineering Foundation Execution Plan

**Status:** Draft — Pending Product Owner Review  
**Date:** 21 November 2026  
**Repository:** `/Users/ttc/Sites/truckbill/nexus`  
**Authorization for this document:** Phase 1 **planning only**. This plan does **not** authorize implementation.  
**Phase 0:** Formally approved and closed (PO-APPROVE-001, 21 November 2026).  
**DEC-004:** Phase 0 approval prerequisite is satisfied; Phase 1 coding starts only on **separate Product Owner implementation authorization**.

---

## 1. Executive summary

Phase 1 builds the **engineering foundation** for TruckBill Nexus on the existing Laravel 13 + React/Inertia starter kit: reproducible PostgreSQL/Redis environments, CI quality gates, tenant context primitives (without Phase 2 identity/RBAC workflows), append-only audit, private file pipeline with malware-scan gating, transactional outbox/idempotency/queues, observability, and a **technical UI shell** only.

The current repository is a **starter kit**, not a production foundation:

- Models: `User` only; no company/branch/audit/outbox schema.
- Fortify: login + password reset only; profile **hard delete** still present (conflicts with DEC-003/006 — must be removed/disabled in Phase 1 hardening).
- `.env.example` defaults to SQLite + database cache/queue/session; `phpunit.xml` expects PostgreSQL `nexus_test`; CI has **no** Postgres service.
- No Policies, no `api.php`, no Playwright app tests, no Sanctum.

**Critical path:** Accept or constrain **ADR-001, ADR-002, ADR-003, ADR-007, ADR-008, ADR-009** before irreversible schema/CI choices → baseline + CI → tenant context → audit + outbox → files → observability → technical UI shell → exit certification.

**Explicitly out of Phase 1:** Phase 2 identity/membership/roles; Owner/Branch product panels; Razorpay/billing (Phase 3); business modules; product UI behind Design Deliverables Gate.

---

## 2. Phase 1 scope and boundaries

### In scope (from `Phases.md` Phase 1)

| Area | In scope |
|---|---|
| Stack baseline | Laravel 13, React + Inertia, PostgreSQL, Redis/Valkey client usage |
| Dev ergonomics | macOS/Linux/Windows setup docs; env conventions |
| Quality | Pint, Larastan, Pest, frontend build/typecheck, CI gates |
| Tenant foundation | Explicit company/branch/owner/internal context; query/job/cache conventions; negative tests |
| Audit foundation | Append-only, atomic with mutations; actor/tenant; remark where required |
| Files | Private storage; validation; 2 MB; malware scan interface; authorized download |
| Async | Outbox; queues; idempotency; retry/DLQ; correlation IDs |
| Observability | Structured logs; correlation; health; masking; basic runbooks |
| UI | Technical shell only (layouts, tokens, Poppins, placeholders, auth surfaces under DEC-006/009) |
| Exit | CI green; tenant/audit/upload/queue reliability tested; non-production deploy |

### Out of scope / exclusions

| Exclusion | Reason |
|---|---|
| Owner/Branch product IA, workflows, billing screens | Design Deliverables Gate (`TB-DES-002`) |
| Identity provisioning, RBAC, Owner transfer, sessions product model | Phase 2 (`ADR-005` schema still Proposed; `ADR-006` Pending) |
| Razorpay, catalogue, entitlements | Phase 3 |
| Business modules (Enquiry, LR, Broker, OwnFleet, Partload, Accounting UI) | Phases 4–8 |
| Partload Expense Management | Excluded (`TB-PTL-EXP-001`) |
| Production infra lock-in (ECS, region, Octane) | Architecture candidates; not Phase 1 exit |
| Load personas / RPO-RTO | Pending (`TB-NFR-001/002`); Phase 11 |
| Converting Pending/Proposed to Confirmed/Accepted | PO-EXCEPT-001 |

### Starter-kit hardening included in Phase 1

| Item | Action |
|---|---|
| Profile hard delete | Remove/disable; align DEC-003/006 (`TB-AUTH-007`, `TB-DEL-001`) |
| Self-service email change | Remove/disable; align DEC-009 (`TB-AUTH-006`) |
| Registration | Keep disabled (already) |
| `verified` middleware drift | Clean or intentionally enable — decision in WS1 |

---

## 3. Architecture and technology baseline

| Layer | Approved direction | Current repo reality |
|---|---|---|
| App | Laravel 13 modular monolith | Laravel 13.43 flat starter (`laravel/react-starter-kit` name) |
| UI | React + Inertia + Poppins | React 19 + Inertia 3; fonts not brand-locked |
| DB | PostgreSQL | Local often pgsql; `.env.example` sqlite |
| Cache/queue/session | Redis (phpredis) per Architecture/agent baseline | Example defaults: database drivers |
| Auth | Fortify web; Sanctum only if ADR-006 Accepted | Fortify reset only; no Sanctum |
| Files | Private S3 + scan gate | Local disk; no scan pipeline |
| Events | Outbox + idempotent consumers | Jobs tables only; no outbox |
| Quality | Pint, Larastan, Pest, Playwright (Phase 1+) | Pint/Larastan/Pest in CI; no Playwright scripts |
| Observability | Structured logs; Sentry/OTel later as evaluated | Default Laravel logging |

**Modular layout:** Prefer a thin domain/support split under `app/` (or equivalent) once **ADR-001** is Accepted or explicitly authorized as “implement Proposed default”. Do not invent microservices.

---

## 4. Workstream breakdown

### WS-1 — Repository and engineering baseline

| Field | Content |
|---|---|
| **ID** | WS-1 |
| **Objective** | Make the starter a reproducible TruckBill engineering baseline |
| **Scope** | App structure conventions; pgsql/redis alignment; cross-OS setup docs; env naming; migration conventions; Pint/PHPStan config hygiene; package governance; starter auth hardening (DEC-006/009/003) |
| **Prerequisites** | Phase 0 approved; Product Owner Phase 1 **implementation** authorization; ADR-007 direction |
| **Dependencies** | Blocks meaningful CI (WS-2) and all schema work |
| **Likely files** | `.env.example`, `README`/`docs/implementation` setup guides, `composer.json` name/metadata, `phpunit.xml`, `config/*`, `app/Http/Controllers/Settings/*`, Fortify views, `AGENTS`-adjacent docs |
| **Technical decisions** | ADR-007 accept/constrain; ADR-001 folder layout; whether to keep SQLite as optional local override |
| **Implementation tasks** | Align example env to pgsql+redis; document Windows/macOS/Linux; define migration rules; remove self-delete/email change; establish coding boundaries doc |
| **Testing tasks** | Boot tests; auth regression (login/reset); assert delete/email self-service denied |
| **Acceptance criteria** | New contributor can boot against documented pgsql+redis; example env matches Architecture intent; hard delete/self email change gone; Pint/Larastan clean on baseline |
| **Exit evidence** | Setup doc PR; green local `composer ci:check` subset; Pest auth tests updated |
| **Risks / rollback** | Breaking local sqlite workflows — mitigate with documented override; auth UX change — expected |

### WS-2 — CI/CD and automated quality gates

| Field | Content |
|---|---|
| **ID** | WS-2 |
| **Objective** | CI proves the real stack (Postgres) and quality gates |
| **Scope** | GitHub Actions; Pint; Larastan; Pest; npm build/types; migration checks; dependency/security scan; PR requirements |
| **Prerequisites** | WS-1 env/phpunit alignment; ADR-007 |
| **Dependencies** | Enables safe merge of later workstreams |
| **Likely files** | `.github/workflows/tests.yml`, `composer.json` scripts, Dependabot, optional security workflow |
| **Technical decisions** | Postgres service image/version; Redis in CI or array/sync for unit subset; scan tool choice |
| **Implementation tasks** | Add Postgres (and Redis if required) services; migrate+test against `nexus_test`; fail on Pint/Larastan/test/build |
| **Testing tasks** | CI matrix smoke; intentional failing test to prove gate (then remove) |
| **Acceptance criteria** | PRs cannot merge red CI; tests run on pgsql; frontend build/typecheck required |
| **Exit evidence** | Green Actions run on main/PR; workflow docs |
| **Risks / rollback** | Flaky Redis — start with sync/array for most tests; dedicated Redis job later |

### WS-3 — Tenant context foundation

| Field | Content |
|---|---|
| **ID** | WS-3 |
| **Objective** | Server-side tenant context primitives without Phase 2 product identity |
| **Scope** | Context object(s) for company/branch/owner/internal; resolution middleware hooks; query scopes helpers; job payload context; cache key prefix helpers; DB constraints patterns; cross-tenant negative tests on **harness models** |
| **Prerequisites** | ADR-001, ADR-002 decisions; WS-1 |
| **Dependencies** | Required by WS-4/5/6 |
| **Likely files** | `app/Support/Tenant/*` or domain equivalent; middleware; migrations for minimal context tables **only if needed for harness**; factories; Pest isolation suite |
| **Technical decisions** | ADR-002 Accept; whether Phase 1 uses stub company/branch tables vs context-only with test doubles; RLS deferred (defence-in-depth later) |
| **Implementation tasks** | Context carrier; forbid trusting client IDs; job middleware; cache key helper; isolation tests |
| **Testing tasks** | Spoofed company/branch IDs fail; job without context fails closed; cache keys tenant-scoped |
| **Acceptance criteria** | `TB-SEC-001`–`004` foundation behaviours proven on harness; no Phase 2 membership UI |
| **Exit evidence** | Pest suite `TenantIsolation*`; architecture note linking ADR-002 |
| **Risks / rollback** | Overbuilding Phase 2 — strict scope; schema churn — keep minimal |

**Gap:** Full membership resolution is Phase 2. Phase 1 must document that context is **explicitly injected** for foundation tests and infrastructure, not full product authz.

### WS-4 — Audit foundation

| Field | Content |
|---|---|
| **ID** | WS-4 |
| **Objective** | Append-only audit written atomically with mutations |
| **Scope** | Audit writer service; immutable store; actor + tenant; old/new representation; remarks for edit/delete; cancel reason fields when used; rollback behaviour |
| **Prerequisites** | ADR-003; WS-3 |
| **Dependencies** | Used by later mutations; pairs with WS-6 outbox in same transaction when events exist |
| **Likely files** | `audit_records` migration; `App\Audit\*`; traits/helpers; tests |
| **Technical decisions** | ADR-003 Accept; JSON vs columnar old/new; retention N/A (no hard delete) |
| **Implementation tasks** | Schema; writer; transactional API; example harness mutation |
| **Testing tasks** | Success writes audit; failure rolls back audit; remark required on update/delete paths of harness |
| **Acceptance criteria** | `TB-AUD-001`–`003` behaviours on foundation harness; audits immutable |
| **Exit evidence** | Pest `AuditAtomicity*`; schema dump |
| **Risks / rollback** | Inventing Pending lifecycle fields — forbid; stick to Rules §4 confirmed behaviours |

### WS-5 — File/document infrastructure

| Field | Content |
|---|---|
| **ID** | WS-5 |
| **Objective** | Private uploads with validation and scan-before-availability |
| **Scope** | Storage abstraction; local + S3 adapter; extension/MIME/content checks; 2 MB; malware scan port; quarantine/unavailable until clean; authorized download; checksum/metadata; replace-then-cleanup |
| **Prerequisites** | ADR-008; WS-3 (tenant metadata) |
| **Dependencies** | Optional WS-4 for upload audit events |
| **Likely files** | `config/filesystems.php`; file services; scan driver interface + null/clam stub; controllers; tests |
| **Technical decisions** | Scanner product (ClamAV stub vs cloud); signed URL vs authenticated proxy (Architecture allows either) |
| **Implementation tasks** | Pipeline: receive → validate → store private → scan → mark available; deny public disks |
| **Testing tasks** | Oversize/MIME reject; unscanned not downloadable; failed scan quarantined; tenant isolation on download |
| **Acceptance criteria** | `TB-DOC-001` foundation met; no public ACL; `TB-DOC-002` retention counting prepared (full quota accounting may wait Phase 3) |
| **Exit evidence** | Pest file suite; security note |
| **Risks / rollback** | Real malware engine in CI — use stub interface in CI; production driver selected later |

### WS-6 — Transactional events and asynchronous processing

| Field | Content |
|---|---|
| **ID** | WS-6 |
| **Objective** | Reliable outbox + idempotent async processing |
| **Scope** | Outbox table; dispatcher; Redis/database queue config; idempotency keys store; retry/backoff; failed jobs/DLQ; correlation IDs; tenant on jobs |
| **Prerequisites** | ADR-009; WS-3; Redis strategy from ADR-007 |
| **Dependencies** | Complements WS-4 (same DB transaction pattern) |
| **Likely files** | `outbox_messages`, `idempotency_keys` migrations; jobs; console dispatcher; queue config |
| **Technical decisions** | ADR-009 Accept; polling vs listen; uniqueness of idempotency keys |
| **Implementation tasks** | Atomic business+outbox write; worker publishes; consumer idempotent; DLQ path |
| **Testing tasks** | Duplicate delivery safe; rollback leaves no orphan outbox; job without tenant fails |
| **Acceptance criteria** | `TB-EVT-001` foundation proven |
| **Exit evidence** | Pest outbox/idempotency suite; queue runbook section |
| **Risks / rollback** | Dual-write bugs — mandatory transactional tests |

### WS-7 — Observability

| Field | Content |
|---|---|
| **ID** | WS-7 |
| **Objective** | Operable foundation logs/metrics/health with secret masking |
| **Scope** | Structured logging; request/correlation IDs; `/up` + deeper health; queue depth signals; exception reporting hook (Sentry optional/evaluated); masking; runbooks |
| **Prerequisites** | WS-1; correlation woven into WS-3/6 |
| **Dependencies** | Parallel after baseline |
| **Likely files** | `config/logging.php`; middleware; health routes; `docs/implementation/runbooks/*` |
| **Technical decisions** | Whether to add Sentry/OTel packages in Phase 1 or stub interfaces (Architecture lists them; avoid undeclared deps without approval) |
| **Implementation tasks** | Log context; mask PII; health checks for db/redis/queue; runbooks for restart/fail-job |
| **Testing tasks** | Assert masked fields; health endpoint checks |
| **Acceptance criteria** | Correlation present on request logs; secrets absent from logs in tests; runbooks exist |
| **Exit evidence** | Sample log fixtures; runbook files |
| **Risks / rollback** | Premature vendor lock — prefer interfaces |

**Gap:** No dedicated Confirmed TB-* for “Sentry installed”; treat as engineering NFR under Architecture §13–14 without inventing Confirmed product requirements.

### WS-8 — Technical UI shell

| Field | Content |
|---|---|
| **ID** | WS-8 |
| **Objective** | Non-product shell proving Inertia + tokens + Poppins |
| **Scope** | App layout/nav scaffolding; shared non-product components; design-token CSS variables; Poppins; responsive shell; Fortify auth pages retained/adjusted for DEC-006/009; empty placeholders for tenant wiring demos |
| **Prerequisites** | WS-1 auth hardening; tokens can start early; **no** Design Deliverables Gate completion required for this shell |
| **Dependencies** | Optional WS-3 placeholder pages for context display in non-prod |
| **Likely files** | `resources/js/layouts/*`, `resources/css/app.css`, font loading, `pages` placeholders |
| **Technical decisions** | Font loading strategy (self-host vs provider) without changing DEC-007 |
| **Implementation tasks** | Token plumbing; Poppins; strip self-delete/email UI; placeholder route gated for local/testing |
| **Testing tasks** | Frontend typecheck/build; optional Playwright smoke on shell + login |
| **Acceptance criteria** | `TB-DES-001` applied to shell; **no** Owner/Branch product screens (`TB-DES-002` gate respected) |
| **Exit evidence** | Screenshots/Playwright; confirmation checklist that product IA absent |
| **Risks / rollback** | Scope creep into product UI — hard gate review |

### WS-9 — Testing and certification

| Field | Content |
|---|---|
| **ID** | WS-9 |
| **Objective** | Make Phase 1 exit measurable and evidenced |
| **Scope** | Cross-cutting suites; CI gates; non-prod deploy smoke; traceability test ID backfill for Phase 1 reqs |
| **Prerequisites** | WS-1–8 substantially complete |
| **Dependencies** | Final gate |
| **Likely files** | `tests/**`; Playwright config; `docs/testing/*`; traceability updates (test IDs only) |
| **Technical decisions** | Playwright install authorization (dependency change needs approval when implementing) |
| **Implementation tasks** | Consolidate suites; certification checklist run; update Memory with verified facts |
| **Testing tasks** | Full matrix in §9 below |
| **Acceptance criteria** | All mandatory exit checklist items green |
| **Exit evidence** | Certification report appendix; CI URL; deploy smoke log |
| **Risks / rollback** | Weakening tests — forbidden |

---

## 5. Ordered implementation sequence

```text
[PO: Authorize Phase 1 implementation]
        │
        ▼
 S0  Decide ADR-007, ADR-001 (and record Accept or “implement Proposed default”)
        │
        ▼
 WS-1  Baseline + auth hardening ─────────────────┐
        │                                           │
        ▼                                           │
 WS-2  CI (Postgres) ◄──────────────────────────────┘
        │
        ▼
 S1  Decide ADR-002, ADR-003, ADR-009, ADR-008
        │
        ├──────────────┬──────────────┐
        ▼              ▼              ▼
      WS-3           WS-7           WS-8 (tokens/Poppins early)
   Tenant ctx     Observability    technical shell (parallel)
        │              │              │
        ├──────────────┤              │
        ▼              ▼              │
      WS-4  Audit ◄────┘              │
        │                             │
        ├──────────┐                  │
        ▼          ▼                  │
      WS-6       WS-5                 │
   Outbox/Q    Files                  │
        │          │                  │
        └────┬─────┘                  │
             ▼                        │
           WS-9  Certification ◄──────┘
             │
             ▼
      Phase 1 Exit sign-off
```

### Sequential musts

1. ADR-007 before CI Postgres/Redis alignment.  
2. WS-1 before WS-2.  
3. ADR-002 before WS-3 schema/helpers.  
4. WS-3 before WS-4/5/6 tenant-aware behaviour.  
5. ADR-003 before WS-4 schema.  
6. ADR-009 before WS-6 outbox schema.  
7. ADR-008 before production-oriented scan driver choice (stub OK earlier).  
8. WS-9 after functional workstreams.

### Parallelizable

- WS-7 with WS-3/4 after baseline.  
- WS-8 token/Poppins with WS-1/2.  
- WS-5 and WS-6 after WS-3 (and respective ADRs).  
- Documentation/runbooks continuous.

---

## 6. Dependency matrix

| Workstream | Depends on | Blocks |
|---|---|---|
| WS-1 | PO Phase 1 auth; ADR-007/001 | WS-2, all |
| WS-2 | WS-1 | Safe merges |
| WS-3 | WS-1; ADR-002 | WS-4,5,6 |
| WS-4 | WS-3; ADR-003 | Domain mutations later |
| WS-5 | WS-3; ADR-008 | Module uploads later |
| WS-6 | WS-3; ADR-009 | Reliable async later |
| WS-7 | WS-1 | Operability |
| WS-8 | WS-1; DEC-006/009 | UX shell only |
| WS-9 | WS-1–8 | Phase 1 exit |

---

## 7. ADR decision register (Phase 1 impact)

| ADR | Status | Phase 1 impact | Blocks implementation? | Recommended decision | Latest safe decision point |
|---|---|---|---|---|---|
| ADR-001 Modular monolith structure | Proposed | Folder/package layout | Soft-blocks structure | Accept a concrete `app/` layout default for Phase 1 | Before first domain migration |
| ADR-002 Tenant context | Proposed | Entire WS-3 | **Yes** for WS-3 | Accept explicit context propagation; RLS later only | Before tenant migrations/helpers |
| ADR-003 Audit atomic mutation | Proposed | WS-4 schema/API | **Yes** for WS-4 | Accept append-only + same-TX write | Before audit migration |
| ADR-004 Soft delete / no restore | Accepted | Auth hardening; future schemas | No | Follow as-is | N/A |
| ADR-005 Identity model | Accepted product / Proposed schema | Do **not** implement Phase 2 schema | Avoid | Keep schema Proposed | Phase 2 |
| ADR-006 Web/mobile auth | Pending | Do not install Sanctum in Phase 1 | Avoid dependency | Leave Pending | Before Phase 2 mobile |
| ADR-007 PG + Redis local/CI | Proposed | WS-1/2 | **Yes** for CI realism | Accept pgsql+redis as required baseline; document overrides | Before CI change |
| ADR-008 Private files + scan | Proposed | WS-5 | **Yes** for scan strategy | Accept interface + private storage; stub scanner in CI | Before non-stub scanner wiring |
| ADR-009 Outbox + idempotency | Proposed | WS-6 | **Yes** for WS-6 | Accept outbox+idempotency primitives | Before outbox migration |
| ADR-010 Accounting posting | Pending | None in Phase 1 | No | Defer | Phase 8 |
| ADR-011 Phase numbering | Accepted | Sequencing | No | Follow | N/A |
| ADR-012 Cursor rule versioning | Accepted | Process | No | Follow | N/A |

**Rule:** Do not change ADR statuses in this planning task. Implementation must not treat Proposed as Accepted without Product Owner / architecture acceptance.

---

## 8. Requirement traceability mapping

### Mapped Phase 1 requirements (existing IDs)

| Requirement ID | Status | Workstreams |
|---|---|---|
| TB-SEC-001 | Confirmed | WS-3, WS-9 |
| TB-SEC-002 | Confirmed | WS-3 |
| TB-SEC-003 | Confirmed | WS-3, WS-6 |
| TB-SEC-004 | Confirmed | WS-3 |
| TB-AUD-001 | Confirmed | WS-4, WS-6, WS-9 |
| TB-AUD-002 | Confirmed | WS-4 |
| TB-AUD-003 | Confirmed | WS-4 |
| TB-DEL-001 | Resolved by User Decision | WS-1 (hardening), WS-4 |
| TB-AUTH-006 | Resolved by User Decision | WS-1, WS-8 |
| TB-AUTH-007 | Resolved by User Decision | WS-1, WS-8 |
| TB-AUTH-002 | Confirmed | WS-1 (keep registration off) |
| TB-DOC-001 | Confirmed | WS-5, WS-9 |
| TB-DOC-002 | Confirmed | WS-5 (prepare; full quota Phase 3) |
| TB-EVT-001 | Confirmed | WS-6, WS-9 |
| TB-DES-001 | Resolved by User Decision | WS-8 |
| TB-DES-002 | Confirmed | WS-8 boundary / Design Gate |
| TB-PLT-001 | Resolved by User Decision | Satisfied by Phase 0 approval; Phase 1 still needs impl auth |
| TB-PLT-002 | Resolved by User Decision | All sequencing |

### Explicit gaps (do not invent Confirmed IDs)

| Gap | Handling |
|---|---|
| CI Postgres service | Engineering task under Architecture §15 / ADR-007 — track in plan/checklist |
| Observability vendor (Sentry/OTel) | Architecture §14 — optional/evaluated; interface-first |
| Playwright adoption | TEST_STRATEGY — add when implementation authorized (dependency approval) |
| Non-prod deployment target | Architecture candidates — define in WS-9 without locking ECS |
| Full membership resolution | Phase 2 — WS-3 uses explicit context injection |

### Must not touch as Confirmed in Phase 1

Pending: `TB-OWN-006`, `TB-SUB-STATE-001`, `TB-ACC-003`, `TB-TAX-001`, `TB-NFR-001`, `TB-NFR-002`, `TB-INVITE-001`  
Deferred: `TB-SUP-001`, `TB-DEF-001`  
Excluded: `TB-PTL-EXP-001`

---

## 9. Testing and quality gates

Aligned to `docs/testing/TEST_STRATEGY.md` (Phase 1 subset):

| Gate | Tool | Mandatory for Phase 1 exit? |
|---|---|---|
| Pint | `vendor/bin/pint` | Yes |
| Larastan | level per `phpstan.neon` | Yes |
| Pest unit/feature/integration | `php artisan test` | Yes |
| Tenant isolation suite | Pest | Yes |
| Audit atomicity suite | Pest | Yes |
| File validation/scan stub suite | Pest | Yes |
| Outbox/idempotency suite | Pest | Yes |
| Frontend types + production build | `npm run types:check` / build | Yes |
| Playwright shell smoke | Playwright | Recommended (install needs impl authorization) |
| Dependency vulnerability scan | CI action | Recommended → treat as Yes if feasible without new paid services |
| k6 / load | k6 | No (Phase 11) |
| Backup/restore drills | Runbooks | No (Phase 11 / `TB-NFR-002`) |

**CI minimum:** Postgres service; migrate; Pest; Pint; Larastan; frontend check/build.

---

## 10. Security and tenant-isolation gates

| Gate | Evidence |
|---|---|
| No public object storage | Config + test |
| Scan-before-availability | File tests |
| No self-service account delete | Route absent + Pest |
| No self-service email change | Route/UI absent + Pest |
| Registration remains disabled | Fortify features + Pest |
| Client tenant IDs untrusted | Isolation tests |
| Jobs require tenant context | Job tests fail-closed |
| Cache keys tenant-scoped | Unit/feature tests |
| Secrets masked in logs | Logging tests |
| No hard delete APIs introduced | Code review + Pest |

---

## 11. Technical UI shell and design restrictions

**Allowed**

- App chrome, layouts, shared primitives  
- Design tokens + Poppins (`TB-DES-001`)  
- Auth pages: login / forgot / reset  
- Non-product placeholders for foundation demos  

**Forbidden until Design Deliverables Gate**

- Owner Panel / Branch Panel product IA  
- Enquiry/LR/Broker/OwnFleet/Partload/Accounting/Billing product screens  
- Treating “UI shell” as permission for workflows (`Phases.md`, `TB-DES-002`)

Design artefacts in `PHASE_0_DELIVERABLES.md` remain **Not Started** and are **not** Phase 1 exit blockers for the *technical* shell, but **are** blockers for any product-specific UI.

---

## 12. Phase 1 exit certification checklist

### Mandatory

- [ ] CI green on main/PR with PostgreSQL-backed Pest  
- [ ] Documented macOS/Linux/Windows setup reproduces boot  
- [ ] `.env.example` reflects pgsql + redis intent (or documented approved override)  
- [ ] Tenant isolation negative tests pass (`TB-SEC-001`–`004` foundation)  
- [ ] Audit atomicity tests pass (`TB-AUD-001`–`003` foundation)  
- [ ] Upload validation + scan-gate tests pass (`TB-DOC-001`)  
- [ ] Outbox + idempotency + queue reliability tests pass (`TB-EVT-001`)  
- [ ] Self-service delete/email change removed; registration off  
- [ ] Technical UI shell validated (build + smoke); **no** product panels shipped  
- [ ] Security gates in §10 completed  
- [ ] Non-production deployment smoke verified (target defined without premature infra lock-in)  
- [ ] Runbooks for queue/fail-job/restart exist  
- [ ] Memory updated with **verified** implementation facts only  
- [ ] Traceability acceptance-test IDs filled for implemented Phase 1 requirements  

### Recommended improvements (not mandatory exit)

- [ ] Playwright CI job  
- [ ] Dependency scanning badge/report archived  
- [ ] Real malware engine in non-prod (beyond stub)  
- [ ] Composer package rename from starter-kit  
- [ ] OpenTelemetry exporter wired  

---

## 13. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Implementing Phase 2 identity early | Hard scope boundary in WS-3; PR checklist |
| Treating Proposed ADRs as Accepted | Decision register; PO accept before irreversible migrations |
| CI/sqlite drift continues | ADR-007 + WS-2 Postgres service |
| Public file exposure | Private disk only; tests |
| Dual-write outbox bugs | Same-TX tests; fail CI on orphan rows |
| Product UI scope creep | Design Gate enforcement; codeowners/review |
| Starter hard delete left in place | WS-1 first hardening tasks |
| Dependency installs without approval | List required packages in PO authorization request |

---

## 14. Deferred work and explicit exclusions

| Item | Defer to |
|---|---|
| Full identity/RBAC/Owner workflows | Phase 2 |
| Sanctum/mobile single-session | Phase 2 after ADR-006 |
| Razorpay / entitlements | Phase 3 |
| Business modules | Phases 4+ |
| Accounting sync/async | ADR-010 / Phase 8 |
| Load/RPO certification | Phase 11 |
| Partload Expense Management | Excluded forever unless PO changes DEC-005 |
| Owner Panel action inventory (`TB-OWN-006`) | Pending — Phase 2 |
| Support module placement | Deferred |

---

## 15. Product Owner decisions required before implementation

| Decision ID | Topic | Why needed before coding |
|---|---|---|
| **PO-P1-AUTH** | Authorize Phase 1 **implementation** (this plan alone is insufficient) | DEC-004 sequencing / agent rules |
| **PO-P1-ADR-007** | Accept or constrain PostgreSQL+Redis local/CI baseline | Unblocks WS-1/2 |
| **PO-P1-ADR-001** | Accept modular folder layout default | Unblocks structure |
| **PO-P1-ADR-002** | Accept tenant context approach for Phase 1 | Unblocks WS-3 |
| **PO-P1-ADR-003** | Accept audit schema/atomicity approach | Unlocks WS-4 |
| **PO-P1-ADR-008** | Accept file/scan interface strategy (stub in CI OK) | Unlocks WS-5 |
| **PO-P1-ADR-009** | Accept outbox/idempotency primitives | Unlocks WS-6 |
| **PO-P1-DEPS** | Approve any new packages (Playwright, scan client, Sentry, etc.) when requested | Dependency governance |
| **PO-P1-DEPLOY** | Name non-production deployment target for exit smoke | Exit criterion |

Optional (not blocking technical shell): progress Design Deliverables for later product UI.

---

## Appendix A — Current repository snapshot (planning baseline)

| Item | Observation |
|---|---|
| Laravel | 13.43.0 |
| Fortify | 1.43.0 — `Features::resetPasswords()` only |
| Inertia/React | 3.x / 19.x |
| Migrations | users/sessions, cache, jobs only |
| Models | `User` only |
| CI | `.github/workflows/tests.yml` — PHP 8.5, Node 22, no Postgres service |
| Tests | ~25 Pest tests; 2FA skipped |
| Profile destroy | Hard delete present — Phase 1 must remove |

## Appendix B — Document control

| Field | Value |
|---|---|
| Plan status | Draft — Pending Product Owner Review |
| Implementation authorized | **No** |
| Next step | PO review → issue Phase 1 implementation authorization referencing this plan and required ADR decisions |
