# Legacy OwnFleet → Nexus Functional Parity Audit

**Audit date:** 24 November 2026  
**Auditor mode:** Read-only legacy inspection; Nexus workspace analysis only  
**Legacy repository:** `taldartech/truckbill_ownfleet` @ branch `development`  
**Legacy verified SHA:** `9423293edd8b4e313198728f687f657d4a64c27f` (`9423293e…`) — _Merge branch `responsive-ui` into `development`_  
**Legacy local path (read-only):** `/Users/ttc/Sites/truckbill/_legacy_refs/truckbill_ownfleet`  
**Nexus repository:** `/Users/ttc/Sites/truckbill/nexus`  
**Nexus HEAD at audit:** `efe341d39ea47499cdb16506c0cfaf3a2848d4ab` (uncommitted audit doc only)  
**Governing phase authority:** `docs/governing/Phases.md` (DEC-001)

---

## Methodology

1. Enumerated legacy HTTP surface from `routes/web.php`, `routes/api-routes.php` at the verified SHA (controllers under `app/Http/Controllers/Api/`).
2. Enumerated Nexus Branch operational surface from `routes/web.php` and `app/Domains/OwnFleet/**`, `app/Http/Controllers/Branch/{Trip,TripInvoice,Vehicle,Party,LorryReceipt}*`.
3. Mapped each legacy capability to **approved Nexus phase** (Phase 5 OwnFleet operations vs Phase 10 assets vs Phase 11 reports vs cross-cutting masters in Phase 3/4).
4. Classified parity using the legend below. Nexus PRD/Rules/Architecture **override** legacy when they conflict.
5. **No legacy files were modified.** **No git push.** This document is **uncommitted** unless the Product Owner stages it separately.

### Classification legend

| Classification             | Meaning                                                                       |
| -------------------------- | ----------------------------------------------------------------------------- |
| **Equivalent**             | Same business outcome via Nexus-approved design                               |
| **Improved**               | Nexus meets intent with clearer architecture, safety, or unified model        |
| **Partial**                | Core slice exists; material legacy sub-features missing in current Nexus      |
| **Missing Current Scope**  | In approved Phase 5 (or prerequisite Phase 4 LR) scope but not implemented    |
| **Deferred Future Phase**  | Explicitly later phase per `Phases.md` / Memory / entitlement add-ons         |
| **Intentional Difference** | Approved Nexus product rule; not a parity defect                              |
| **Deprecated**             | Legacy pattern rejected for Nexus (multi-app, float math, separate CRM, etc.) |
| **PO Decision**            | Requires explicit Product Owner call beyond current authorization             |
| **Not Verified**           | Insufficient end-to-end evidence in this audit pass (no runtime/UI proof)     |

### Severity

| Level    | Meaning                                                                 |
| -------- | ----------------------------------------------------------------------- |
| **P0**   | Blocks Phase 5 operational workflow or financial integrity for OwnFleet |
| **P1**   | High operator pain or regression vs legacy for in-scope Phase 5 work    |
| **P2**   | Medium gap; workaround exists or scope ambiguous                        |
| **Info** | Low / deferred / intentional                                            |

---

## Approved phase boundaries (OwnFleet-relevant)

| Phase  | Approved OwnFleet scope (summary)                                                                               | Legacy overlap in this audit                                                          |
| ------ | --------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| **3**  | Party/Vehicle/Bank/Station masters; catalogue seeds; document service foundations                               | Vehicle/Driver masters, stations, goods/truck/tyre-layout/document/expense catalogues |
| **4**  | Unified LR (OwnFleet context); EWB on LR; enquiry conversion (where authorized)                                 | Legacy trips were **not** LR-linked — Nexus redesign                                  |
| **5**  | Trip from LR; multi-point allocations; dispatch/complete; odometer; expenses/diesel; POD/docs; invoice/payments | Trips, expenses, invoicing, POD (subset of legacy trip module)                        |
| **8**  | Accounting completion / GL (ADR-010 Pending)                                                                    | Legacy accounting bridges — not compared as Phase 5                                   |
| **9**  | Notifications / comms                                                                                           | Legacy notification centre — separate product shell                                   |
| **10** | Vehicle documents, tyres, batteries, maintenance, store/workshop                                                | Full legacy asset & compliance ops module                                             |
| **11** | Operational/financial reports; legacy migration                                                                 | Legacy `reports/*`, exports, fleet dashboard analytics                                |

**Phase 5 authorization:** `PO-P5-AUTH` (`docs/implementation/PHASE_5_AUTHORIZATION_RECORD.md`).  
**Engineering status (24 Nov):** Phase 5 OwnFleet slice implemented locally with certification gaps noted in `docs/engineering/PHASE_5_6_FINAL_GAP_REGISTER.md` — this audit is **functional parity**, not certification sign-off.

---

## Classification summary (countable)

