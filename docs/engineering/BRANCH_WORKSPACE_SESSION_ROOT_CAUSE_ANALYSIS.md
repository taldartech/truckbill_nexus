# Branch Workspace Session — Root Cause Analysis

**Date:** 24 November 2026  
**Baseline HEAD:** `eb72a17c332ef5b842e6ac27573bf3a52dc4d899`  
**Status:** Root cause identified and corrected

## Reported symptom

Owner logs in → enters Branch Panel → works for several minutes → **unexpected redirect to Owner Panel** without clicking Return. Authentication remains valid; form work can be lost.

## Verified root cause

### Primary defect

Several **Owner Panel controllers cleared the entire workspace session on every GET**, including:

- `OwnerHomeController` (`GET /management`)
- `OwnerCompaniesController::index`
- `OwnerGlobalBranchesController`
- `OwnerGlobalUsersController`
- `OwnerGlobalAuditController`
- `OwnerSubscriptionsController`

Additionally:

- `HandleInertiaRequests` previously `forget` `workspace.branch_id` on any `owner.*` route.
- `AuthorizesOwnerPanel` called `WorkspaceSession::put($companyId)` which **defaults branch to null**, clearing branch on company-scoped Owner pages.
- Branch sidebar exposed `Link href="/management" prefetch` on the logo and “Return to Owner Portal”.

### Trigger chain (reproduced)

1. User enters Branch Panel → session stores `company_id` + `branch_id`.
2. Inertia **prefetch** (or any GET) of `/management` (logo / Return link) runs while still on Branch Panel.
3. `OwnerHomeController` executed `WorkspaceSession::clear()`.
4. Next Branch Panel navigation hits `ResolveBranchWorkspace` with missing branch → redirect `workspace.landing` → for owners → `owner.home`.

Authentication stayed valid throughout — only workspace context was destroyed.

## Non-causes (verified)

- Not ordinary session lifetime timeout.
- Not Redis permission-cache TTL alone.
- Not single-active-session false positive from multiple tabs (separate enforcement path).
- Not CSRF failure (would 419 / login).

## CI failure on same HEAD

GitHub Actions run `45356747338`: Frontend check failed on unformatted `resources/js/pages/broker/invoices/show.tsx` (unrelated invoice commit). Corrected with `vp check --fix`.

## Reproduction evidence

1. Enter Branch Panel → session holds company + branch.
2. `GET /management` (or Inertia prefetch) while still “in branch” → previously cleared session.
3. Next Branch route → `ResolveBranchWorkspace::missingContext` → `workspace.landing` → Owner home for designated Owners.
4. Auth cookie / guard remained valid (`auth()->id()` unchanged in Pest regression).

After remediation, the same Owner GETs leave `workspace.branch_id` intact; Branch dashboard returns 200 with matching Inertia workspace props.
