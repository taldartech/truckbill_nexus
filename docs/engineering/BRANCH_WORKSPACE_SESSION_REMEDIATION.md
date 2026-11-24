# Branch Workspace Session — Remediation

**Date:** 24 November 2026

## Correction

1. **Stop clearing workspace on Owner list/home GETs** — remove `WorkspaceSession::clear()` from global Owner controllers.
2. **HandleInertiaRequests** — Owner routes hide branch in shared props only; do not mutate session.
3. **ResolveManagementCompany** — do not clear branch on every request.
4. **AuthorizesOwnerPanel** — use `WorkspaceSession::ensureCompany()` so same-company pages preserve branch.
5. **Explicit leave** — `POST /workspace/context/leave-branch` clears branch (keeps company) and redirects to Owner home.
6. **Sidebar** — logo stays on `/operations` in Branch Panel; Return uses POST leave (no Owner prefetch).
7. **Diagnostics** — `ResolveBranchWorkspace::missingContext` logs `branch_workspace_context_missing` with correlation id / route / presence flags (no secrets).

## Security preserved

- Single-active Customer session unchanged.
- Explicit company/branch context POSTs still authorize via `CompanyAccessService`.
- Tenant isolation and AUD-013 unchanged.
- Customer members still cannot open Owner Panel.

## Tests

- `tests/Feature/BranchWorkspaceSessionPersistenceTest.php`
- Updated `SessionWorkspaceRoutingTest`, `WorkspacePanelTest`
- Playwright: `tests/e2e/workspace/branch-session-persistence.spec.ts`

## Additional hardening in this batch

- `phpunit.xml`: `force="true"` on `APP_ENV`, `SESSION_DRIVER`, `SESSION_DOMAIN`, `CACHE_STORE`, `QUEUE_CONNECTION` so a shell that sourced `.env` (Redis session) cannot break CSRF/login Pest with 419s.
- `BrokerInvoiceController`: catch `BrokerAuthorizationException` before `BrokerDomainException` (authorization extends domain; prior order made the auth rethrow dead and incorrectly mapped auth failures to validation messages) — PHPStan `catch.alreadyCaught` + correct auth semantics.
- CI formatting gate: `resources/js/pages/broker/invoices/show.tsx` formatted (`vp check --fix`) for Actions run `45356747338`.

## Verification (24 Nov 2026, local)

| Check                                    | Result                                                    |
| ---------------------------------------- | --------------------------------------------------------- |
| PostgreSQL full Pest                     | 743 passed / 2 skipped                                    |
| MySQL 8.4 focused session Pest (`:3307`) | 62 passed                                                 |
| Playwright branch session persistence    | 2 passed                                                  |
| PHPStan                                  | 0 errors                                                  |
| `npm run check`                          | pass                                                      |
| Security                                 | Single-session, AUD-013, Owner-only Owner Panel preserved |

## Remaining risks

- Wall-clock 15–30 minute idle was not re-observed in browser for this batch (defect was immediate prefetch wipe).
- Deployed environments must share session storage and consistent `APP_KEY`; this fix does not change session lifetime.
- Remote GitHub Actions success is only claimable after PO push of this commit.
