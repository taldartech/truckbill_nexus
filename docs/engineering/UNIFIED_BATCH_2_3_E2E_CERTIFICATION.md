# Unified Batch 2 & 3 — E2E Certification

**Date:** 24 November 2026  
**Status:** Incomplete

## Executed this session

| Suite                                                           | Result                             | Notes                                    |
| --------------------------------------------------------------- | ---------------------------------- | ---------------------------------------- |
| Pest focused (`redirect_to_trip` / LorryReceipt / vehicle JSON) | 14 passed                          | OwnFleetTripFoundation + LR filters      |
| Playwright `bilty-lorry-hire-ui.spec.ts`                        | Failed (login) then re-run pending | Demo reseeded; `composer run dev` active |
| Full `php artisan test`                                         | Not run this pass                  | Required before claiming Complete        |
| MySQL disposable suite                                          | Not run this pass                  | Required before claiming Complete        |

## Journeys A–H

| Journey                                           | Status                                                   |
| ------------------------------------------------- | -------------------------------------------------------- |
| A Market Vehicle Bilty → Hire → Voucher → Invoice | Partial automated; not fully browser-certified this pass |
| B Own Vehicle Bilty → Trip → Expenses             | Domain + UI CTA aligned; full browser open               |
| C Vehicle type switching                          | Open                                                     |
| D Multi-point OwnFleet                            | Open                                                     |
| E Quick master creation                           | Partial (Market+Own vehicle sheets exist)                |
| F Common customer billing                         | Domain exists; visual polish open                        |
| G Financial restrictions                          | Prior Pest coverage; not re-run full matrix              |
| H Role/entitlement matrix                         | Not eleven-persona certified this pass                   |

Do not claim journeys Complete without attached Playwright or manual browser evidence.