| Classification         |   Count |
| ---------------------- | ------: |
| Equivalent             |      33 |
| Improved               |      10 |
| Partial                |      15 |
| Missing Current Scope  |       0 |
| Deferred Future Phase  |      68 |
| Intentional Difference |       6 |
| Deprecated             |       2 |
| PO Decision            |       2 |
| Not Verified           |       2 |
| **Total capabilities** | **138** |

_Post-`3aa5bfe` (24 Nov): B06 → Equivalent; B15 → Improved; C06 → Deferred Future Phase (not Phase 5 exit)._

---

## Capability register

Columns: **ID** | **Capability** | **Legacy evidence** | **Nexus evidence** | **Classification** | **Severity** | **Phase** | **Business impact**

### A. Vehicle & Driver (14)

| ID  | Capability                       | Legacy evidence                               | Nexus evidence                                            | Classification         | Severity | Phase  | Business impact                       |
| --- | -------------------------------- | --------------------------------------------- | --------------------------------------------------------- | ---------------------- | -------- | ------ | ------------------------------------- |
| A01 | Vehicle list/create/edit/delete  | `VehicleController` apiResource + bulk routes | `branch.vehicles.*`, `VehicleService`                     | Equivalent             | Info     | 3      | Core master data                      |
| A02 | Vehicle bulk Excel import        | `vehicles/bulk-upload`                        | No bulk route on `VehicleController`                      | Partial                | P2       | 3      | Faster onboarding                     |
| A03 | Vehicle active/inactive          | `PATCH vehicles/{id}/status`                  | `is_active` on create/edit in `VehicleService`            | Partial                | P2       | 3      | Fleet availability filtering          |
| A04 | Primary driver on vehicle        | `primary-assignment`, `remove-primary-driver` | `primary_driver_party_id`, `VehicleService` validation    | Equivalent             | Info     | 3/5    | Trip driver auto-select               |
| A05 | Fuel history per vehicle         | `vehicles/{id}/fuel-history`                  | No dedicated fuel history API                             | Deferred Future Phase  | Info     | 10/P11 | Analytics, not Phase 5 exit           |
| A06 | Trip vehicle selection list      | `vehicles/for-trip-selection`                 | Vehicle locked from OwnFleet LR (`TripController` create) | Intentional Difference | Info     | 5      | PRD §11 one LR one trip               |
| A07 | Dedicated Driver entity + CRUD   | `DriverController` apiResource                | Driver as **Party** + `PartyRoleType::Driver`             | Intentional Difference | Info     | 3      | Unified party model                   |
| A08 | Driver bulk import               | `drivers/bulk-upload`                         | No party bulk import in Branch routes                     | Partial                | P2       | 3      | Migration/onboarding                  |
| A09 | Driver license expiry list       | `drivers/license-expiring`                    | Not implemented                                           | Deferred Future Phase  | P2       | 10/11  | Compliance alerts                     |
| A10 | Driver assignment records        | `driver-assignments` apiResource              | Primary driver on vehicle + trip snapshot fields          | Partial                | P2       | 5/10   | Historical assignment audit           |
| A11 | Driver advance ledger            | `DriverAdvanceController`                     | No advance ledger domain                                  | PO Decision            | P2       | —      | Payroll/settlement; not in PRD §11–12 |
| A12 | Truck type master                | `truck-types` apiResource                     | `branch.truck-types.*` catalogue                          | Equivalent             | Info     | 3      | Vehicle classification                |
| A13 | Tyre layout master (config only) | `tyre-layouts` apiResource                    | `branch.tyre-layouts.*` catalogue                         | Equivalent             | Info     | 3/10   | Layout without tyre inventory         |
| A14 | Separate Customer/Broker masters | `customers`, `brokers` apiResource            | Unified `PartyController` + roles                         | Improved               | Info     | 3      | DRY master data                       |

### B. Trips & LR linkage (22)

