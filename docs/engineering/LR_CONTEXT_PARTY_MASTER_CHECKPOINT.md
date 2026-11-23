# LR Context Resolution & Party Master — Checkpoint

**Date:** 23 November 2026  
**Baseline:** `463e9502e4959bd07c8ddca1ddce5f1dbfca0670`  
**GitHub Actions #79:** Frontend check failed on 21 unused-param warnings — **fixed** (`npm run check` clean).

## Batch A — LR context

| Item                            | Result                                                            |
| ------------------------------- | ----------------------------------------------------------------- |
| `CoreModuleEntitlementResolver` | Active subscription items (+ trial → all core modules)            |
| `LrWorkflowContextResolver`     | ready / choose / blocked from entitlements + origin               |
| Form dropdown removed           | `lr-form.tsx` uses fixed server context                           |
| Field matrix                    | Broker text+parties; OwnFleet stations; Partload stations+parties |
| Forged context                  | Rejected on POST                                                  |
| Edit immutability               | Stored context locked                                             |
| Pest                            | `LrWorkflowContextResolutionTest` + updated LR service tests      |

## Batch B — Party Master

| Item                          | Result                                                          |
| ----------------------------- | --------------------------------------------------------------- |
| `PartyRoleAttributeValidator` | Allowlists + required sets per role                             |
| `PartyService::syncRoles`     | Edit/add roles without wiping history                           |
| Form UI                       | Common + roles + transporter/driver/vendor panels; edit prefill |
| Pest                          | `PartyRoleAttributesTest` (8 cases)                             |

## Gaps (honest)

- TDS engine not auto-wired into Lorry Hire from transporter attributes
- April 1 declaration reset not scheduled
- Bank account on transporter is free-text, not Bank/Cash FK
- Playwright full save journeys need entitled e2e companies with activated modules
- k6 not re-run in this wave

## Demo seeder

Alpha: active multi-module subscription; permanent/market drivers; pump/general vendors; multi-role party.  
Beta: active broker+ownfleet subscription.
