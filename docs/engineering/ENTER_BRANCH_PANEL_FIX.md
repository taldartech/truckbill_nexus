# Enter Branch Panel Redirect Fix

**Date:** 23 November 2026  
**Baseline:** `18d083430f4df64ebcf9e68648841b80b2d88d58`
**Feature commit:** `00d6ed6c6729729e895727a359bc933ec0804e25` (Actions #78 SUCCESS)

## Root cause

Global Owner Branches used a GET `Link` to `/branch` (`urls.enter_branch`) while Owner routes clear branch session chrome. `ResolveBranchWorkspace` found no context, redirected to Workspace Landing, which sent Owners to `/management`.

## Correction

- Use `WorkspaceContextAction` with `urls.enter_branch_context` (POST) and redirect `/branch`.
- Icon: Lucide `LogIn`, accessible label/tooltip, loading/disabled while processing.
- `WorkspaceSession::sync()` avoids bumping `context_version` on routine Branch GET navigation.
- Branch Dashboard links use canonical `/branch` and `/branch/settings` (no UUID paths).

## Verification

- Pest: enter-panel context POST, branch switch, version stability, deactivated denial, foreign denial.
- Playwright: `tests/e2e/owner/enter-branch.spec.ts` (requires e2e seed).