| ID  | Capability                              | Legacy evidence                          | Nexus evidence                                                                                                   | Classification         | Severity | Phase | Business impact                         |
| --- | --------------------------------------- | ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------- | ---------------------- | -------- | ----- | --------------------------------------- |
| B01 | Standalone trip create/edit             | `trips` apiResource, `UpdateTripRequest` | Trips only via `branch.trips.store-from-lr`                                                                      | Intentional Difference | Info     | 5     | Unified LR architecture                 |
| B02 | One OwnFleet LR → one Trip              | Not in legacy model                      | `TripService` + `lorry_receipt_id` unique                                                                        | Improved               | Info     | 5     | Prevents orphan trips                   |
| B03 | Trip list/show                          | `TripController@index/show`              | `branch.trips.index/show`                                                                                        | Equivalent             | Info     | 5     | Operations hub                          |
| B04 | Multi-point pickups                     | Trip module pickups                      | `TripMovementService::addPickup`                                                                                 | Equivalent             | Info     | 5     | Multi-consignor loads                   |
| B05 | Multi-point drops                       | Trip module drops                        | `TripMovementService::addDrop`                                                                                   | Equivalent             | Info     | 5     | Multi-consignee                         |
| B06 | Pickup/drop edit/delete after add       | `UpdateTripRequest` (full trip graph)    | `DELETE` pickups/drops + cascade (`3aa5bfe`); field correction via remove+re-add                                 | Equivalent             | Info     | 5     | Data correction before dispatch         |
| B07 | Pickup-drop allocations                 | Legacy trip allocation graph             | `storeAllocation` / `destroyAllocation`                                                                          | Equivalent             | Info     | 5     | Freight split                           |
| B08 | Allocation EWB field                    | `TripPickup`/`TripRouteLeg` eway fields  | `ewb_number` on `TripDropPickupAllocation`                                                                       | Improved               | Info     | 5     | Aligns with PRD optional EWB            |
| B09 | Dispatch + starting odometer            | Trip status transition                   | `branch.trips.dispatch`, `TripService`                                                                           | Equivalent             | Info     | 5     | Fleet utilisation                       |
| B10 | Complete + ending odometer + arrival    | Trip complete flow                       | `branch.trips.complete`                                                                                          | Equivalent             | Info     | 5     | Distance + timing KPIs                  |
| B11 | Cancel in-progress trip                 | Trip cancel endpoints                    | `branch.trips.cancel`                                                                                            | Equivalent             | Info     | 5     | Exception handling                      |
| B12 | Soft-delete pending trip                | `DeleteTripRequest`                      | `branch.trips.destroy`                                                                                           | Equivalent             | Info     | 5     | PRD pending-only delete                 |
| B13 | Archive + restore trip                  | `trips/{id}/archive`, `restore`          | No archive; PRD §11 no restore                                                                                   | Intentional Difference | Info     | 5     | Audit retention model differs           |
| B14 | Trip archive/deletion impact preview    | `archive-impact`, `deletion-impact`      | No impact preview routes                                                                                         | Partial                | P2       | 5     | Safer deletes                           |
| B15 | Vehicle date-window / active-trip guard | `trips/check-window`                     | `TripService::assertVehicleHasNoActiveTrip` (`3aa5bfe`) — one Pending/InProgress trip per Own vehicle (Rules §9) | Improved               | Info     | 5     | Prevents double-booking truck           |
| B16 | Trip number preview                     | `trips/next-number`                      | LR/trip numbers via `DocumentNumberAllocator`                                                                    | Partial                | P2       | 5     | UX for manual numbering                 |
| B17 | Substitute driver (trip-only)           | `substitute_driver_id` on Trip           | `use_substitute_driver` in `TripService`                                                                         | Equivalent             | Info     | 5     | PRD §11                                 |
| B18 | Substitute / change vehicle on trip     | Legacy trip `vehicle_id` mutable         | Vehicle read-only from LR (`ed0c6c3` behaviour)                                                                  | Intentional Difference | Info     | 5     | PRD one vehicle per trip                |
| B19 | Trip bilty PDF export                   | `trips/{id}/bilty.pdf`                   | No OwnFleet bilty PDF route                                                                                      | Partial                | P2       | 5     | Print handoff to driver                 |
| B20 | Trip-level planned diesel header fields | Trip model planned diesel columns        | Planned fields on `trip_expenses`                                                                                | Improved               | Info     | 5     | Matches Rules §9 expense-level planning |
| B21 | Unified LR create/edit/print (OwnFleet) | Legacy embedded customer on trip         | `branch.lorry-receipts.*` + print                                                                                | Equivalent             | Info     | 4/5   | Prerequisite for trips                  |
| B22 | OwnFleet LR → create trip UI            | Legacy N/A                               | `create-trip` + Inertia trip flow                                                                                | Improved               | Info     | 5     | Vertical slice UX                       |

### C. Trip expenses & imports (9)

| ID  | Capability                                        | Legacy evidence                      | Nexus evidence                                         | Classification        | Severity | Phase | Business impact               |
| --- | ------------------------------------------------- | ------------------------------------ | ------------------------------------------------------ | --------------------- | -------- | ----- | ----------------------------- |
| C01 | Add/list/delete trip expenses                     | Trip expense endpoints               | `branch.trips.expenses.*`, `TripExpenseService`        | Equivalent            | Info     | 5     | Cost capture                  |
| C02 | Diesel litres × rate + odometer                   | Diesel validation in legacy services | `is_diesel` fields on `TripExpense`                    | Equivalent            | Info     | 5     | Fuel accountability           |
| C03 | Planned diesel / efficiency fields                | Planned columns on trip/expense      | `planned_diesel_*` on expense row                      | Equivalent            | Info     | 5     | Rules §9                      |
| C04 | Trip profit summary                               | Trip totals on legacy Trip           | `TripExpenseService` profit summary + tests            | Equivalent            | Info     | 5     | Margin visibility             |
| C05 | Expense category master                           | `trip-expense-types` apiResource     | Owner catalogue `expense-categories`                   | Improved              | Info     | 3/5   | Shared catalogue pattern      |
| C06 | Bulk trip expense import (analyze/preview/commit) | `TripExpenseImportController`        | Manual expense entry exists; bulk import pack deferred | Deferred Future Phase | P2       | 11    | High-volume diesel log upload |
| C07 | Payment mode + paid-from bank/cash                | Legacy expense payment fields        | `payment_mode_id`, `paid_from_bank_cash_account_id`    | Equivalent            | Info     | 5     | Cash/bank traceability        |
| C08 | Vehicle mileage estimate from diesel chain        | Legacy mileage helpers               | Rules: estimate allowed; no dedicated UI surfaced      | Not Verified          | P2       | 5     | Optional analytics            |
| C09 | Advanced expense module (add-on)                  | Legacy “advanced expense” patterns   | Entitlement add-on in Rules §15; not separate module   | Deferred Future Phase | Info     | 10/PO | Subscription add-on boundary  |

