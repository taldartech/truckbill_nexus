# TruckBill Foundation Audit

**Date:** 2026-11-21  
**Repository:** `/Users/ttc/Sites/truckbill/nexus`  
**Branch:** `main` (`origin/main`, in sync)  
**Scope:** Read-only governing-specification and repository audit  
**Status of governing docs:** Expanded drafts for review — **not formally approved**  
**Authorization:** No Phase 1 / business implementation performed. No application code, dependencies, migrations, databases, remotes, or commits changed by this audit (except creation of this report file).

**Verified runtime baseline (local):**

- PHP 8.5.13
- Laravel Framework 13.43.0
- Fortify 1.43.0
- Inertia Laravel 3.7.0 / React Inertia 3.11.0 / React 19.3.1
- Pest 5.3.7
- Local `.env`: `DB_CONNECTION=pgsql`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `REDIS_CLIENT=phpredis`
- Test run during audit: **23 passed, 2 skipped, 62 assertions, 0 failures**

---

## Finding severity legend

| Severity | Meaning                                                                       |
| -------- | ----------------------------------------------------------------------------- |
| Critical | Blocks Phase 0 exit or creates immediate security/integrity risk if ignored   |
| High     | Must resolve before or at start of Phase 1                                    |
| Medium   | Resolve during Phase 0/1 planning; may block later phases if deferred blindly |
| Low      | Cleanup, clarity, or hygiene                                                  |
| Info     | Observation; no action required unless priorities change                      |

Each finding includes whether **user approval is required** before acting.

---

## 1. Governing Document Audit

### Overall assessment

The six governing documents plus the traceability starter form a coherent **proposed product baseline** covering identity, tenancy, operations (Broker / OwnFleet / Partload), accounting, subscription, documents, notifications, assets, design, and phased delivery. Coverage is broad and internally detailed for operations and subscription math.

They remain **drafts**. `docs/governing/README.md`, `Phases.md`, and `Memory.md` state that coding is gated on approval and that a line-by-line traceability review against the historical discussion record is still required. This audit does **not** re-label them approved.

### Completeness by area

| Area                             | Assessment                                                                                                  |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| Product scope                    | Strong for operational modules; subscription detail lives mainly in Rules, not PRD                          |
| Architecture consistency         | Strong intent (modular monolith, shared PostgreSQL tenancy, bounded contexts); infra still “validate later” |
| Business rules / calculations    | Very detailed for Partload Challan formula, OwnFleet allocations, TDS, subscription proration               |
| Identity / authorization         | Detailed Internal vs Customer, Owner designation, Permission Master                                         |
| Multi-tenancy / branch isolation | Explicit company/branch/owner scopes and Data Visibility                                                    |
| Accounting / financial integrity | Double-entry intent, immutability, reversals; mappings labelled as needing accountant validation            |
| Subscription / billing           | Detailed in Rules §15; Phase naming inconsistent with Phases.md                                             |
| Documents / audit logging        | Consistent soft-delete, remark, atomic audit, S3 private objects                                            |
| UI/UX                            | Design.md covers shell/patterns; pre-coding design deliverables not produced                                |
| Phase dependencies               | Clear Phase 0–11 sequence; hard gate at Phase 0                                                             |
| Acceptance criteria              | Module-level acceptance lists exist; not yet atomic per requirement                                         |
| Test coverage requirements       | Phases/gates require tenant, audit, accounting, entitlement tests; no detailed test matrix yet              |

### Findings

