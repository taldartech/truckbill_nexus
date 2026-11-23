# Simplified UI & Clean Operational URLs — Checkpoint

**Date:** 23 November 2026  
**Scope:** Batches A–B (UI simplification + session URLs without `/branch`)  
**Status:** Implemented locally

## A — UI simplification

| Requirement            | Result                                                                        |
| ---------------------- | ----------------------------------------------------------------------------- |
| Functional top bar     | `AppTopbar` — sidebar trigger, portal/branch context, user menu               |
| Company · Branch once  | `data-testid="app-topbar-context"`; sidebar `branch-context-readonly` removed |
| No Shared Data banners | Removed from Master list/create headers and forms                             |
| Compact page titles    | `MasterPageHeader` / `PageHeader` title-only                                  |
| Dashboard chrome       | `/operations` shows “Dashboard” without duplicate company/branch badges       |

## B — Clean URLs

| Legacy                    | Canonical            | Route name preserved      |
| ------------------------- | -------------------- | ------------------------- |
| `/branch`                 | `/operations`        | `branch.dashboard`        |
| `/branch/settings`        | `/settings/branch`   | `branch.settings`         |
| `/branch/enquiries…`      | `/enquiries…`        | `branch.enquiries.*`      |
| `/branch/parties…`        | `/parties…`          | `branch.parties.*`        |
| `/branch/vehicles…`       | `/vehicles…`         | `branch.vehicles.*`       |
| `/branch/bank-cash…`      | `/bank-cash…`        | `branch.bank-cash.*`      |
| `/branch/stations…`       | `/stations…`         | `branch.stations.*`       |
| `/branch/catalogues…`     | `/catalogues…`       | `branch.catalogues.*`     |
| `/branch/documents…`      | `/documents…`        | `branch.documents.*`      |
| `/branch/masters`         | `/masters` → parties | `branch.masters.index`    |
| `/branch/lorry-receipts…` | `/lorry-receipts…`   | `branch.lorry-receipts.*` |

Legacy `/branch/*` GET redirects to canonical paths **without** changing session tenant context.  
UUID legacy `/branch/companies/{company}/branches/{branch}/…` still refuses silent switch.

## Related batches (this wave)

| Batch                                | Status                                                                           |
| ------------------------------------ | -------------------------------------------------------------------------------- |
| C Unified LR product UI/services     | **Implemented** — `/lorry-receipts` + Pest; gaps: number-adjust UI, LR documents |
| D OwnFleet Trip→Invoice→Payment      | **Not implemented** — see `OWNFLEET_IMPLEMENTATION_CHECKPOINT.md`                |
| E Full Playwright/k6 for LR/OwnFleet | Partial; OwnFleet blocked on D                                                   |

## Security preserved

- WorkspaceSession + `branch.workspace` middleware
- Context version on unsafe mutations
- Named routes / Wayfinder for link generation
- Server-derived company/branch; no client tenant IDs