### D. POD & operational documents (6)

| ID  | Capability                              | Legacy evidence                             | Nexus evidence                                                     | Classification        | Severity | Phase | Business impact             |
| --- | --------------------------------------- | ------------------------------------------- | ------------------------------------------------------------------ | --------------------- | -------- | ----- | --------------------------- |
| D01 | POD upload on trip/LR                   | Trip/LR document attachments in legacy      | `branch.documents.*`, `DocumentAttachmentService`                  | Equivalent            | Info     | 5     | Delivery proof              |
| D02 | Document replace with audit             | Replace endpoints                           | `documents/attachments/{id}/replace`                               | Equivalent            | Info     | 5     | Correction trail            |
| D03 | Document type master                    | `document-types`                            | `branch.document-types.*`                                          | Equivalent            | Info     | 3     | Classification              |
| D04 | Document alert settings                 | `document-alert-settings`                   | Not implemented                                                    | Deferred Future Phase | P2       | 9/10  | Expiry reminders            |
| D05 | Fleet compliance alert dashboard widget | `FleetDashboardController@complianceAlerts` | Branch dashboard shell without stats (`BranchDashboardController`) | Deferred Future Phase | P2       | 11    | Owner situational awareness |
| D06 | E-way expiry alerts on dashboard        | `FleetComplianceAlerts` + trip legs         | EWB on LR + allocation; no alert job                               | Partial               | P2       | 11    | Compliance risk visibility  |

### E. Customer invoicing & payments (OwnFleet) (14)

| ID  | Capability                                      | Legacy evidence                 | Nexus evidence                                      | Classification         | Severity | Phase | Business impact                             |
| --- | ----------------------------------------------- | ------------------------------- | --------------------------------------------------- | ---------------------- | -------- | ----- | ------------------------------------------- |
| E01 | Individual trip/LR invoice                      | `trips/{id}/invoices/generate`  | `branch.trip-invoices.store-individual`             | Equivalent             | Info     | 5     | Billing                                     |
| E02 | Consolidated multi-LR invoice                   | `invoices/bulk`                 | `storeConsolidated`, consolidate screen             | Equivalent             | Info     | 5     | Customer convenience                        |
| E03 | Invoice charge edit before payment              | Invoice update                  | `edit-charges` / `updateCharges`                    | Equivalent             | Info     | 5     | Pre-payment corrections                     |
| E04 | Invoice cancel (no payments)                    | Legacy cancel rules             | `trip-invoices.cancel`                              | Equivalent             | Info     | 5     | Re-billing eligibility                      |
| E05 | Soft-delete latest unpaid invoice               | `invoices/{id}/delete`          | `trip-invoices.destroy`                             | Equivalent             | Info     | 5     | Number reuse rules                          |
| E06 | Partial payments + TDS + deductions             | `invoices.payments.store`       | `TripInvoicePaymentService`                         | Equivalent             | Info     | 5     | Settlement                                  |
| E07 | Fill-net final payment                          | Legacy payment UX               | `fill_net` in payment service                       | Equivalent             | Info     | 5     | Close-out                                   |
| E08 | Delete recorded payment                         | `destroyPayment`                | Payments immutable after save (PRD §12)             | Intentional Difference | Info     | 5     | Financial integrity                         |
| E09 | Invoice PDF download                            | `invoices/{id}/pdf`             | Inertia `print` view (sample layout)                | Partial                | P1       | 5     | **Not production tax invoice** (EX pending) |
| E10 | Unified customer invoice list (OwnFleet+Broker) | Separate legacy apps            | `branch.invoices` facade                            | Improved               | Info     | 5/6   | Single navigation                           |
| E11 | Payments index / receipts                       | Legacy payment lists            | `branch.payments.index`                             | Equivalent             | Info     | 5     | Cash application                            |
| E12 | GST/TDS snapshot on invoice                     | Legacy invoice tax fields       | Trip invoice charge/lines + platform tax masters    | Partial                | P1       | 5     | Legal validation Pending (TB-TAX)           |
| E13 | Eligible trips for invoicing picker             | `invoices/eligible-trips`       | Create/consolidate screens + services               | Equivalent             | Info     | 5     | Workflow                                    |
| E14 | Invoice preview before save                     | `previewForTrip`, `previewBulk` | Server validation via store; no named preview route | Partial                | P2       | 5     | UX parity                                   |