| ID          | Severity | Description                                                                                       | Source                              | Impact                                                              | Recommended resolution                                                                            | User approval required     |
| ----------- | -------- | ------------------------------------------------------------------------------------------------- | ----------------------------------- | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- | -------------------------- |
| AUD-GOV-001 | Critical | Governing package is still “draft / not approved”; Phase 0 exit not met                           | README, Phases, Memory              | No authorization to implement Phase 1 or business modules           | Complete review/approval of six docs + expand traceability                                        | Yes                        |
| AUD-GOV-002 | High     | Historical line-by-line traceability vs discussion record not done                                | README review caveat                | Undetected requirement drift from prior decisions                   | Run traceability review; mark Pending where undecided                                             | Yes                        |
| AUD-GOV-003 | High     | PRD has no dedicated Subscription/Billing section; commercial rules only in Rules §15             | PRD TOC vs Rules §15                | Product owners may miss billing requirements when reading PRD alone | Add PRD subscription section that references Rules, or elevate Rules as authoritative for billing | Yes                        |
| AUD-GOV-004 | Medium   | Design Guideline PDF referenced but not present under `docs/governing/`                           | Design.md / README source authority | Brand/visual QA cannot be verified from repo alone                  | Add PDF (or approved extract) under docs with clear authority note                                | Yes                        |
| AUD-GOV-005 | Medium   | Design §14 pre-coding deliverables (nav map, wireframes, field specs, component catalogue) absent | Design.md §14                       | UI implementation risk and rework                                   | Produce deliverables in Phase 0 before UI-heavy Phase 1 shell work                                | Yes                        |
| AUD-GOV-006 | Medium   | Accounting posting maps are “intent” pending accountant/tax advisor validation                    | PRD §15, Rules §13                  | Wrong ledger mappings if implemented as-is                          | External validation before Phase 8; keep Pending labels                                           | Yes                        |
| AUD-GOV-007 | Medium   | TDS/GST/transporter tax rules require professional legal validation                               | Rules status banner, Rules §6       | Compliance risk                                                     | Legal/tax review before production use of rule engine                                             | Yes                        |
| AUD-GOV-008 | Low      | Infrastructure choices (ECS, Octane, RDS Proxy, Mumbai, Valkey vs Redis) remain candidates        | Architecture §1, Memory             | Premature infra commitment                                          | Record ADR after validation; do not hard-code in app yet                                          | Yes (for production infra) |
| AUD-GOV-009 | Info     | Bounded contexts and event catalogue are well sketched for a modular monolith                     | Architecture §§2–6                  | Positive foundation for module boundaries                           | Preserve; implement contexts gradually per Phases                                                 | No                         |

---

## 2. Requirements Gaps and Ambiguities

| ID          | Severity | Description                                                                                                                         | Source                               | Impact                                                                                    | Recommended resolution                                                                | User approval required     |
| ----------- | -------- | ----------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ | ----------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- | -------------------------- |
| AUD-GAP-001 | High     | “Partload Expense Management excluded” appears only in README + TB-PTL-EXP-001; **not** defined in PRD or Rules body                | README, Traceability vs PRD/Rules    | Unclear what is excluded vs OwnFleet “Advanced Expense” add-on (Rules §15)                | Define exclusion explicitly in PRD/Rules; distinguish from OwnFleet expense/add-on    | Yes                        |
| AUD-GAP-002 | High     | Self-service profile email change / account deletion UX not specified for Customer vs Internal                                      | PRD §3 vs starter ProfileController  | Starter kit allows email change + hard delete that may conflict with invite-only identity | Specify whether users may change email/delete self; replace with admin-governed flows | Yes                        |
| AUD-GAP-003 | Medium   | Customer invitation / Internal CRM lead → identity provisioning flow underspecified (fields, expiry, password set)                  | PRD §3                               | Cannot implement Phase 2 onboarding without invention                                     | Specify invitation lifecycle, tokens, password rules, reuse of existing identity      | Yes                        |
| AUD-GAP-004 | Medium   | Mobile app single-session model mentioned; mobile client architecture not specified                                                 | PRD §3, Architecture §4              | Session registry / Sanctum / token design undecided                                       | ADR for web vs mobile auth; install Sanctum only if approved                          | Yes                        |
| AUD-GAP-005 | Medium   | Website enquiry webhook: token model, replay window, payload schema not fully specified                                             | PRD §9, Architecture §12             | Integration risk with marketing site                                                      | Publish webhook contract + security requirements before Phase 4                       | Yes                        |
| AUD-GAP-006 | Medium   | QR/Exchange Code: “anyone can scan; no login; no sender revocation” — abuse/PII exposure controls thin                              | PRD §14, Rules §12                   | Data leakage risk                                                                         | Define code entropy, TTL, rate limits, masked fields, audit of fetches                | Yes                        |
| AUD-GAP-007 | Medium   | RPO/RTO, DR region, backup drill criteria not defined                                                                               | Architecture §15                     | Cannot certify Phase 11 recovery                                                          | Define numeric RPO/RTO before production                                              | Yes                        |
| AUD-GAP-008 | Medium   | 5,000 concurrent users is a load objective without persona/RPS definition                                                           | Architecture §14, Phases §11         | Unmeasurable performance gate                                                             | Define workload personas and SLOs in Phase 0/1                                        | Yes                        |
| AUD-GAP-009 | Medium   | Product font: Design prefers Poppins for marketing but “product font optimized for legibility”; TB-DES-001 says Poppins for product | Design §1 vs Traceability TB-DES-001 | Font choice ambiguity                                                                     | Confirm product UI font family                                                        | Yes                        |
| AUD-GAP-010 | Low      | “Support tickets” / Internal Support dashboard referenced without Support module phase                                              | PRD §6                               | Orphan requirements                                                                       | Place Support module in Phases or mark deferred                                       | Yes                        |
| AUD-GAP-011 | Low      | Volumetric weight “future”; automated routing deferred — OK but not listed as explicit out-of-scope register                        | PRD §§10–11                          | Scope creep risk                                                                          | Maintain explicit deferred register                                                   | No (documentation hygiene) |
| AUD-GAP-012 | Info     | Traceability starter has area-level rows only; missing atomic field/calc/transition IDs                                             | Requirements-Traceability-Starter.md | Phase 0 incomplete                                                                        | Expand per Section 5 proposal below                                                   | Yes (for approval process) |

