# Session Persistence E2E Certification

**Date:** 24 November 2026  
**Spec:** `tests/e2e/workspace/branch-session-persistence.spec.ts`  
**Local demo:** `LocalDemoWorkspaceSeeder` (profile=standard)  
**Assets:** production Vite build (`public/build`; `public/hot` absent so Playwright does not depend on a live Vite server)

## Results (verified)

| Suite                                                                                                                                                                                  | Result                                      |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| Playwright `branch-session-persistence.spec.ts`                                                                                                                                        | **2 passed** / 0 failed / 0 skipped (~9.9s) |
| Pest PostgreSQL full suite                                                                                                                                                             | **743 passed** / 2 skipped / 0 failed       |
| Pest MySQL focused session suite (`BranchWorkspaceSessionPersistenceTest`, `SessionPolicyTest`, `SessionWorkspaceRoutingTest`, `WorkspacePanelTest`, `WorkspaceNavigationServiceTest`) | **62 passed** / 0 failed                    |

Screenshot evidence: `storage/visual-certification/session/branch-persist-after-refresh.png`

## Scenarios covered in automation

| Scenario                        | Coverage                                                 |
| ------------------------------- | -------------------------------------------------------- |
| Owner login → enter branch      | Demo fixture + `enterDemoAlphaBranch`                    |
| Navigation within branch        | Bilty Register / operations                              |
| Hover Owner-adjacent chrome     | Return button hover (former prefetch trigger)            |
| Browser refresh                 | `page.reload()` retains Branch Panel                     |
| Explicit Return                 | POST leave → `/management`                               |
| Multiple tabs                   | Second tab `/operations` + first tab `/parties`          |
| Owner GET / prefetch regression | Pest: management GETs do not clear `workspace.branch_id` |
| Permission cache flush          | Pest: `Cache::flush()` then Branch dashboard still 200   |
| Explicit leave-branch           | Pest: POST clears branch, lands Owner home               |

## Idle / 30-minute wall-clock

Automated Pest covers cache flush without destroying context. The primary defect was **immediate** workspace wipe via Owner GET/Inertia prefetch of `/management`, not idle session TTL. Full 15–30 minute wall-clock idle remains an environment observation (session lifetime still governs auth expiry).

## Command

```bash
php artisan db:seed --class=LocalDemoWorkspaceSeeder --no-interaction
# Prefer built assets when Vite is not running (remove/ignore public/hot):
E2E_REQUIRE_DEMO=1 PLAYWRIGHT_USE_SYSTEM_CHROME=1 PLAYWRIGHT_SKIP_WEBSERVER=1 \
  E2E_USER_PASSWORD="$LOCAL_DEMO_PASSWORD" \
  npx playwright test tests/e2e/workspace/branch-session-persistence.spec.ts
```

Ensure `php artisan serve --host=127.0.0.1 --port=8000` is running when `PLAYWRIGHT_SKIP_WEBSERVER=1`.