### F. Attendance & payroll (12) — legacy module

| ID  | Capability                    | Legacy evidence                          | Nexus evidence                 | Classification        | Severity | Phase | Business impact                 |
| --- | ----------------------------- | ---------------------------------------- | ------------------------------ | --------------------- | -------- | ----- | ------------------------------- |
| F01 | Daily attendance CRUD         | `attendances` apiResource                | Not implemented                | Deferred Future Phase | Info     | 10/PO | Driver wages                    |
| F02 | Bulk mark attendance          | `attendances/bulk-mark`                  | Not implemented                | Deferred Future Phase | Info     | 10/PO | Ops efficiency                  |
| F03 | Month overview calendar       | `attendances/month-overview`             | Not implemented                | Deferred Future Phase | Info     | 10/PO | Planning                        |
| F04 | Monthly wages grid            | `attendances/monthly-wages`              | Not implemented                | Deferred Future Phase | Info     | 10/PO | Payroll prep                    |
| F05 | Settlement sheet + export     | `settlement-sheet` routes                | Not implemented                | Deferred Future Phase | Info     | 10/PO | Accounts handoff                |
| F06 | Close / lock / reopen month   | `close-month`, `lock-month`, etc.        | Not implemented                | Deferred Future Phase | Info     | 10/PO | Period control                  |
| F07 | Mark wages paid (bulk/line)   | `mark-paid` routes                       | Not implemented                | Deferred Future Phase | Info     | 10/PO | Disbursement                    |
| F08 | Payroll settings              | `payroll-settings`                       | Not implemented                | PO Decision           | Info     | —     | Not defined in Nexus PRD §11–12 |
| F09 | Attendance reports + export   | `reports/attendance`                     | Not implemented                | Deferred Future Phase | Info     | 11    | Management reporting            |
| F10 | Vehicle settlement lines      | `vehicle-settlement-line`                | Not implemented                | Deferred Future Phase | Info     | 10/PO | Legacy payroll pattern          |
| F11 | Driver wage month lock entity | `AttendanceMonthLock`                    | Not implemented                | Deferred Future Phase | Info     | 10    | Concurrency                     |
| F12 | Scheduled wage jobs           | `routes/console.php` attendance commands | Nexus scheduler not equivalent | Not Verified          | Info     | 10/11 | Background automation           |

### G. Tyre management (14)

| ID  | Capability                       | Legacy evidence                   | Nexus evidence     | Classification        | Severity | Phase | Business impact   |
| --- | -------------------------------- | --------------------------------- | ------------------ | --------------------- | -------- | ----- | ----------------- |
| G01 | Tyre inventory CRUD              | `tyres` apiResource               | Not implemented    | Deferred Future Phase | Info     | 10    | Asset lifecycle   |
| G02 | Tyre bulk import                 | `tyres/bulk-upload`               | Not implemented    | Deferred Future Phase | Info     | 10    | Onboarding        |
| G03 | Install / remove / rotate        | tyre install/remove/rotate routes | Not implemented    | Deferred Future Phase | Info     | 10    | Fitment history   |
| G04 | Tyre damage / inspection / scrap | damage/inspection/scrap routes    | Not implemented    | Deferred Future Phase | Info     | 10    | Compliance & cost |
| G05 | Tyre expenses linked to asset    | `tyres/{id}/expenses`             | Not implemented    | Deferred Future Phase | Info     | 10    | COGS tracking     |
| G06 | Tyre alerts dashboard            | `tyres/alerts`                    | Not implemented    | Deferred Future Phase | Info     | 10/11 | Safety            |
| G07 | Vehicle tyre positions map       | `vehicles/{id}/tyre-positions`    | Layout master only | Deferred Future Phase | Info     | 10    | Visual fitment    |
| G08 | Vehicle tyre history             | `vehicles/{id}/tyre-history`      | Not implemented    | Deferred Future Phase | Info     | 10    | Audit             |
| G09 | Tyre life report                 | `reports/tyres/life`              | Not implemented    | Deferred Future Phase | Info     | 11    | Management        |
| G10 | Tyre cost-per-km report          | `reports/tyres/cost-per-km`       | Not implemented    | Deferred Future Phase | Info     | 11    | Economics         |
| G11 | Tyre replacement report          | `reports/tyres/replacements`      | Not implemented    | Deferred Future Phase | Info     | 11    | Planning          |
| G12 | Vehicle-wise tyre usage          | `reports/tyres/vehicle-wise`      | Not implemented    | Deferred Future Phase | Info     | 11    | Fleet review      |
| G13 | Tyre expense summary report      | `reports/tyres/expense-summary`   | Not implemented    | Deferred Future Phase | Info     | 11    | P&L support       |
| G14 | Tyre report export               | `reports/tyres/export`            | Not implemented    | Deferred Future Phase | Info     | 11    | Excel handoff     |