---

## 3. Conflicting Requirements Register

Do **not** silently resolve. Preserve draft status.

| ID          | Severity | Conflict                                                                                                                                                                                                                        | Sources                                                     | Impact                                                                | Recommended resolution                                                                                           | User approval required  |
| ----------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- | --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- | ----------------------- |
| AUD-CON-001 | Critical | **“Phase 1” means different things:** Phases.md Phase 1 = engineering foundation; Rules/Memory/README/Design say “Razorpay manual Phase 1”; Traceability TB-SUB-002 text says Phase 1 but Phase column says **3**               | Phases.md; Rules §15; Memory; README; Design §8; TB-SUB-002 | Wrong delivery sequencing; agents may implement billing in foundation | Adopt Phases.md numbering; rename billing language to “first billing delivery / Phase 3”; fix TB-SUB-002 wording | Yes                     |
| AUD-CON-002 | High     | **Company deactivation vs Owner Panel:** PRD §4 says deactivated company blocks Owner and users from Branch Panel (Owner Panel access for that company unclear). Rules §2 says Branch Panel blocked but **Owner Panel remains** | PRD §4; Rules §2                                            | Ambiguous Owner billing access on deactivated company                 | Clarify Owner Panel visibility and allowed actions when company is Deactivated                                   | Yes                     |
| AUD-CON-003 | High     | **Hard delete ban vs identity soft-delete vs starter hard delete:** Rules forbid hard delete/restore; PRD allows soft-delete of identity with no memberships; code hard-deletes user on profile destroy                         | Rules §4; PRD §4; `ProfileController::destroy`              | Data integrity / audit violation if left as-is                        | Remove self hard-delete; implement soft-delete/deactivation per approved identity rules                          | Yes                     |
| AUD-CON-004 | High     | **Coding gate vs agent “safe foundation work”:** Governing docs forbid coding until six docs approved; agent package allows limited foundation work where clear                                                                 | README; Phases; Memory; AGENT_EXECUTION_INSTRUCTIONS        | Process conflict                                                      | User must authorize either (a) approve docs, or (b) explicitly waive Phase 0 for named foundation tasks          | Yes                     |
| AUD-CON-005 | Medium   | **TB-PTL-EXP-001 cites PRD/Rules** but exclusion text exists only in README                                                                                                                                                     | Traceability vs PRD/Rules/README                            | False “Confirmed” traceability                                        | Move exclusion into PRD/Rules or fix citation                                                                    | Yes                     |
| AUD-CON-006 | Medium   | **Subscription suspension vs company deactivation** both block Branch Panel / keep Owner Panel — overlapping states not differentiated in UX/entitlement matrix                                                                 | Rules §§2, 15                                               | Ambiguous restriction behaviour                                       | Define state matrix: trial / grace / restricted / suspended / deactivated                                        | Yes                     |
| AUD-CON-007 | Low      | **AGENTS.md (Boost)** says create docs only when asked; TruckBill rule requires Memory/ADR/traceability updates during work                                                                                                     | Root `AGENTS.md`; `.cursor/rules/truckbill-project.mdc`     | Agent behaviour inconsistency                                         | Prefer TruckBill doc-update protocol for product work; keep Boost for framework style                            | Yes (policy preference) |
| AUD-CON-008 | Info     | Design “do not imply auto-renewal in Phase 1” uses same overloaded Phase 1 term as AUD-CON-001                                                                                                                                  | Design §8                                                   | Same sequencing confusion                                             | Tie language to Phases.md Phase 3                                                                                | Yes                     |

---

## 4. Repository and Technology Audit

### Stack vs specification

