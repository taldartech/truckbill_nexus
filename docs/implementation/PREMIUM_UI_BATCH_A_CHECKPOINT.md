# Premium UI / Owner Shell — Batch A Checkpoint

**Date:** 23 November 2026  
**Baseline:** `4959523228e7bfed055bbffdeb1dfc01ab6be2b2`  
**Scope:** Batch A — Critical Owner shell, branding, dashboard, management tables; Enquiry form layout start; CI #74 runner pin.

## Delivered

| Item                                                          | Status                                                             |
| ------------------------------------------------------------- | ------------------------------------------------------------------ |
| Remove Company Switcher from global Owner management          | **Done** — switcher only in Branch Panel                           |
| Clear workspace company/branch session on global Owner routes | **Done**                                                           |
| Global Owner Dashboard (`GET /owner`)                         | **Done** — real aggregates + attention from subscription lifecycle |
| Global Users (`GET /owner/users`)                             | **Done** — membership aggregation                                  |
| Global Audit (`GET /owner/audit`)                             | **Done** — read-only feed                                          |
| Branding TruckBill + tagline (not Laravel)                    | **Done** — `config/app.php` default + `AppLogo`                    |
| Distinct sidebar icons + Overview/Management groups           | **Done**                                                           |
| Companies table columns (GSTIN, PAN, contact, History)        | **Done**                                                           |
| Enquiry create multi-column layout                            | **Done** (goods multi-row editor still Batch B+)                   |
| CI Playwright OS pin `ubuntu-24.04`                           | **Done** (CI #74)                                                  |

## Not in this checkpoint (authorized, remaining)

| Item                                                         | Batch |
| ------------------------------------------------------------ | ----- |
| Full-app page-by-page UI audit + shared form system          | B     |
| Enquiry goods multi-row / masters form polish                | B     |
| Phase 4 Unified LR / conversion / quotation completion       | C     |
| Phase 5 OwnFleet domain + Operations nav                     | D     |
| Full Playwright visual matrix + authenticated k6 Owner lists | E     |

## Verification (local)

- Pest: OwnerGlobalPanelTest + WorkspacePanelTest + Phase2SecurityCertificationTest — passed
- PHPStan on touched Owner services/controllers — passed
- `npm run types:check` — passed

OwnFleet is **not** certified. Do not treat Batch A as Phase 5 complete.