### H. Battery management (8)

| ID  | Capability                  | Legacy evidence                | Nexus evidence                  | Classification        | Severity | Phase | Business impact |
| --- | --------------------------- | ------------------------------ | ------------------------------- | --------------------- | -------- | ----- | --------------- |
| H01 | Battery inventory CRUD      | `batteries` apiResource        | Not implemented                 | Deferred Future Phase | Info     | 10    | Asset lifecycle |
| H02 | In-stock counts             | `batteries/counts`, `in-stock` | Not implemented                 | Deferred Future Phase | Info     | 10    | Store control   |
| H03 | Install / remove on vehicle | install/remove routes          | Not implemented                 | Deferred Future Phase | Info     | 10    | Fitment         |
| H04 | Battery replace workflow    | `batteries/replace`            | Not implemented                 | Deferred Future Phase | Info     | 10    | Uptime          |
| H05 | Battery inspection          | `batteries/{id}/inspections`   | Not implemented                 | Deferred Future Phase | Info     | 10    | Maintenance     |
| H06 | Battery scrap               | `batteries/{id}/scrap`         | Not implemented                 | Deferred Future Phase | Info     | 10    | Write-off       |
| H07 | Vehicle batteries list      | `vehicles/{id}/batteries`      | Not implemented                 | Deferred Future Phase | Info     | 10    | History         |
| H08 | Battery entitlement add-on  | Rules §15 add-on               | Subscription module gating only | Deferred Future Phase | Info     | 10    | Commercial      |

### I. Vehicle maintenance (8)

| ID  | Capability                      | Legacy evidence                    | Nexus evidence  | Classification        | Severity | Phase | Business impact     |
| --- | ------------------------------- | ---------------------------------- | --------------- | --------------------- | -------- | ----- | ------------------- |
| I01 | Maintenance record CRUD         | `vehicle-maintenances` apiResource | Not implemented | Deferred Future Phase | Info     | 10    | Service history     |
| I02 | Due services list               | `vehicle-maintenances/due`         | Not implemented | Deferred Future Phase | Info     | 10    | Preventive schedule |
| I03 | Per-vehicle maintenance list    | `vehicles/{id}/maintenances`       | Not implemented | Deferred Future Phase | Info     | 10    | Workshop context    |
| I04 | Maintenance service types       | `maintenance-service-types`        | Not implemented | Deferred Future Phase | Info     | 10    | Catalogue           |
| I05 | Maintenance cost summary report | `reports/maintenance/cost-summary` | Not implemented | Deferred Future Phase | Info     | 11    | Cost control        |
| I06 | Truck-wise maintenance report   | `reports/maintenance/truck-wise`   | Not implemented | Deferred Future Phase | Info     | 11    | Fleet review        |
| I07 | Due services report             | `reports/maintenance/due-services` | Not implemented | Deferred Future Phase | Info     | 11    | Compliance planning |
| I08 | Maintenance report export       | `reports/maintenance/export`       | Not implemented | Deferred Future Phase | Info     | 11    | Excel handoff       |

### J. Compliance / RTO / fleet documents (10)

| ID  | Capability                             | Legacy evidence                            | Nexus evidence                                   | Classification        | Severity | Phase | Business impact           |
| --- | -------------------------------------- | ------------------------------------------ | ------------------------------------------------ | --------------------- | -------- | ----- | ------------------------- |
| J01 | Vehicle document registry              | `vehicle-documents` apiResource            | Generic document attachments on vehicle master   | Partial               | P2       | 10    | RTO paper tracking        |
| J02 | Document download/preview              | download/preview routes                    | `branch.documents.download`                      | Equivalent            | Info     | 5/10  | Access                    |
| J03 | Fitness/insurance/permit expiry alerts | `FleetComplianceAlerts`, document statuses | No scheduled compliance engine                   | Deferred Future Phase | P2       | 10/11 | Statutory risk            |
| J04 | EMI compliance records                 | `compliance/emis` API + scheduled command  | Not implemented                                  | Deferred Future Phase | Info     | 10/PO | Legacy-only surface       |
| J05 | Traffic challan records                | `challans` apiResource                     | Not implemented (distinct from Partload challan) | Deferred Future Phase | Info     | 10/PO | Fine tracking             |
| J06 | Dashboard compliance widget            | `dashboard/compliance-alerts`              | Not implemented                                  | Deferred Future Phase | P2       | 11    | Daily ops                 |
| J07 | E-way bill tracking on movements       | Trip leg eway fields                       | LR `eway_bills` + allocation `ewb_number`        | Improved              | Info     | 4/5   | Unified consignment model |
| J08 | EMI due scheduled job                  | `compliance:check-emi-due`                 | Not implemented                                  | Deferred Future Phase | Info     | 11    | Automation                |
| J09 | Invoice due reminders job              | `invoices:send-due-reminders`              | Phase 9 notifications deferred                   | Deferred Future Phase | Info     | 9     | Collections               |
| J10 | Vehicle document entitlement add-on    | Rules §15                                  | Subscription gating                              | Deferred Future Phase | Info     | 10    | Commercial                |

