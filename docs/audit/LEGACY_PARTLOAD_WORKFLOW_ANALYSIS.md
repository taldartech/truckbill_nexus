# Legacy Partload Workflow Analysis

**Audit date:** 24 November 2026  
**Legacy repo:** `truckbill_partload` @ **`fb9b955e64f6370c662a3a157081a96596a33690`** (account)  
**Legacy read-only path:** `legacy/truckbill_partload/`  
**Nexus HEAD:** `e05d1d6f505ac076963ede60b86e4ebe062e2edb`  
**Phase status:** Phase 7 **not authorized** for implementation — analysis only.

---

## Classification legend

Same as `LEGACY_BROKER_WORKFLOW_ALIGNMENT.md`.

---

## Product model (legacy)

Partload is a **station-network** operation:

1. **Bilty** (consignment note) at origin — multi-line goods, GST, cartage account, agents.
2. **Inward register** — goods received at hub.
3. **Challan** — line-haul manifest (truck, driver, station pair).
4. **Main challan** — aggregates challans / truck freight settlements.
5. **Delivery receipt** — GR-linked delivery + accounting bridge.
6. **Invoice** — bilty-level customer billing.
7. **Vouchers** — payment instruments with entity balance AJAX.
8. **Accounting** — Tally-style COA, operational bridge on every posting document.

Primary route file: `legacy/truckbill_partload/routes/web.php` (lines 92–208 subscription-gated block).

---

## Controller / workflow register

| Workflow             | Controller                                                                                                              | Middleware permissions                              | Key methods                                                      |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- | ---------------------------------------------------------------- |
| Bilty CRUD + print   | `BiltyController`                                                                                                       | `view/create/edit/delete bilties`                   | `index`, `create`, `store`, `edit`, `update`, `destroy`, `print` |
| Challan CRUD + print | `ChallanController`                                                                                                     | `view/create/edit/delete challans`                  | `index`, `store`, `update`, links `MainChallan`                  |
| Main challan         | `MainChallanController`                                                                                                 | challan permissions                                 | `store`, `update`, truck freight totals                          |
| Inward register      | `InwardRegisterController`                                                                                              | inward permissions                                  | `store`, `update` + `OperationalAccountingBridge`                |
| Delivery receipt     | `DeliveryReceiptController`                                                                                             | `delivery_receipts`                                 | `lookupGr`, `store`, `print`                                     |
| Invoice              | `InvoiceController`                                                                                                     | invoice permissions                                 | `pendingBilties`, `store`, `print`                               |
| Voucher              | `VoucherController`                                                                                                     | voucher permissions                                 | `entityBalance`                                                  |
| Quick search         | `QuickSearchController::index`                                                                                          | —                                                   | Cross-document lookup                                            |
| Masters              | `PartyController`, `StationController`, `AgentController`, `GoodController`, `PackageTypeController`, `GroupController` | + bulk/quick create                                 | Partload-specific catalogue                                      |
| Loading report       | `LoadingReportController`                                                                                               | `create`, `print`                                   | Operational print                                                |
| Registers            | `ReportController`, `LedgerReportController`                                                                            | `bilty-register`, `challan-register`, party ledgers |                                                                  |
| Accounting           | `Accounting\*Controller`                                                                                                | Full COA + FY close + 15+ reports                   | ADR-010 analog in legacy only                                    |
| Onboarding API       | `Api\OnboardingController`                                                                                              | HMAC `onboarding.signature`                         | User provision from admin                                        |

### Bilty domain (evidence)

| Concern    | Path                                                                                                       |
| ---------- | ---------------------------------------------------------------------------------------------------------- |
| Validation | `app/Http/Requests/BiltyRequest.php` — `details.*.charged_weight` required, max 120000                     |
| Model      | `app/Models/Bilty.php` — `consignor`, `consignee`, `fromStation`, `toStation`, `biltyDetails`, `ewayBills` |
| Store tx   | `BiltyController::store` — detail rows + `OperationalAccountingBridge`                                     |
| Print      | `App\Support\BiltyPrint`                                                                                   |
| UI confirm | `resources/js/Components/FormConfirm/BiltyConfirmModal.vue` — actual vs charged weight totals              |

Weights on bilty lines are operator-entered numerics (legacy UI labels Actual Wt / Charged Wt); Nexus must map to `OPERATIONAL_WEIGHT_UNIT_STANDARD.md` at Phase 7.

### Challan / main challan

- `ChallanController` loads `Bilty` lines into manifest details; status filters on index.
- `MainChallanController::store` — truck-level freight (`truck_freight` in Vue `MainChallans/Create.vue`).

### Delivery + inward linkage

- `DeliveryReceiptController::lookupGr` — JSON GR lookup before create.
- `InwardRegisterController` pairs with inward detail lines feeding challan eligibility.

---

## Nexus current state (Phase 4 foundation only)

