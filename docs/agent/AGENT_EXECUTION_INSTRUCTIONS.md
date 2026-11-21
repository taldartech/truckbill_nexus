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

Also read `Requirements-Traceability-Starter.md`. Do not invent missing requirements or silently resolve contradictions. Keep `Memory.md` synchronized with verified implementation decisions. The six governing documents are Approved as the Phase 0 baseline (21 November 2026), subject to PO-EXCEPT-001. Pending, Deferred, Proposed, and Excluded items retain their recorded statuses. Do not independently resolve or reinterpret them. The user's direction is to let the agent take over execution, but genuinely unresolved or conflicting requirements must be reported rather than guessed.

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
6. **Coding gate (DEC-004 / PO-P1-AUTH):** Phase 0 is formally approved (PO-APPROVE-001). **PO-P1-AUTH APPROVED** (21 November 2026) authorizes Phase 1 Engineering Foundation implementation under `docs/implementation/PHASE_1_EXECUTION_PLAN.md` and `docs/engineering/PHASE_1_AUTHORIZATION_RECORD.md`. Stay within that plan’s scope, sequencing and exclusions. Do **not** implement Phase 2+ identity/RBAC/product panels, Sanctum (ADR-006 Pending), Razorpay, business modules, or accounting posting architecture (ADR-010 Pending). Preserve Pending/Deferred/Proposed/Excluded exceptions (PO-EXCEPT-001). **PO-P1-DEPS** is case-by-case; **PO-P1-DEPLOY** is not decided.

## Safety
- Never expose secrets in logs/reports or commit `.env`, `.env.testing`, backups or credentials.
- Never use the development DB for tests.
- Never run `migrate:fresh`, drop/truncate databases, destructive migrations, production deployment, or Git history rewriting without explicit Product Owner approval.
- Never run `git push`, `git push --force`, or any equivalent remote-publishing command. Never publish branches, tags, releases, or other repository changes remotely. This remote-publishing prohibition is absolute and cannot be overridden by a future approval request.
- Do not change global PostgreSQL authentication, MySQL, Redis or other machine services without a demonstrated need and approval.
- Do not install packages/services without checking whether they are already available and whether they are justified.
- Do not modify sibling projects or legacy applications.

## Quality gates
Use Pest, Pint, Larastan and frontend production builds. Add regression tests for meaningful behavior. Do not weaken tests to get green results. Report skipped tests accurately. Browser and load tests should be introduced at the appropriate foundation phase.

## Git
Work only in the `nexus` repository on its existing `main` branch unless an approved branching strategy is established.

### Local commits — expected by default
- When the Product Owner asks Cursor to implement, modify, fix, refine or complete a task, Cursor is authorized and expected to create a local Git commit for the completed changes by default.
- This applies to application code, tests, configuration and documentation.
- Before committing, inspect the complete diff, run relevant tests and quality checks, and verify that no secrets, environment files, credentials or unrelated user changes are included.
- Use a clear, descriptive conventional commit message.
- Keep commits focused on the task. Do not include unrelated changes.
- Do not commit incomplete or unverified work. If a blocker prevents safe completion, report it and leave the work uncommitted.
- If the Product Owner explicitly says "do not commit", "no commit", or requests a review-only/uncommitted change, respect that instruction.
- If the task is only an analysis or review and no files are changed, no commit is necessary.
- After committing, report the full commit SHA, commit message, changed files, verification results and remaining working-tree status.

### Absolute no-push / no-remote-publishing policy
This restriction remains unchanged and absolute:
- Never execute `git push`, `git push --force`, or equivalent commands.
- Never publish branches, tags, releases or repository changes remotely.
- Never use GitHub APIs or other tools to create commits or update remote references on behalf of the user.
- The Product Owner will handle all remote pushes and publishing personally.
- No future instruction or approval can override this prohibition.

### Safe Git workflow
For each task:
1. Inspect current branch, HEAD and working-tree status.
2. Preserve all pre-existing user changes.
3. Complete only the requested task.
4. Run relevant tests, linting, builds and checks.
5. Review git diff and staged changes.
6. Stage only files belonging to the task.
7. Create the local commit.
8. Verify the resulting commit and working-tree status.
9. Report the commit SHA and explicitly confirm that no push or remote publication occurred.

If the local branch is behind or diverged from its upstream, do not reset, rebase, overwrite or discard user work. Report the discrepancy and request direction before proceeding with any operation that could affect existing history.

This policy is consistent with `.cursor/rules/truckbill-project.mdc`.

## Reporting
Maintain `docs/engineering/IMPLEMENTATION_LOG.md` and architecture decision records. At each milestone report: completed work, changed files, verification and actual results, security implications, decisions, outstanding risks/blockers, and next milestone. Distinguish implemented/tested, implemented/untested, planned and blocked.