### K. Monthly fleet expenses (5)

| ID  | Capability                     | Legacy evidence                      | Nexus evidence  | Classification        | Severity | Phase | Business impact   |
| --- | ------------------------------ | ------------------------------------ | --------------- | --------------------- | -------- | ----- | ----------------- |
| K01 | Monthly expense entries CRUD   | `monthly-expenses` apiResource       | Not implemented | Deferred Future Phase | Info     | 10    | Overhead capture  |
| K02 | Fleet monthly P&L-style report | `reports/fleet-monthly`              | Not implemented | Deferred Future Phase | Info     | 11    | Owner review      |
| K03 | Fleet monthly export           | `reports/fleet-monthly/export`       | Not implemented | Deferred Future Phase | Info     | 11    | External analysis |
| K04 | Vehicle utilization report     | `reports/vehicle-utilization`        | Not implemented | Deferred Future Phase | Info     | 11    | Capacity planning |
| K05 | Utilization export             | `reports/vehicle-utilization/export` | Not implemented | Deferred Future Phase | Info     | 11    | Excel handoff     |

### L. Operational reports (trip/fleet) (8)

| ID  | Capability                  | Legacy evidence                     | Nexus evidence                      | Classification        | Severity | Phase | Business impact |
| --- | --------------------------- | ----------------------------------- | ----------------------------------- | --------------------- | -------- | ----- | --------------- |
| L01 | Trip profitability report   | `reports/trips/profitability`       | Not implemented                     | Deferred Future Phase | P2       | 11    | Margin analysis |
| L02 | Vehicle-wise trip report    | `reports/trips/vehicle-wise`        | Not implemented                     | Deferred Future Phase | Info     | 11    | Utilisation     |
| L03 | Driver-wise trip report     | `reports/trips/driver-wise`         | Not implemented                     | Deferred Future Phase | Info     | 11    | Performance     |
| L04 | Trip expense summary report | `reports/trips/expense-summary`     | Not implemented                     | Deferred Future Phase | Info     | 11    | Cost scrutiny   |
| L05 | Route profitability report  | `reports/trips/route-profitability` | Not implemented                     | Deferred Future Phase | Info     | 11    | Lane decisions  |
| L06 | Fuel performance report     | `reports/trips/fuel-performance`    | Not implemented                     | Deferred Future Phase | Info     | 11    | MPG tracking    |
| L07 | Trip reports export         | `reports/trips/export`              | Not implemented                     | Deferred Future Phase | Info     | 11    | Excel           |
| L08 | Fleet dashboard stats API   | `dashboard/stats`                   | Branch dashboard without fleet KPIs | Deferred Future Phase | P2       | 11    | Landing insight |

### M. Platform / shell (8)

| ID  | Capability                           | Legacy evidence              | Nexus evidence                    | Classification        | Severity | Phase | Business impact                  |
| --- | ------------------------------------ | ---------------------------- | --------------------------------- | --------------------- | -------- | ----- | -------------------------------- |
| M01 | Standalone OwnFleet SPA shell        | Vue `vue-app` catch-all      | Inertia Branch workspace          | Improved              | Info     | 1–2   | Unified product                  |
| M02 | Legacy subscription provisioning API | `SubscriptionGuard` patterns | Nexus entitlement resolver        | Deprecated            | Info     | 3     | Multi-repo provisioning rejected |
| M03 | Separate goods type bulk import      | `goods-types/bulk-upload`    | Catalogue UI (no bulk)            | Partial               | P2       | 3     | Data migration                   |
| M04 | Station bulk import                  | `stations/bulk-upload`       | No bulk on `StationController`    | Partial               | P2       | 3     | Migration                        |
| M05 | Quick search global                  | `quick-search`               | Not implemented                   | Deferred Future Phase | P2       | 11    | Power-user UX                    |
| M06 | In-app notification centre           | `notifications` routes       | Phase 9 deferred                  | Deferred Future Phase | Info     | 9     | Comms                            |
| M07 | Super-admin group dashboard          | `dashboard/group`            | Owner/Internal dashboards partial | Partial               | P2       | 2A/11 | Portfolio view                   |
| M08 | Float monetary arithmetic            | Legacy helpers               | bcmath in domain services         | Deprecated            | Info     | 5/6   | Financial integrity              |

---

## Top Phase 5 scope gaps (P0 / P1)

These are **not** deferred Phase 10/11 asset or report modules. They affect approved **Phase 5** OwnFleet operations or immediate Phase 4 LR prerequisites.