| Expected                   | Observed                                                                      | Status                          |
| -------------------------- | ----------------------------------------------------------------------------- | ------------------------------- |
| Laravel 13                 | 13.43.0 (`^13.17`)                                                            | Match                           |
| PHP 8.5                    | 8.5.13 runtime; composer `^8.3`                                               | Runtime match; constraint wider |
| React + Inertia            | React 19.3.1, Inertia 3.x                                                     | Match                           |
| PostgreSQL                 | Local `.env` `pgsql`; `.env.example` still `sqlite`                           | Local OK; example drift         |
| Redis                      | Local cache/queue/session Redis + phpredis; example defaults database drivers | Local OK; example drift         |
| Pest                       | Present; 25 tests                                                             | Match                           |
| Sanctum                    | Speculative in Architecture; **not installed**                                | Pending decision                |
| Playwright / k6            | Specced; **not installed**                                                    | Later phases                    |
| Modular monolith modules   | Specced; **no module packages / no tenant schema**                            | Not started                     |
| S3 / malware scan / outbox | Specced for Phase 1; **not present**                                          | Not started                     |

### Application shape

- Laravel React starter kit (`composer.json` name still `laravel/react-starter-kit`)
- Models: `User` only
- Migrations: users/sessions/password_reset_tokens, cache tables, jobs tables
- Routes: home, dashboard, settings (profile/security/appearance), Fortify login/logout/password reset
- No `api.php`, no Policies, no tenancy middleware, no audit tables
- Frontend: Inertia pages for welcome, dashboard, auth, settings; shadcn-style UI; Wayfinder
- CI: GitHub Actions PHP 8.5 + Node 22 + `composer ci:check` (Pint, PHPStan, Pest, npm check/types)
- Empty doc folders: `docs/architecture-decisions`, `docs/decisions`, `docs/implementation`, `docs/testing`

### Findings

| ID           | Severity | Description                                                                                                                       | Source                                                                | Impact                                                 | Recommended resolution                                                     | User approval required |
| ------------ | -------- | --------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- | ------------------------------------------------------ | -------------------------------------------------------------------------- | ---------------------- |
| AUD-REPO-001 | High     | No company/branch/tenant/audit schema or authorization layer                                                                      | `app/`, `database/`                                                   | Phase 1 exit criteria unmet                            | Design and implement after Phase 0 approval                                | Yes                    |
| AUD-REPO-002 | High     | `.env.example` defaults SQLite + database cache/queue/session; conflicts with documented Redis/PostgreSQL baseline                | `.env.example` vs Architecture / agent instructions / local `.env`    | New clones diverge; CI confusion                       | Update example for pgsql+redis **after approval**; keep secrets out of git | Yes                    |
| AUD-REPO-003 | High     | CI has no PostgreSQL (or Redis) service; `phpunit.xml` forces `pgsql`/`nexus_test`; `composer setup` uses `.env.example` (sqlite) | `.github/workflows/tests.yml`, `phpunit.xml`, `composer.json` scripts | CI may not reliably exercise the real test DB strategy | Add CI Postgres service + align migrate/test env                           | Yes                    |
| AUD-REPO-004 | Medium   | Fortify features: password reset only — registration off (good). Profile hard delete remains                                      | `config/fortify.php`, `ProfileController`                             | Starter behaviour conflicts with no-hard-delete        | Disable/remove account destruction path; add tests                         | Yes                    |
| AUD-REPO-005 | Medium   | `verified` middleware used while email verification / MustVerifyEmail not enabled                                                 | routes, User model, Fortify features                                  | Dead middleware; false sense of verification           | Remove `verified` or enable verification intentionally                     | Yes                    |
| AUD-REPO-006 | Medium   | User PHPDoc references `two_factor_*`; 2FA disabled; Security tests skip 2FA                                                      | User model, Fortify, SecurityTest                                     | Schema/docs/UI drift                                   | Clean 2FA remnants or explicitly defer with ADR                            | Yes                    |
| AUD-REPO-007 | Medium   | No Playwright/frontend component test harness despite Phase 1 exit mentioning frontend tests                                      | `package.json`, Phases §1                                             | Phase 1 incomplete                                     | Add Playwright (or approved alternative) in Phase 1 plan                   | Yes                    |
| AUD-REPO-008 | Low      | Composer package name still starter-kit; `APP_NAME=Laravel` in examples                                                           | `composer.json`, `.env.example`                                       | Branding/hygiene                                       | Rename when approved                                                       | Yes                    |
| AUD-REPO-009 | Low      | Sanctum/Reverb/Sentry/OTel/Terraform not present — acceptable if deferred with ADR                                                | Architecture vs composer/npm                                          | Avoid premature installs                               | Install only when phase requires                                           | No (defer)             |
| AUD-REPO-010 | Info     | Pest baseline green (23 pass / 2 skip) matches agent package claim                                                                | `php artisan test`                                                    | Good starter health                                    | Keep; do not weaken skips falsely                                          | No                     |
| AUD-REPO-011 | Info     | Git remote `taldartech/truckbill_nexus.git`; working tree clean except untracked `.cursorignore`                                  | `git status`                                                          | Local Cursor ignore not shared                         | Commit `.cursorignore` when user requests commit                           | Yes (to commit)        |
| AUD-REPO-012 | Info     | TruckBill Cursor rule is alwaysApply locally but `.cursor/` is gitignored                                                         | `.gitignore`, `.cursor/rules/truckbill-project.mdc`                   | Teammates won’t inherit rule via git                   | Decide versioning strategy for Cursor rules                                | Yes                    |

