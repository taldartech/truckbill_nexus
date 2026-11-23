# Branch Navigation, Visibility & OwnFleet Checkpoint

**Date:** 23 November 2026  
**Baseline remote:** `18d083430f4df64ebcf9e68648841b80b2d88d58`  
**Local tip before this batch:** `a405bdeaad7c596f4537f396174551b6f85f3c0a`
**Feature commit:** `6b19c46ddc648717bb5c97b56907b6209141fa22`

## Batch A — Navigation (implemented)

| Requirement                              | Status                                                           |
| ---------------------------------------- | ---------------------------------------------------------------- |
| Direct Masters sidebar links             | Done — Party, Vehicle, Bank/Cash, Station, Supporting Catalogues |
| Remove Masters hub from nav              | Done — `/branch/masters` redirects to Party Master               |
| Remove CompanySwitcher from Branch Panel | Done — sidebar, MasterPageHeader, settings, dashboard            |
| Read-only company/branch context         | Done — `branch-context-readonly` + shared Inertia names          |
| Single **Return to Owner Portal**        | Done — sidebar footer only                                       |

## Batch B — Master visibility UI (implemented)

| Requirement                                     | Status                                                              |
| ----------------------------------------------- | ------------------------------------------------------------------- |
| Remove Scope columns (Party/Vehicle/Bank-Cash)  | Done                                                                |
| Company Data Visibility banner on lists         | Done via `MasterPageHeader`                                         |
| Server `MasterDataVisibilityResolver` preserved | Unchanged (already authoritative on create)                         |
| Historical mixed-scope remediation              | Deferred — no silent rewrite; existing Pest visibility tests remain |

## Batch C — Unified LR (not completed this run)

Foundations exist (`LorryReceipt` model, goods rows, numbering enum, enquiry conversion tables).  
**No operational Unified LR Branch UI / controllers** for create–dispatch workflow are present.

**Next executable task:** Phase 4 Batch C–F LR operational vertical slice (list/create/detail, goods, numbering, session routes under `/branch/lorry-receipts`) before OwnFleet Trip.

## Batch D — OwnFleet Phase 5 (not completed this run)

`PO-P5-AUTH` is approved. Repository has **no OwnFleet Trip domain services, routes, or UI** — only subscription module enum / CRM interest / compliance matrix docs.

**Do not expose fake OwnFleet sidebar entries.**

**Next executable task:** After Unified LR create/complete path works with 1 LR = 1 Trip invariant, implement Trip Management + Branch sidebar Operations links with real entitlement checks.

## Demo seeder

No OwnFleet/LR operational fixtures added this batch (modules not implemented). `LocalDemoWorkspaceSeeder` remains the sole demo seeder.
