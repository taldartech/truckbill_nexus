# OwnFleet (Phase 5) — Honest Checkpoint

**Date:** 23 November 2026  
**PO Phase 5 AUTH:** Approved  
**Implementation status:** **Batches A–G complete (invoices + payments); Batch H remaining**  
**HEAD tip (local):** `71ebdb988828377d0c864560e23910cec6a14ccd` (Larastan level 7 — OwnFleet invoice/payment/trip money helpers)
**Batch F commit:** `a31b073b739e8d34e0b24ab935bfbf312bb2014f`
**Batch G commit:** `9d2e0a8356ac1bbb3c950d5b0e9332f2a2922829`

## Verdict

Do **not** certify Phase 5 complete. Trip lifecycle, invoices, and invoice payments are implemented with sample/non-production workflow; Playwright/k6, dual-DB sweep, and full certification remain (Batch H).

## Completed — Batch A

- Migration `2026_11_23_120233_create_phase5_ownfleet_trip_foundations` (trips, pickups, drops, allocations; vehicle `primary_driver_party_id`; vehicles `company_id+id` unique for composite FKs)
- Domain: `TripService::createFromLorryReceipt` (1 OwnFleet LR = 1 Trip), `TripLrDependencyGuard` bound into `CompositeLrDependencyGuard`
- Permissions `trip.*` registered (no blanket role grants); demo roles grant Trip codes for local fixtures
- Routes/UI: `/trips`, `/trips/{trip}`, `/lorry-receipts/{lr}/create-trip` + Create Trip CTA on OwnFleet LR show
- Pest: `tests/Feature/Phase5/OwnFleetTripFoundationTest.php`
- Demo seeder: OwnFleet pending LR + linked Pending Trip; drivers + primary driver on vehicles

## Completed — Batch B

- `TripMovementService` pickup/drop/allocation with lock-for-update over-allocation protection
- Trip Detail UI: add pickup/drop, allocation editor, reconciliation summary
- Pest: `OwnFleetTripAllocationTest` (split, combine, over-allocation, completion gate)
- Demo seeder: multi-point fixtures with partial allocation example

## Completed — Batch C

- `TripService::dispatch` / `complete` with odometer rules, distance, timing Early/On Time/Delayed/N/A
- Trip Detail dispatch & completion forms
- Pest: `OwnFleetTripLifecycleTest`

## Completed — Batch D

- `trip_expenses` migration + `TripExpenseService` (diesel litres×rate, planned diesel, profit = LR freight + charges − expenses; mileage N/A until attributable)
- Trip Detail expenses/profit UI
- Pest: `OwnFleetTripExpenseTest`

## Completed — Batch E

- Trip cancel (Pending/In Progress + reason) and Pending soft-delete (expenses retained, excluded from search)
- Document parents `lorry_receipt` / `trip` for POD/supporting docs via existing Document Management
- Pest: `OwnFleetTripCancelDeleteTest`

## Completed — Batch F (OwnFleet invoices)

- Migration `2026_11_23_135323_create_phase5_ownfleet_trip_invoices_tables` (`trip_invoices`, lines, charges, payments; tenant FKs; soft-delete invoice number uniqueness)
- `DocumentNumberType::TripInvoice` + `TripInvoiceService` (individual/consolidated, GST snapshot from company `gst_rate_snapshot`, invoice TDS rate from billing customer PAN, cancel, latest-only soft delete)
- **Eligibility decision:** `TripService::complete` does **not** set LR `delivered`; invoices require **completed Trip** + OwnFleet LR + `billing_customer_party_id` (documented here per PO guidance).
- Permissions `invoice.*` registered; demo + Pest helpers grant explicitly (no blanket RBAC matrix grants)
- Inertia: `/trip-invoices` list/create/consolidate/show/print; sidebar **OwnFleet Invoices**
- Pest: `tests/Feature/Phase5/OwnFleetTripInvoiceTest.php` (6 cases)
- Demo seeder: partial invoice fixture (`DEMO-OF-INV-PARTIAL`) via completed LR path

## Completed — Batch G (invoice payments)

- `TripInvoicePaymentService` (settlement = received + TDS + shortage/damage + discount; fill net; overpayment guard; cumulative TDS ≤ base freight; immutable rows; pending/partial/paid)
- Routes/UI: payment form on invoice show, `/payments` history index; sidebar **Payments**
- Permissions `payment.*` registered; demo grants for manager/operator
- Pest: `tests/Feature/Phase5/OwnFleetTripInvoicePaymentTest.php` (3 cases)
- Demo seeder: paid invoice fixture (`DEMO-OF-INV-PAID`) with fill-net settlement

## Remaining (authorized sequence)

- **H** Full UI polish, dual-DB formal certification sweep, Playwright, k6, Phase 5 certification

## Explicit gaps (honest)

- No GL / accounting postings (ADR-010 Pending)
- Invoice additional-charge **edit after create** not exposed in UI (lock after first payment enforced server-side)
- Consolidated invoice **second-latest delete** sequencing not exhaustively covered in Pest (latest-only rule implemented)
- Playwright journeys for invoice/payment not yet added
- MySQL job parity for new tables not re-certified in this batch

## Explicitly out of scope here

Phase 6 Broker / Market-Hired. Live Razorpay. Production tax invoice claims. Homebrew MySQL repair.