---

## 5. Security and Tenant-Isolation Assessment

| ID          | Severity | Description                                                                                                     | Source                                 | Impact                                              | Recommended resolution                                                   | User approval required |
| ----------- | -------- | --------------------------------------------------------------------------------------------------------------- | -------------------------------------- | --------------------------------------------------- | ------------------------------------------------------------------------ | ---------------------- |
| AUD-SEC-001 | Critical | Tenant isolation not implemented (no `company_id`/`branch_id`, no policies, no query scopes)                    | Architecture §3; codebase absence      | Any future feature without this is a data-leak risk | Treat tenant middleware/policies/constraints as Phase 1 blockers         | Yes                    |
| AUD-SEC-002 | High     | Hard user delete on profile destroy; no audit trail                                                             | `ProfileController::destroy`; Rules §4 | Irreversible identity loss; violates no-hard-delete | Remove endpoint or convert to soft-delete/deactivation with remark/audit | Yes                    |
| AUD-SEC-003 | High     | No privileged-action audit subsystem                                                                            | Architecture §5/§13; Rules §4          | Cannot meet audit atomicity exit criteria           | Design append-only audit + transactional outbox in Phase 1               | Yes                    |
| AUD-SEC-004 | Medium   | Password reset enabled — OK; public registration disabled — OK                                                  | Fortify config                         | Aligns with no public self-registration             | Preserve; add invite-based provisioning later                            | No                     |
| AUD-SEC-005 | Medium   | Strong password rules in AppServiceProvider (12+, mixed, uncompromised)                                         | AppServiceProvider                     | Positive                                            | Keep                                                                     | No                     |
| AUD-SEC-006 | Medium   | Production destructive DB commands prohibited                                                                   | AppServiceProvider                     | Positive                                            | Keep                                                                     | No                     |
| AUD-SEC-007 | Medium   | Env secrets present locally and gitignored (`.env`, `.env.testing`, `.env.backup`); only `.env.example` tracked | `.gitignore`, `git ls-files`           | Good secret hygiene                                 | Continue; never commit env backups                                       | No                     |
| AUD-SEC-008 | Medium   | File/S3 malware scanning and private object access not implemented                                              | Architecture §10                       | Phase 1 upload requirements unmet                   | Implement file service with scan gate before availability                | Yes                    |
| AUD-SEC-009 | Low      | CSP/security headers / WAF called out for AWS edge — not configured in app yet                                  | Architecture §§1,13                    | Expected for later infra                            | Track in Phase 1/11 security checklist                                   | No                     |
| AUD-SEC-010 | Info     | Login rate limiting present (5/min email+IP)                                                                    | FortifyServiceProvider                 | Positive starter control                            | Retain; extend per API later                                             | No                     |

**Tenant-isolation verdict:** Spec is clear; **implementation is absent**. RLS is correctly positioned as defence-in-depth only (Architecture §3) — do not rely on it as the sole control.

---

## 6. Architecture Risks

