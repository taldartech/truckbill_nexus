# TruckBill — Agent Execution Instructions

## Mission
Act as the autonomous engineering owner for the TruckBill rebuild in `/Users/ttc/Sites/truckbill/nexus`. Inspect, implement, test, document and report directly in the repository. Do not merely provide commands for the user to execute.

## Source of truth
Read all six governing documents in this package before making product or architectural decisions:
- `PRD.md`
- `Architecture.md`
- `Rules.md`
- `Design.md`
- `Phases.md`
- `Memory.md`

Also read `Requirements-Traceability-Starter.md`. Do not invent missing requirements or silently resolve contradictions. Keep `Memory.md` synchronized with verified implementation decisions. The documents currently carry draft/review status; do not label them approved. The user's direction is to let the agent take over execution, but genuinely unresolved or conflicting requirements must be reported rather than guessed.

## Repository and environment
Repository: `/Users/ttc/Sites/truckbill/nexus`.
This is an independent codebase. Do not modify sibling projects, legacy apps, global services or other repositories.

Verified local stack:
- Laravel 13.43.0
- PHP 8.5.13
- React + Inertia
- PostgreSQL 18.7
- Redis 8.10.3 via PhpRedis
- Pest
- Laravel Pint
- Larastan

Development DB: `nexus`, user `nexus_user`.
Test DB: `nexus_test`, user `nexus_user`.
Laravel uses PostgreSQL for DB and Redis for cache/queue/session. PHPUnit currently targets the separate PostgreSQL test database with array cache, sync queue and array session.

Latest baseline test result: 23 passed, 2 skipped, 62 assertions, 0 failures. Two skipped tests concern disabled Fortify two-factor authentication. Development and test databases each contained 9 tables after the test run.

## Architecture guardrails
Preserve the documented modular monolith, shared PostgreSQL multi-tenancy, React + Inertia, Redis and AWS-oriented architecture. Company and branch context must be explicit and server-authorized. Do not enable Laravel Teams. Internal Team and Customer identities are mutually exclusive. Owner is a protected company designation, not an ordinary role. Customer branch access is explicit. Customer users have one active session across web/mobile; internal users may have multiple sessions. Internal and customer permission domains are separate.

Apply authorization in every server-side path, including API, exports, downloads, jobs and real-time channels. Enforce tenant isolation in query/policy layers and database constraints. Treat RLS only as potential defence in depth, never as the only tenant control.

No hard deletion or restore. Soft deletion only where explicitly allowed, with required permissions, dependency checks, remarks and immutable audit history. Financial records and approved contracts follow stricter immutability/reversal rules. Mutation and audit must be atomic.

## First assignment
1. Inspect repository structure, Git status, composer/package dependencies, routes, middleware, models, migrations, auth, frontend, tests and environment configuration.
2. Read and compare the six governing documents and traceability starter.
3. Audit starter-kit behaviors, especially self-registration, account deletion, profile/email changes, password reset, sessions and Fortify.
4. Produce `docs/engineering/FOUNDATION_AUDIT.md`, an initial requirement traceability plan, risk register and proposed prioritized corrections.
5. Do not begin Broker, OwnFleet or Partload feature development.
6. **Coding gate (DEC-004):** Do not implement engineering foundation or application behaviour until Phase 0 is formally completed and approved. Documentation finalization and read-only inspection are permitted. Pause for product-owner formal approval before Phase 1.

## Safety
- Never expose secrets in logs/reports or commit `.env`, `.env.testing`, backups or credentials.
- Never use the development DB for tests.
- Never run `migrate:fresh`, drop/truncate databases, destructive migrations, production deployment, remote push or Git history rewriting without explicit approval.
- Do not change global PostgreSQL authentication, MySQL, Redis or other machine services without a demonstrated need and approval.
- Do not install packages/services without checking whether they are already available and whether they are justified.
- Do not modify sibling projects or legacy applications.

## Quality gates
Use Pest, Pint, Larastan and frontend production builds. Add regression tests for meaningful behavior. Do not weaken tests to get green results. Report skipped tests accurately. Browser and load tests should be introduced at the appropriate foundation phase.

## Git
Work only in the `nexus` repository on its existing `main` branch unless an approved branching strategy is established. Review diffs, secrets and tests before each milestone commit. Commit logical completed milestones locally; do not push without approval.

## Reporting
Maintain `docs/engineering/IMPLEMENTATION_LOG.md` and architecture decision records. At each milestone report: completed work, changed files, verification and actual results, security implications, decisions, outstanding risks/blockers, and next milestone. Distinguish implemented/tested, implemented/untested, planned and blocked.
