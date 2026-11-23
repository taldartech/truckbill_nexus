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

| Item                                                         | Batch                            |
| ------------------------------------------------------------ | -------------------------------- |
| Full-app page-by-page UI audit + shared form system          | Premium UI B                     |
| Enquiry goods multi-row / masters form polish                | Premium UI B                     |
| Phase 4 Batch C — assignment + follow-ups                    | Phase 4 C (`PO-P4-BATCH-C-AUTH`) |
| Phase 4 Batches D–F — quotation, Unified LR ops, conversion  | Phase 4 D–F                      |
| Phase 5 OwnFleet domain + Operations nav                     | Phase 5 (after D–F)              |
| Full Playwright visual matrix + authenticated k6 Owner lists | Premium UI E                     |

## Phase 4 / 5 codebase fact

Verified by [Phase 4 / OwnFleet audit](432d0e12-3046-4aeb-bf65-0e8ac14bf4e1):

- Phase 4 in code = **Batch A (schema) + Batch B (enquiry intake only)**.
- Quotation, Unified LR HTTP/UI, and conversion are **schema/models only**.
- OwnFleet ops are **absent** (enum/hook stubs + subscription SKU only).
- **Do not start OwnFleet** until Phase 4 **D → E → F** deliver real `LorryReceipt` workflows.
- Next operational slice: **Phase 4 Batch C** (assignment + follow-ups), then D/E/F.

## Verification (local)

- Pest: OwnerGlobalPanelTest + WorkspacePanelTest + Phase2SecurityCertificationTest — passed
- PHPStan on touched Owner services/controllers — passed
- `npm run types:check` — passed

OwnFleet is **not** certified. Do not treat Batch A as Phase 5 complete.