| ID           | Severity | Description                                                                                                  | Source                               | Impact                                                  | Recommended resolution                                          | User approval required   |
| ------------ | -------- | ------------------------------------------------------------------------------------------------------------ | ------------------------------------ | ------------------------------------------------------- | --------------------------------------------------------------- | ------------------------ |
| AUD-ARCH-001 | High     | Modular monolith bounded contexts not reflected in code structure yet; risk of “flat App\” growth            | Architecture §2                      | Cross-context coupling                                  | Agree module layout ADR before Phase 2+ features                | Yes                      |
| AUD-ARCH-002 | High     | Same-DB operational + accounting atomicity vs async posting tension                                          | Architecture §7                      | Financial inconsistency if async without pending states | ADR: sync posting for Phase 8 MVP vs outbox+pending             | Yes                      |
| AUD-ARCH-003 | High     | Cached counters forbidden as entitlement source of truth — needs rebuildable ledgers from day one of billing | Architecture §5/§8; Rules §15        | Quota bugs / revenue leakage                            | Design usage ledger before Phase 3                              | Yes                      |
| AUD-ARCH-004 | Medium   | Outbox + idempotency required in Phase 1 before most domains exist                                           | Phases §1; Architecture §6           | Over- or under-building risk                            | Minimal generic outbox/idempotency primitives first             | Yes                      |
| AUD-ARCH-005 | Medium   | Multi-session Internal vs single-session Customer needs session/device registry                              | Architecture §4                      | Auth rewrite if deferred too late                       | ADR in Phase 2; avoid premature Sanctum without decision        | Yes                      |
| AUD-ARCH-006 | Medium   | AWS SQS vs Redis queues; ElastiCache vs Redis; Valkey naming                                                 | Architecture §1; Memory              | Infra lock-in                                           | Keep Laravel queue abstraction; ADR after load tests            | Yes                      |
| AUD-ARCH-007 | Medium   | Website is sibling project — enquiry webhook coupling                                                        | PRD §9; TruckBill Cursor safety rule | Contract breakage across repos                          | Versioned webhook contract; do not modify `website/` from nexus | No (already constrained) |
| AUD-ARCH-008 | Low      | 5k concurrent / k6 certification deferred to Phase 11 but indexes/tenancy must anticipate scale earlier      | Architecture §14; Phases             | Late perf surprises                                     | Tenant-aware indexes and pagination standards in Phase 1        | Yes                      |

---

## 7. Phase 1 Readiness Assessment

### Phase 0 gate (prerequisite)

| Criterion                                            | Status                                                             |
| ---------------------------------------------------- | ------------------------------------------------------------------ |
| Six governing docs reviewed and approved             | **Not met** (explicit draft status)                                |
| Traceability matrix expanded                         | **Not met** (starter only)                                         |
| ADR / change-control / risk register / test strategy | **Not met** (folders empty; this audit is the first risk register) |

### Phase 1 exit criteria vs repo

| Exit criterion (Phases.md)                             | Status                                                             |
| ------------------------------------------------------ | ------------------------------------------------------------------ |
| Repository / Laravel 13 / React / Inertia / PostgreSQL | Partial — stack present; example/CI drift                          |
| Cross-OS setup docs                                    | Missing                                                            |
| CI green with intended DB                              | Partial — local tests green; CI Postgres alignment doubtful        |
| Static analysis (Larastan)                             | Present in CI                                                      |
| Pest                                                   | Present                                                            |
| Frontend tests                                         | Missing                                                            |
| Tenant context                                         | Missing                                                            |
| Audit atomicity                                        | Missing                                                            |
| File service / S3 / malware scan                       | Missing                                                            |
| Outbox / idempotency / queues                          | Queue tables exist; Redis used locally; outbox/idempotency missing |
| Observability                                          | Missing (Sentry/OTel not installed)                                |
| UI shell (Owner/Branch panels)                         | Missing (starter dashboard only)                                   |
| Non-production deployment                              | Not assessed / not configured here                                 |

### Verdict

**Phase 1 is not ready to begin as an authorized delivery phase** until Phase 0 approval (or an explicit user waiver listing permitted foundation tasks).

Engineering **can** prepare a Phase 1 plan and ADRs now. Implementation should wait for approval per governing hard gate (AUD-CON-004).

---

## 8. Recommended ADRs

Proposed ADR titles (create under `docs/architecture-decisions/` when approved). Do not invent the decisions themselves.