| Rank | ID  | Gap                                               | Classification          | Severity | Rationale                                                                   |
| ---- | --- | ------------------------------------------------- | ----------------------- | -------- | --------------------------------------------------------------------------- |
| 1    | B15 | Vehicle active-trip guard                         | **Closed — Improved**   | —        | `3aa5bfe` Rules §9 one Pending/In Progress trip per Own vehicle             |
| 2    | B06 | Pickup/drop remove pre-dispatch                   | **Closed — Equivalent** | —        | `3aa5bfe` DELETE + allocation cascade; field fix via remove+re-add          |
| 3    | C06 | Trip expense bulk import (analyze/preview/commit) | Deferred Future Phase   | **P2**   | Not Phase 5 exit; schedule with import/report pack                          |
| 4    | E09 | Customer invoice PDF is print/sample only         | Partial                 | **P1**   | Production tax PDF explicitly Pending (TB-TAX / EX) — blocks legal dispatch |
| 5    | E12 | Statutory GST/TDS invoice validation              | Partial                 | **P1**   | Masters exist; production compliance gate open                              |
| 6    | B19 | OwnFleet trip/bilty PDF                           | Partial                 | **P2**   | Operational print; LR print may partially substitute                        |

**P0 note:** No **P0** “completely missing” blocker was found for the core Phase 5 chain (OwnFleet LR → Trip → dispatch/complete → expenses → invoice → payment) given current Nexus codebase and Pest/Playwright evidence in `docs/engineering/PHASE_5_OWNFLEET_FINAL_WORKFLOW_CERTIFICATION.md`. Remaining P0 risk is **external** (production tax, live payments), not legacy parity.

---

## Deferred fleet modules (legacy present → Nexus phase)

Do **not** treat these as Phase 5 defects:

| Legacy module                                    | Approx. capability count | Nexus phase | PO / notes                                           |
| ------------------------------------------------ | -----------------------: | ----------- | ---------------------------------------------------- |
| Attendance & payroll                             |                       12 | 10 / PO     | Not in PRD §11–12; `PO Decision` on payroll settings |
| Tyres + tyre reports                             |                       14 | 10 / 11     | Rules §15 Tyre add-on                                |
| Batteries                                        |                        8 | 10          | Rules §15 Battery add-on                             |
| Vehicle maintenance + reports                    |                        8 | 10 / 11     | Rules §15 Maintenance add-on                         |
| Vehicle documents & compliance alerts            |                       10 | 10 / 11     | Distinct from trip POD                               |
| Monthly fleet expenses + fleet monthly report    |                        5 | 10 / 11     | Overhead, not trip diesel                            |
| Trip/fleet operational reports + dashboard stats |                        8 | 11          | `Phases.md`: reports after source workflows          |
| Notifications / quick search / platform          |                       2+ | 9 / 11      | Comms + UX (see section M)                           |

---

## Intentional Nexus redesigns (not backlog)

1. **LR-linked trips only** — legacy standalone trips (B01).
2. **Driver as Party role** — no parallel Driver table (A07).
3. **Vehicle fixed to OwnFleet LR** — no mid-trip vehicle swap (B18).
4. **Invoice payments immutable** — no `destroyPayment` (E08).
5. **No trip restore** — archive/restore rejected (B13).
6. **Unified masters** — customers/brokers/stations via Party & shared masters (A14).
7. **EWB primarily on LR** with optional allocation reference (J07).

---

## Evidence index (representative)

| Area            | Legacy                                   | Nexus                                                                |
| --------------- | ---------------------------------------- | -------------------------------------------------------------------- |
| Routes          | `_legacy_refs/.../routes/web.php`        | `nexus/routes/web.php`                                               |
| Trip            | `Api/TripController.php`, `app/Trip.php` | `Branch/TripController.php`, `Domains/OwnFleet/Trip/*`               |
| Invoice         | `Api/InvoiceController.php`              | `Branch/TripInvoiceController.php`, `Domains/OwnFleet/Invoice/*`     |
| Vehicle         | `Api/VehicleController.php`              | `Branch/VehicleController.php`, `Domains/Master/Vehicle/*`           |
| Driver          | `Api/DriverController.php`               | Party + Driver role on Vehicle/Trip                                  |
| Phase authority | —                                        | `docs/governing/Phases.md` §5, §10, §11                              |
| Prior matrix    | —                                        | `docs/engineering/LEGACY_TO_NEXUS_COMPREHENSIVE_PARITY_MATRIX.md` §C |

---

## Audit verdict

| Dimension                  | Assessment                                                                                  |
| -------------------------- | ------------------------------------------------------------------------------------------- |
| **Phase 5 core workflow**  | **Largely equivalent or improved** vs legacy trip/invoicing spine, with LR-centric redesign |
| **Phase 5 parity gaps**    | **2** Missing Current Scope; **16** Partial — see P1 table                                  |
| **Legacy fleet ops depth** | **67** capabilities correctly **Deferred Future Phase** (mostly Phase 10/11)                |
| **Certification**          | Independent from this doc — see Phase 5/6 gap register for CI/Playwright/MySQL residuals    |

---

_Document generated by automated audit pass. Re-validate after Phase 10/11 authorization or material OwnFleet changes._
