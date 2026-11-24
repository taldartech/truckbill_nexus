# Bilty UI & Operational Workflow Remediation

**Date:** 24 November 2026  
**Authority:** Product Owner Bilty UI redesign directive  
**Tip at docs commit:** see git log for `feat(bilty)` / related SHAs

## Summary

Transport-office usability remediation around the Bilty as the primary operational record.

| Area       | Change                                                            |
| ---------- | ----------------------------------------------------------------- |
| Numbering  | Optional Bilty No.; server infers auto vs manual                  |
| Stations   | Loading / Unloading Station labels                                |
| Goods      | Full-width table; Per MT default; Wt./Unit assist                 |
| Register   | Ownership actions: Manage Expenses / Manage Lorry Hire            |
| Navigation | OwnFleet Trips menu removed; `/trips` redirects to Bilty Register |
| Domain     | TripService / LorryHireService unchanged                          |

## Backend

- `LorryReceiptService::normalizePayload` — ignore client `number_mode`; infer from `lr_number` / `manual_number`
- `LorryReceiptController::validatedPayload` — `number_mode` optional
- `listPayload` + `primaryOperationAction` — eager-loaded trip/hire/vehicle ops without N+1
- `LorryReceiptService::search` — CaseInsensitiveLike for MySQL/PG; eager load trip/hire/stations/vehicle
- `TripController::index` — redirect to Bilty Register

## Frontend

- `lorry-receipts/lr-form.tsx` redesign
- `lorry-receipts/index.tsx` Bilty Register columns + actions
- `app-sidebar.tsx` — Bilty Register; Trips nav removed
- `form-section.tsx` denser padding

## Tests

- Phase4 LorryReceiptServiceTest numbering inference + HTTP create without `number_mode`
- `BiltyRegisterOperationsTest` register actions + trips redirect
- OwnFleetTripFoundationTest trips index redirect assertion

## Residuals

- Full Playwright journeys A–D and multi-viewport screenshots — execute in local browser certification pass
- Trip detail remains the OwnFleet operational workspace (linked from Bilty); deeper in-place embedding deferred