| ADR ID  | Topic                                                | Why needed                  | User approval required |
| ------- | ---------------------------------------------------- | --------------------------- | ---------------------- |
| ADR-001 | Modular monolith package/folder layout               | Prevent unstructured growth | Yes                    |
| ADR-002 | Tenant context propagation (HTTP, jobs, cache keys)  | Phase 1 blocker             | Yes                    |
| ADR-003 | Audit log schema and atomic mutation pattern         | Phase 1 blocker             | Yes                    |
| ADR-004 | Soft-delete / no-restore enforcement strategy        | Conflicts with starter kit  | Yes                    |
| ADR-005 | Identity model: Internal vs Customer discrimination  | Phase 2 prerequisite        | Yes                    |
| ADR-006 | Web + mobile session/token strategy (Sanctum or not) | Spec ambiguity              | Yes                    |
| ADR-007 | PostgreSQL + Redis as required local/CI defaults     | Example/CI drift            | Yes                    |
| ADR-008 | File storage: S3, scanning, replacement lifecycle    | Phase 1 uploads             | Yes                    |
| ADR-009 | Outbox and idempotency primitives                    | Phase 1 reliability         | Yes                    |
| ADR-010 | Accounting posting sync vs async                     | Financial integrity         | Yes                    |
| ADR-011 | Subscription phase naming vs Phases.md               | Resolve AUD-CON-001         | Yes                    |
| ADR-012 | Cursor rules / AGENTS.md versioning for the team     | Local-only rule today       | Yes                    |

---

## 9. Recommended Test Strategy

Aligned with Phases gates and `testing-best-practices` skill intent. Expand during Phase 0; do not weaken existing Pest suite.

### Layers

1. **Unit** — pure calculations (proration, Challan TOTAL, TDS, freight, weighted average) with fixed vectors
2. **Feature / HTTP** — authz, tenant isolation negatives, Form Requests, Inertia responses
3. **Integration** — DB constraints, transactions (mutation+audit), queue/outbox idempotency
4. **Browser (Playwright)** — critical auth and Owner/Branch shell flows (Phase 1+)
5. **Contract** — website webhook, Razorpay webhook signatures (Phase 3+)
6. **Load (k6)** — Phase 11 certification only after personas defined
7. **Security** — cross-tenant, IDOR, export/download, webhook replay

### Immediate (when foundation work authorized)

| Priority | Coverage                                                                          |
| -------- | --------------------------------------------------------------------------------- |
| P0       | Replace/remove hard-delete account tests; assert soft-delete or denial            |
| P0       | Tenant isolation helpers/factories (once schema exists) — cross-company 403/empty |
| P0       | Audit row created in same transaction as mutation                                 |
| P1       | CI Postgres service + RefreshDatabase against `nexus_test`                        |
| P1       | Registration remains disabled                                                     |
| P1       | Permission change invalidation (Phase 2)                                          |
| P2       | File upload validation, size/MIME, scan-unavailable state                         |
| P2       | Idempotent job/webhook processing                                                 |

### Traceability link

Every atomic requirement row must gain an **acceptance-test ID** before implementation (per starter file instruction).

---

## 10. Prioritized Action Plan

### P0 — Phase 0 (documentation / decisions only)

1. User reviews and either **approves** governing docs or returns annotated Pending list
2. Resolve **AUD-CON-001** (Phase numbering) and **AUD-CON-002** (Owner Panel on deactivated company)
3. Expand Requirements Traceability (see proposal below)
4. Authorize or deny limited foundation hardening under AUD-CON-004
5. Record ADR stubs for ADR-001–007

### P1 — Foundation hardening (only after approval / waiver)

1. Align `.env.example` + CI with PostgreSQL/Redis test strategy
2. Remove/replace profile hard delete; add soft-delete/deactivation posture
3. Clean verification/2FA drift
4. Introduce tenant context + audit primitives + tests
5. Minimal UI shell / design tokens per Design.md (no business modules)
6. File service skeleton + outbox/idempotency primitives

### P2 — Phase 2+ (blocked until Phase 1 exit)

Identity, company/branch, Owner Panel, Permission Masters — per Phases.md.

### Explicit non-actions (this audit)

- No Broker / OwnFleet / Partload features
- No dependency installs/upgrades
- No migrations
- No cloud resources
- No remote push
- No commit created by this audit

---

## Requirements Traceability — Proposed Expansion

**Original file not modified.** Proposed structure for a future `Requirements-Traceability.md` (Phase 0):

### Suggested columns

| Column                   | Purpose                                                                                         |
| ------------------------ | ----------------------------------------------------------------------------------------------- |
| Requirement ID           | Stable ID (`TB-AREA-NNN` or `TB-AREA-NNN-a`)                                                    |
| Statement                | Atomic, testable requirement sentence                                                           |
| Type                     | Rule / Field / Calculation / Transition / Permission / Entitlement / Exception / Non-functional |
| Governing sources        | Document + section anchors                                                                      |
| Status                   | Confirmed / Pending / Conflict / Deferred / Excluded                                            |
| Phase                    | Phases.md number only                                                                           |
| Module / bounded context | Architecture §2 name                                                                            |
| Dependencies             | Other requirement IDs                                                                           |
| Acceptance test IDs      | Pest/Playwright IDs                                                                             |
| Open conflict IDs        | Link to AUD-CON-* if any                                                                        |
| Owner / last reviewed    | Accountability                                                                                  |

