# Owner Panel Reconstruction, Location Masters & Operational Integration

**Date:** 25 November 2026  
**Starting HEAD:** `9fac0c3e7ef0bbee55ddea5d2cab0a4a7923e976`  
**Status:** Implementation in progress (local) — no push / no deploy

## Delivered in this batch

| Area                            | Result                                                                                                   |
| ------------------------------- | -------------------------------------------------------------------------------------------------------- |
| State / City Master             | `states` / `cities` tables, India seeder, unmatched report `storage/app/geography-migration-report.json` |
| Platform Locations admin        | `GET /admin/locations` (Internal only)                                                                   |
| Dependent selectors             | `StateCityFields` + `/api/geography/states                                                               | cities` |
| Branch Register / Detail / Edit | Full profile + GST inherit/independent + geography                                                       |
| Effective Branch GST            | Wired into Broker + OwnFleet invoice tax computation; print uses snapshot GSTIN                          |
| Owner LR delete                 | Company Owner bypasses role matrix for LR operational permissions (incl. `LR_DELETE`)                    |
| Hire duplicate recovery         | Preserved GET redirect + POST idempotent recovery + form submission lock                                 |
| Multi-point freight             | Allocation `rate` remains `min:0` (ordinary Bilty `gt:0` unchanged)                                      |

## Focused Pest (disposable PG `:5433`)

- `GeographyMasterTest`
- `OwnerBranchProfileUpdateTest`
- `OwnerBranchAdministrationTest`
- `OwnerPanelSessionIndependenceTest`
- `LorryReceiptOwnerDeletePermissionTest`
- `EffectiveBranchGstInvoiceTest`

## Remaining / follow-on

- Broader visual parity polish vs every legacy Broker admin Blade screen
- Full dual-DB Pest + Playwright A–F certification pass
- Party/Station/inline address forms beyond Company/Branch create
- Historical free-text geography continues unmatched where aliases are ambiguous

## Safety

- Additive migrations only; live `nexus` not wiped
- Legacy apps untouched
- No push / no deploy
