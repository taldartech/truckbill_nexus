# GitHub Actions #89 Remediation

**Date:** 24 November 2026  
**Remote run:** https://github.com/taldartech/truckbill_nexus/actions/runs/45307909131  
**Baseline tip at investigation:** `a1f9765ed5bf5eb31d441d959a89e68f00804d45`

## Failures

| Test                                                  | Symptom                     | Root cause                                                                                                 |
| ----------------------------------------------------- | --------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `CompanyCreationAccessibilityTest` (branch.dashboard) | Expected 200, got 403       | AUD-013 requires Owner **or** `platform.operations.view`; fixture only assigned branch membership, no role |
| `WorkspacePanelTest` (member sole assignment)         | Same                        | Same                                                                                                       |
| `TechnicalUiShellTest`                                | Unexpected `broker.` routes | Assertion used `str_contains`; Phase 6 `branch.broker.*` names contain `broker.`                           |

MySQL job had already passed (migrations, focused Pest, seed). Playwright skipped because PostgreSQL Pest failed.

## Fixes (local)

1. **`CompanyAccessService::canViewBranchOperationsPanel()`** — shared AUD-013 gate.
2. **`BranchDashboardController`** — uses the shared gate (security unchanged).
3. **`WorkspaceLandingController`** — does not redirect assignment-only members to `/operations` (sends `workspace.no-access` instead).
4. **Fixtures** — grant `customer.viewer` via `RoleAssignmentService` for member dashboard happy paths.
5. **Negative Pest** — assignment without role → landing no-access + dashboard 403.
6. **`TechnicalUiShellTest`** — forbid **top-level** `broker.` / `ownfleet.` / `partload.` / `accounting.` prefixes; allow `branch.broker.*` and OwnFleet trip routes.

## Verification

| Check                                | Result                                                                    |
| ------------------------------------ | ------------------------------------------------------------------------- |
| Focused three files (+ new negative) | Pass (40 tests)                                                           |
| Full suite / remote Actions          | Pending after local full Pest; remote green **not claimed** until PO push |

## Security note

Do **not** weaken AUD-013. Membership alone must not open Branch operations.