### Gaps in current starter

- Only ~19 area-level rows; need hundreds of atomic rows for fields, formulas, and transitions
- Several rows marked Confirmed without section-level anchors
- TB-SUB-002 Phase text vs column inconsistency
- TB-PTL-EXP-001 citation gap
- No Pending rows even though docs call for Pending labels
- No calculation vector IDs (proration, Challan TOTAL, TDS)
- No negative-test IDs for tenant isolation
- No mapping to ADR IDs

### Seed expansions (illustrative — not invented product rules)

- Explode TB-AUD-001 into create/edit/delete/cancel/remark/atomicity/immutability rows
- Explode TB-SUB-001/002 into trial, grace, proration, bundle order, webhook idempotency, invoice-number approval independence
- Add Pending row for QR/Exchange abuse controls
- Add Conflict rows referencing AUD-CON-001…006

---

## Final Assessment

### Already ready

- Draft governing package covers product, architecture, rules, design, phases, memory
- Independent Laravel 13 + PHP 8.5 + React/Inertia codebase exists
- Local PostgreSQL + Redis wiring in `.env` matches agent baseline
- Fortify public registration disabled (aligns with no self-registration)
- Pest suite green (23 pass / 2 skip)
- CI scaffolding (Pint, PHPStan, Pest, frontend typecheck) exists
- Secrets not tracked; `.env*` ignored appropriately
- Agent + TruckBill Cursor instructions present for future execution

### Missing

- Formal approval of governing docs (Phase 0 exit)
- Expanded traceability, ADRs, risk/test strategy artefacts (this audit starts them)
- Tenant/company/branch/audit/outbox/file-scan foundations
- Owner/Branch UI shell and design deliverables
- CI/Postgres alignment and Playwright
- Subscription/billing implementation (correctly Phase 3 per Phases.md)

### Must be clarified (user decisions)

1. Approve or amend governing drafts
2. Resolve Phase numbering (AUD-CON-001)
3. Owner Panel behaviour on company deactivation (AUD-CON-002)
4. Whether any foundation coding may proceed before formal approval (AUD-CON-004)
5. Partload Expense Management exclusion definition (AUD-GAP-001 / AUD-CON-005)
6. Self-service profile/email/delete policy (AUD-GAP-002)
7. Mobile auth approach (AUD-GAP-004 / ADR-006)
8. Product UI font (AUD-GAP-009)
9. How Cursor rules should be shared with the team (AUD-REPO-012)

### Can be implemented without changing product requirements

_Only if the user waives/approves Phase 0 coding gate for these items:_

- CI + `.env.example` alignment to PostgreSQL/Redis (engineering hygiene)
- Removal/disable of starter hard-delete account path (enforces already-stated Rules)
- Cleanup of unused 2FA/verification scaffolding
- Documentation artefacts: ADRs, expanded traceability, implementation log
- Test harness improvements that lock current Fortify posture (registration remains off)

These do **not** authorize Broker/OwnFleet/Partload work.

### Is Phase 1 ready to begin?

**No.** Phase 0 approval is incomplete; Phase 1 technical exit criteria are largely unmet. The repository is a healthy starter + documentation package, not an engineering-foundation complete system.

### Decisions requiring user approval

All Critical/High items marked “Yes” above, especially: AUD-GOV-001, AUD-CON-001, AUD-CON-002, AUD-CON-003, AUD-CON-004, AUD-SEC-001, AUD-REPO-002/003, and the ADR list in Section 8.

---

## Appendix A — Sources reviewed

- `AGENTS.md`
- `.cursor/rules/truckbill-project.mdc`
- `docs/governing/*` (all eight markdown artefacts)
- `docs/agent/*`
- Application: `app/`, `routes/`, `config/`, `database/`, `tests/`, `resources/js/`
- `composer.json` / installed direct packages
- `package.json`
- `.env.example`, `.gitignore`, `.cursorignore` (untracked)
- `.github/workflows/tests.yml`
- `phpunit.xml`
- Git branch/status/remotes

## Appendix B — Out of scope for this audit

- Implementing fixes
- Editing governing documents
- Editing `Requirements-Traceability-Starter.md`
- Dependency changes
- Migrations / DB modifications
- Cloud / production changes
- Git commit or push
