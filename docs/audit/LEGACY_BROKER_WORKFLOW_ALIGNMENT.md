# Legacy Broker Workflow Alignment

**Audit date:** 24 November 2026  
**Legacy repo:** `truckbill_broker` @ **`6a90e955bd7a05497f60a6d0bd72891c880e6d14`** (stable)  
**Legacy read-only path:** `legacy/truckbill_broker/`  
**Nexus HEAD:** `6584e543e9995c76d1bc8033fc4023bd7670eafc`  
**Detail register:** `LEGACY_BROKER_FUNCTIONAL_PARITY.md` (BRK-* rows)

---

## Classification legend

| Classification         | Meaning                                                |
| ---------------------- | ------------------------------------------------------ |
| Equivalent             | Same business outcome at workflow level                |
| Partial                | Spine exists; material step or field behaviour missing |
| Missing                | No Nexus counterpart in authorized scope               |
| Deferred               | Phase 8/11 or explicit deferral                        |
| Intentional Difference | Approved Nexus redesign                                |
| PO Decision Required   | Policy not decided                                     |
| Not Verified           | Not re-tested this pass                                |

---

## Workflow spine (legacy)

Primary routes: `legacy/truckbill_broker/routes/web.php`

| Step                  | Legacy controller / method                                                                                                        | Route prefix                                    |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------- |
| Masters               | `TransporterController`, `TruckController`, `DriverController`, `ConsigneeController`, `PumpController`, master/*                 | `transporters`, `trucks`, `drivers`, `master/*` |
| LR (bilty)            | `LorryReceiptController::{index,create,store,edit,update,destroy,createLinked,bulkPayment}`                                       | `lorry_receipts`                                |
| Hire                  | `FreightBookingController::{index,create,store,show,edit,update,confirm,settlementStatement,voucherPayment,storeCombinedPayment}` | `freight_bookings`                              |
| Vouchers              | `VoucherPaymentController::{index,create,store,edit,update,destroy,bulkPayment,exportPayoutFile,dailyPayments}`                   | `voucher_payments`                              |
| Customer invoice      | `InvoiceController`, `CustomInvoiceController`                                                                                    | `invoices`, `custom-invoices`                   |
| Reports               | `ReportController`, `PartyLedgerReportController`                                                                                 | `reports/*`                                     |
| Accounting            | `AccountingVoucherController`, `LedgerController`                                                                                 | `accounting/*`                                  |
| Subscription (in-app) | `SubscriptionController`                                                                                                          | `subscription/*`                                |

### Key domain services (legacy)

| Service      | Path                                                | Role                                                                                 |
| ------------ | --------------------------------------------------- | ------------------------------------------------------------------------------------ |
| LR amounts   | `app/Services/LorryReceiptCalculationService.php`   | `resolveForStore`, `resolveForUpdate`, sync hire                                     |
| Hire amounts | `app/Services/FreightBookingCalculationService.php` | `calculateForStore`, `recalculateFromLorryReceipt`, `recalculateFromVoucherPayments` |
| TDS sync     | `app/Services/FreightBookingTdsSync.php`            | Recalc on transporter TDS change                                                     |

Legacy hire math uses **float** `weight × trFrRate` (`FreightBookingCalculationService::calculateForStore`); Nexus uses **bcmath** goods rows + `FreightCalculator` (see `OPERATIONAL_WEIGHT_UNIT_STANDARD.md`).

---

## Nexus workflow spine

| Step          | Nexus surface                                                 | Evidence                                                     |
| ------------- | ------------------------------------------------------------- | ------------------------------------------------------------ |
| Unified LR    | `Branch\LorryReceiptController` + `LorryReceiptService`       | `routes/web.php` `branch.lorry-receipts.*`, context `broker` |
| Hire          | `Broker\LorryHireController` + `LorryHireCalculationService`  | `/broker/lorry-hires/*`                                      |
| Vouchers      | `Broker\TransporterVoucherController` + `VoucherService`      | `/broker/vouchers/*`                                         |
| Invoices      | `Broker\BrokerInvoiceController`                              | `/broker/invoices/*`                                         |
| Linked bilty  | `Broker\LinkedBiltyGroupController`                           | Post-hire grouping (not at LR create)                        |
| Registers hub | `Broker\BrokerRegistersController`                            | Subset of legacy `ReportController`                          |
| Masters       | `PartyController`, `VehicleController`, `CatalogueController` | Owner/branch panels                                          |

---

## Workflow alignment summary

| Workflow                     | Classification             | Severity | Notes                                                                                            |
| ---------------------------- | -------------------------- | -------- | ------------------------------------------------------------------------------------------------ |
| Master data → LR create      | **Partial**                | P2       | Unified parties/vehicles; no legacy bulk CSV (PO-LGC-005); **Batch 2** remote Party search on LR |
| LR goods + freight           | **Partial**                | P1       | Domain complete; MT entry shipped; Wt./Unit KG; `OPERATIONAL_WEIGHT_UNIT_STANDARD.md`            |
| LR → hire creation           | **Equivalent**             | P2       | `createFromLorryReceipt` vs `FreightBookingController::store`                                    |
| Hire lifecycle edit          | **Partial**                | P1       | No full `FreightBookingController::update` parity — adjustments via `BrokerAdjustmentController` |
| Hire → voucher pay           | **Equivalent**             | P3       | Voucher create with hire context                                                                 |
| Voucher edit/delete          | **PO Decision Required**   | P1       | PO-LGC-003 — legacy allows edit/destroy                                                          |
| Customer invoice             | **Partial**                | P2       | No legacy `CustomInvoiceController`; broker invoice edit PO-LGC-002                              |
| Settlement PDF               | **Deferred**               | P2       | `settlementStatement` → Phase 11 / PO-LGC-006                                                    |
| Operational reports (22+)    | **Deferred**               | P1       | Phase 11; hub only in Nexus                                                                      |
| GL / Tally accounting        | **Deferred**               | P1       | ADR-010 Pending — no `accounting/*` in Nexus                                                     |
| In-app Razorpay subscription | **Intentional Difference** | —        | Owner subscription in unified app                                                                |

### Batch 2 progress (Nexus — in progress)

| Gap                                                      | Status         | Evidence                                                                                           |
| -------------------------------------------------------- | -------------- | -------------------------------------------------------------------------------------------------- |
| Scalable Party search                                    | **Done**       | `PartyOptionQuery` `q`/`selected_id`; `GET branch.parties.options`; remote `SearchablePartySelect` |
| Wire Enquiry / LR / Hire / Invoice / Linked Bilty        | **Done**       | Controllers pass `parties_options`; selectors use `searchUrl` + role                               |
| Enquiry / Bilty / Hire / Voucher / Invoice confirmations | **Partial**    | Create surfaces wired; Memo/Delivery/Linked payment remain                                         |
| Hire full edit                                           | **PO-LGC-007** | Adjustments + cancel only                                                                          |

### Batch 1 gaps (Nexus — closed)

| Gap                 | Legacy behaviour                          | Nexus current                                                                  | Evidence                                         |
| ------------------- | ----------------------------------------- | ------------------------------------------------------------------------------ | ------------------------------------------------ |
| Party pickers on LR | Searchable lists / large consignee master | **Done** — `SearchablePartySelect` on Billing Customer / Consignor / Consignee | `80a1c9e`                                        |
| Weight UX           | Single weight field on booking            | **Done** — MT actual/charged + KG Wt./Unit; freight preview corrected          | `03bdae1`, `OPERATIONAL_WEIGHT_UNIT_STANDARD.md` |

---

## Overstated “Equivalent” corrections (broker)

| ID       | Prior      | **Revised**  | Rationale                                                                    |
| -------- | ---------- | ------------ | ---------------------------------------------------------------------------- |
| BRK-LR02 | Equivalent | **Improved** | Batch 1 closed: searchable role-filtered parties + MT weight UX on create    |
| BRK-LR03 | Equivalent | **Partial**  | Searchable/MT on edit shipped; financial freeze vs legacy edit remains       |
| BRK-FH02 | Equivalent | **Partial**  | Create-from-LR OK; independent hire field edit vs legacy `update` incomplete |
| BRK-M09  | Equivalent | **Partial**  | `VehicleController` OK; driver register + bulk upload absent                 |

---

## Evidence index (quick)

| Topic               | Legacy                                  | Nexus                                                           |
| ------------------- | --------------------------------------- | --------------------------------------------------------------- |
| LR store            | `LorryReceiptController::store`         | `LorryReceiptController::store` → `LorryReceiptService::create` |
| Hire store          | `FreightBookingController::store`       | `LorryHireController::storeFromLorryReceipt`                    |
| Voucher bulk        | `VoucherPaymentController::bulkPayment` | `TransporterVoucherController` + `BulkVoucherService`           |
| Linked LR at create | `LorryReceiptController::createLinked`  | `LinkedBiltyGroupController` (post-hire)                        |

_No legacy files modified. Does not authorize Phase 7._
