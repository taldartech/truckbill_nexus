# Legacy OwnFleet Workflow Alignment

**Audit date:** 24 November 2026  
**Legacy repo:** `truckbill_ownfleet` @ **`9423293edd8b4e313198728f687f657d4a64c27f`** (development)  
**Legacy read-only path:** `legacy/truckbill_ownfleet/`  
**Nexus HEAD:** `e05d1d6f505ac076963ede60b86e4ebe062e2edb`  
**Detail register:** `LEGACY_OWNFLEET_FUNCTIONAL_PARITY.md` (OF-* rows)

---

## Classification legend

Same as `LEGACY_BROKER_WORKFLOW_ALIGNMENT.md`.

---

## Architecture note

Legacy OwnFleet is a **Vue SPA** behind `routes/web.php` `app/*` with JSON API under `app/Http/Controllers/Api/*`. Nexus consolidates OwnFleet into **branch Inertia** routes plus `app/Domains/OwnFleet/**`, with trips anchored to **unified LR** (`LrWorkflowContext::OwnFleet`) — intentional product redesign.

---

## Workflow spine (legacy)

| Domain               | API / route evidence                                                            | Key methods                                                                              |
| -------------------- | ------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Tenancy              | `ApiCompanyController`, `ApiBranchController`                                   | `enter`, `exit`, `switch`                                                                |
| Vehicles / drivers   | `Api\VehicleController`, `Api\DriverController`                                 | CRUD, assign, documents                                                                  |
| Trips                | `Api\TripController` (resource)                                                 | `store`, `update`, `archive`, `restore`, `biltyPdf`, `nextTripNumber`, `checkTripWindow` |
| Trip expenses        | Trip expense APIs + `TripExpenseImportController`                               | `analyze`, `preview`, `store`                                                            |
| Invoicing            | `Api\InvoiceController`                                                         | `eligibleTrips`, `generateForTrip`, `previewForTrip`                                     |
| Fleet assets         | Tyre, battery, maintenance, compliance controllers (see `routes/web.php` ~400+) | Phase 10 legacy module                                                                   |
| Payroll / attendance | Dedicated API route groups                                                      | Phase 10                                                                                 |
| Reports              | `Api\TripReportController`                                                      | profitability, vehicle-wise, fuel-performance, etc.                                      |
| Subscription         | `ApiSubscriptionController`                                                     | Mirrors product-app Razorpay                                                             |

### Trip UI (legacy JS)

| Asset            | Path                                                           | Behaviour                                                             |
| ---------------- | -------------------------------------------------------------- | --------------------------------------------------------------------- |
| Multi-point legs | `resources/js/vue-app/composables/useTripForm.js`              | `weight_ton`, `freight_auto_calculate`, `calculateLegFreight`         |
| Route builder    | `resources/js/vue-app/components/trips/TripFormRedesigned.vue` | Leg freight toggle + MT tonne fields                                  |
| Leg math         | `resources/js/vue-app/utils/multiPointDrops.js`                | `calculateLegFreight(allocation.weight_ton, allocation.freight_rate)` |

Legacy freight legs use **tonnes in the UI**; Nexus stores LR goods in **KG** and trip allocations via domain services (see `OPERATIONAL_WEIGHT_UNIT_STANDARD.md`).

---

## Nexus workflow spine (Phase 5 scope)

| Step              | Nexus                                        | Evidence                            |
| ----------------- | -------------------------------------------- | ----------------------------------- |
| LR (own fleet)    | `LorryReceiptController` context `own_fleet` | Requires `vehicle_id`, station pair |
| Trip from LR      | `TripController` + `TripService`             | `routes/web.php` branch trips       |
| Movement          | Dispatch / complete / odometer endpoints     | Domain state machine                |
| Expenses / diesel | Trip expense controllers + catalogues        | Branch permissions                  |
| POD / docs        | Document attachment on trip/LR               | `DocumentAttachmentService`         |
| Trip invoice      | `TripInvoiceController`                      | Unified customer invoice facade     |
| Vehicles          | `VehicleController`, compliance hooks        | Owner + branch                      |

**Not in Nexus (authorized deferral):** tyres, batteries, full maintenance workshop, payroll, legacy trip expense CSV import (OF-C06 → Phase 11), most `TripReportController` exports.

---

## Workflow alignment summary

| Workflow                      | Classification             | Severity | Notes                                                                         |
| ----------------------------- | -------------------------- | -------- | ----------------------------------------------------------------------------- |
| LR → trip create              | **Improved**               | P2       | LR-first vs legacy standalone trip bilty                                      |
| Multi-point trip / drops      | **Partial**                | P1       | Core allocations exist; legacy Vue route builder richer                       |
| Trip edit / archive           | **Equivalent**             | P2       | `archive`/`restore` patterns in Nexus                                         |
| Trip expense entry            | **Partial**                | P2       | No bulk import (deferred P11)                                                 |
| Trip → customer invoice       | **Partial**                | P2       | Nexus trip invoice; legacy `generateForTrip` feature depth not fully verified |
| Vehicle active trip guard     | **Improved**               | P2       | Closed in Nexus (`3aa5bfe` era)                                               |
| Fleet compliance / assets     | **Deferred**               | P1       | Phase 10 — 50+ legacy API groups                                              |
| Operational reports           | **Deferred**               | P1       | Phase 11                                                                      |
| Standalone subscription shell | **Intentional Difference** | —        | Owner subscription                                                            |

### Batch 1 cross-cutting (LR)

OwnFleet LR uses same `lr-form.tsx` as Broker/Partload station modes:

- **Party selector:** Partial — `SearchablePartySelect` not on LR form yet (Batch 1).
- **Weight:** Partial — MT/KG standard applies to goods rows before trip allocation.

---

## Overstated “Equivalent” corrections (own fleet)

| ID     | Prior      | **Revised** | Rationale                                                                      |
| ------ | ---------- | ----------- | ------------------------------------------------------------------------------ |
| OF-B02 | Equivalent | **Partial** | Trip create exists; multi-leg UX vs `TripFormRedesigned.vue` not parity        |
| OF-B18 | Equivalent | **Partial** | Leg freight auto-calc uses legacy `weight_ton`; Nexus allocation model differs |
| OF-E05 | Equivalent | **Partial** | Invoice generation path exists; line-level parity not verified this pass       |

---

## Evidence index

| Capability     | Legacy                               | Nexus                                      |
| -------------- | ------------------------------------ | ------------------------------------------ |
| Trip store     | `Api\TripController::store`          | `TripController::store` → `TripService`    |
| Trip bilty PDF | `TripController::biltyPdf`           | LR print + document service (Not Verified) |
| Expense import | `TripExpenseImportController::store` | Missing — Deferred P11                     |

_No legacy files modified._