| Legacy workflow            | Nexus                                                               | Classification                               |
| -------------------------- | ------------------------------------------------------------------- | -------------------------------------------- |
| Partload bilty             | Unified LR `workflow_context=partload` via `LorryReceiptController` | **Partial** — LR form only; no challan chain |
| Station masters            | `StationController`                                                 | **Equivalent**                               |
| Party / agent              | `PartyController` — no Agent role catalogue parity                  | **Partial**                                  |
| Package type / goods group | Catalogue seeds — no Partload `GroupController` / `PackageType` UI  | **Missing**                                  |
| Challan                    | No `Challan` domain in `app/`                                       | **Missing**                                  |
| Main challan               | No domain                                                           | **Missing**                                  |
| Inward register            | No domain                                                           | **Missing**                                  |
| Delivery receipt           | No domain                                                           | **Missing**                                  |
| Partload invoice           | Unified customer invoice — not bilty-pending picker                 | **Missing**                                  |
| Partload vouchers          | Branch bank/voucher patterns — not entity balance AJAX              | **Partial**                                  |
| Quick search               | No cross-doc quick search                                           | **Missing**                                  |
| Partload accounting stack  | Deferred ADR-010                                                    | **Deferred**                                 |
| Operational reports        | None                                                                | **Deferred** P11                             |
| Enquiry → convert          | `EnquiryController::convert` supports `partload` context            | **Partial** — not inward/challan             |

Evidence: `grep` for `Challan|MainChallan|InwardRegister` under `app/` → **no matches** (24 Nov 2026).

### Entitlement / UI hints

- `LrWorkflowContextResolver::hasPartloadWorkflow`
- Sidebar partload create URL when entitled (`LorryReceiptController::index` props)
- `EnquiryController` validation `workflow_context` includes `partload`

---

## PLD-* summary register (for matrix)

| ID     | Capability                            | Legacy                                     | Nexus                       | Classification         |
| ------ | ------------------------------------- | ------------------------------------------ | --------------------------- | ---------------------- |
| PLD-01 | Bilty CRUD                            | `BiltyController`                          | Unified LR partload context | Partial                |
| PLD-02 | Bilty print                           | `BiltyController::print`                   | LR print                    | Partial                |
| PLD-03 | Bilty GST / exemption                 | `GstCalculator`, branch settings           | LR charges — not verified   | Not Verified           |
| PLD-04 | E-way on bilty                        | `Bilty` ewayBills                          | LR ewayBills                | Improved               |
| PLD-05 | Challan                               | `ChallanController`                        | —                           | Missing                |
| PLD-06 | Main challan                          | `MainChallanController`                    | —                           | Missing                |
| PLD-07 | Inward register                       | `InwardRegisterController`                 | —                           | Missing                |
| PLD-08 | Delivery receipt + GR lookup          | `DeliveryReceiptController`                | —                           | Missing                |
| PLD-09 | Customer invoice from pending bilties | `InvoiceController::pendingBilties`        | Unified invoice             | Missing                |
| PLD-10 | Vouchers + entity balance             | `VoucherController::entityBalance`         | —                           | Partial                |
| PLD-11 | Agents + station agents               | `AgentController`, `CompanyStationAgent`   | —                           | Missing                |
| PLD-12 | Package types / groups                | `PackageTypeController`, `GroupController` | —                           | Missing                |
| PLD-13 | Quick search                          | `QuickSearchController`                    | —                           | Missing                |
| PLD-14 | Loading report                        | `LoadingReportController`                  | —                           | Missing                |
| PLD-15 | Registers (bilty/challan)             | `ReportController`                         | —                           | Deferred               |
| PLD-16 | Accounting COA + bridge               | `Accounting\*`                             | ADR-010                     | Deferred               |
| PLD-17 | Masters bulk/quick                    | Party/station quick routes                 | Partial owner/branch        | Partial                |
| PLD-18 | Branch settings (GST limits)          | `BranchSettingsController`                 | Branch settings subset      | Partial                |
| PLD-19 | Truck on challan                      | `TruckController` on challan               | Vehicles master only        | Partial                |
| PLD-20 | Onboarding API                        | `Api\OnboardingController`                 | Nexus onboarding            | Intentional Difference |

**PLD totals (20 rows):** Equivalent 0 · Improved 1 · Partial 6 · Missing 10 · Deferred 2 · Not Verified 1 · Intentional Difference 1

---

## Batch 1 relevance (unified LR before Phase 7)

Before Phase 7 coding, Nexus Batch 1 closes gaps on the **shared LR spine** used by Partload context:

- Searchable party selection on `lr-form.tsx` (legacy Partload uses full party lists with quick-create).
- MT/KG weight standard on goods rows (`lr-form.tsx` + `OperationalWeight`).

These are prerequisites for honest bilty parity, not a substitute for challan/inward/delivery workflows.

---

## Test-backed legacy behaviours (for Phase 7 planning)

| Test                                                      | Theme                            |
| --------------------------------------------------------- | -------------------------------- |
| `tests/Feature/BiltyAdvancePaymentModeTest.php`           | Advance payment modes on bilty   |
| `tests/Feature/BiltyChargeMinimumTest.php`                | Minimum charge rules             |
| `tests/Feature/GstExemptionLimitTest.php`                 | Branch GST exemption threshold   |
| `tests/Feature/MainChallanAccountingTest.php`             | Main challan → accounting bridge |
| `tests/Feature/Accounting/OperationalBridgeRulesTest.php` | Posting invariants               |

---

## Legacy SHA record

`fb9b955e64f6370c662a3a157081a96596a33690` (account branch) — verified read-only under `legacy/truckbill_partload/`.

_No legacy files modified. No Phase 7 implementation._
