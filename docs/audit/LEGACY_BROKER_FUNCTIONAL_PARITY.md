# Legacy Broker → Nexus Functional Parity Audit

**Audit date:** 24 November 2026  
**Auditor basis:** Read-only source inspection (routes, controllers, models, report surfaces). No legacy mutations.  
**Authority:** Governing PRD / Rules / Architecture / PO decisions override legacy behaviour.

| Reference               | Value                                                                           |
| ----------------------- | ------------------------------------------------------------------------------- |
| Legacy repository       | `taldartech/truckbill` (Broker) @ branch `stable`                               |
| Legacy verified SHA     | `6a90e955bd7a05497f60a6d0bd72891c880e6d14`                                      |
| Legacy local path       | `/Users/ttc/Sites/truckbill/_legacy_refs/truckbill_broker`                      |
| Nexus inspected HEAD    | `efe341d39ea47499cdb16506c0cfaf3a2848d4ab`                                      |
| Prior matrix reconciled | `docs/engineering/LEGACY_TO_NEXUS_COMPREHENSIVE_PARITY_MATRIX.md` (24 Nov 2026) |

## Classification legend

| Classification             | Meaning                                                                 |
| -------------------------- | ----------------------------------------------------------------------- |
| **Equivalent**             | Same business capability with comparable UX entry point                 |
| **Improved**               | Same intent; Nexus redesign is stricter or materially better            |
| **Partial**                | Core subset exists; material legacy behaviour not yet exposed           |
| **Missing Current Scope**  | In authorized Phase 6 (or applicable current phase) but not implemented |
| **Deferred Future Phase**  | Explicitly deferred (Phase 8/11, ADR-010, etc.)                         |
| **Intentional Difference** | Approved Nexus model; not a gap to close                                |
| **Deprecated**             | Legacy-only; must not be ported                                         |
| **PO Decision**            | Business policy unclear; needs Product Owner                            |
| **Not Verified**           | Nexus mapping inferred; line-level behaviour not re-tested in this pass |

**Severity:** P0 = blocks core Broker money movement; P1 = daily operations pain / compliance risk in current phase; P2 = workaround exists; P3 = low / deferred phase.

---

## Reconciliation with `LEGACY_TO_NEXUS_COMPREHENSIVE_PARITY_MATRIX.md`

| Matrix claim                                                                                                   | This audit finding                                                                                                                                                                          |
| -------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Phase 6 Broker batches A–J **DONE** for hire, vouchers, memo/POD, broker invoices, linked bilty, registers hub | **Confirmed** by Nexus `routes/web.php` (`/broker/*`, unified LR) and domain services under `app/Domains/Broker/*`.                                                                         |
| Operational registers **DONE** (ops subset); full legacy `ReportController` **P11**                            | **Confirmed.** Nexus has hub only (`BrokerRegistersController`); 22+ legacy report routes have no Nexus HTTP counterparts.                                                                  |
| GL / Tally **Defer P8** (ADR-010 Pending)                                                                      | **Confirmed.** No `accounting/*` routes in Nexus.                                                                                                                                           |
| Negative advance / book balance **DONE (REDESIGN)**                                                            | **Confirmed** — `transporter_book_balance_entries` + Adjust-in-Advance vouchers; legacy `AdvancePaymentController::store` pattern replaced.                                                 |
| RBAC HTTP matrix **PARTIAL** (Batch I)                                                                         | **Still accurate** — not re-certified in this pass.                                                                                                                                         |
| Playwright Broker journeys **not fully run** (`PHASE_6_PLAYWRIGHT_CERTIFICATION.md`)                           | **Still accurate** — E2E exists but certification doc lists gaps.                                                                                                                           |
| Early Batch 0 “Missing / not authorized” notes superseded                                                      | **Agree** for core workflow; **this audit adds** post-create edit/delete, payout export, custom invoice, and full report inventory as explicit **Partial / Missing / Deferred** rows below. |
| Matrix uses codes `DONE` / `P6` / `REDESIGN`                                                                   | **Not equivalent** to this audit’s nine-way classification — use this document for gap counting.                                                                                            |

---

## 1. Masters & reference data

| ID  | Capability                                     | Legacy evidence                                                              | Nexus equivalent                                                                                                    | Classification         | Sev | Phase  |
| --- | ---------------------------------------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ---------------------- | --- | ------ |
| M01 | Country master                                 | `routes/web.php` `master/countries` + `CountryController`                    | No country CRUD; stations/geo via Nexus masters catalogue                                                           | Intentional Difference | P3  | 3      |
| M02 | State master                                   | `master/states` + `StateController`                                          | Stations / governance catalogue — no legacy state table UI                                                          | Intentional Difference | P3  | 3      |
| M03 | City master                                    | `master/cities` + `CityController`                                           | `StationController` CRUD (`/stations`)                                                                              | Improved               | P3  | 3      |
| M04 | Goods types                                    | `master/goods-types` + `GoodsTypeController`                                 | `/goods-types` via `CatalogueController`                                                                            | Equivalent             | P3  | 3      |
| M05 | Truck types                                    | `master/truck-types` + `TruckTypeController`                                 | `/truck-types` via `CatalogueController`                                                                            | Equivalent             | P3  | 3      |
| M06 | Pump vendor master                             | `master/pumps` + `PumpController`                                            | `PartyRoleType::PumpVendor` on `PartyController`; voucher `pump_reference`                                          | Partial                | P2  | 6      |
| M07 | Transporter CRUD                               | `TransporterController` resource + TDS refresh POST                          | `PartyController` + transporter role; TDS via `previewTds` / tax masters                                            | Improved               | P3  | 3/6    |
| M08 | Truck owner CRUD                               | `TruckOwnerController` resource                                              | Market owner on `Vehicle` (`owner_party_id`); unified parties                                                       | Intentional Difference | P3  | 3      |
| M09 | Truck / vehicle CRUD                           | `TruckController`                                                            | `VehicleController` (`/vehicles`) with `VehicleOwnership::Market`                                                   | Equivalent             | P3  | 3      |
| M10 | Driver master CRUD                             | `DriverController` resource + bulk upload                                    | `/drivers` (`DriverMasterController` + unified `Party` Driver role + Quick Add); OwnFleet assignments isolated      | Equivalent             | P2  | 5/6    |
| M11 | Consignee master                               | `ConsigneeController`                                                        | Consignee / customer / consignor as party roles on LR                                                               | Intentional Difference | P3  | 3      |
| M12 | Branch management                              | `BranchController`                                                           | Owner panel `OwnerBranchesController`                                                                               | Improved               | P3  | 2      |
| M13 | Company PDF templates (LR / invoice / challan) | `CompanySettingsController`, `CompanyController::*TemplatePreview`           | Document service + print views; production template gate deferred                                                   | Partial                | P2  | 3 / 11 |
| M14 | Transporter bank beneficiary ID                | `transporter_bank_accounts` routes                                           | Party + `bank-cash` accounts on branch panel                                                                        | Partial                | P2  | 3      |
| M15 | Truck owner bank beneficiary                   | `truck_owner_bank_accounts` routes                                           | Party settlement fields (form hints in masters UI)                                                                  | Partial                | P2  | 3      |
| M16 | Platform transporter master registry           | `master/transporter-masters` + `TransporterMasterController`                 | **Missing** — no platform-wide transporter master admin                                                             | PO Decision            | P2  | PO / 2 |
| M17 | Masters bulk CSV upload                        | bulk routes on consignees, transporters, trucks, drivers, pumps, goods-types | **No** `bulk-upload` routes in Nexus — **PO-LGC-005**                                                               | PO Decision            | P2  | 3      |
| M18 | Standalone advance payment entry               | `AdvancePaymentController::store`                                            | Shared `BookBalanceDialog` (`advance`, `recovery`, `adjustment`, `opening_balance`) on Party Master/Show & Balances | Equivalent             | P3  | 6      |
| M19 | Moderator role (read-mostly ops)               | `role:moderator` on many routes                                              | Nexus RBAC permission codes (no legacy role name)                                                                   | Improved               | P3  | 2      |
| M20 | Legacy in-app subscription / Razorpay          | `SubscriptionController` prefix                                              | Nexus Owner subscription (`OwnerSubscriptionController`)                                                            | Deprecated             | —   | —      |

---

## 2. Bilty / Lorry Receipt (Broker context)

| ID   | Capability                             | Legacy evidence                                                                    | Nexus equivalent                                                                    | Classification         | Sev | Phase |
| ---- | -------------------------------------- | ---------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- | ---------------------- | --- | ----- |
| LR01 | LR listing + filters                   | `LorryReceiptController::index` + DataTables JSON                                  | `LorryReceiptController::index` Inertia list                                        | Improved               | P3  | 4/6   |
| LR02 | LR create                              | `LorryReceiptController::store`                                                    | `LorryReceiptController::store`                                                     | Equivalent             | P3  | 4     |
| LR03 | LR edit / update                       | `edit`, `update` routes                                                            | `edit`, `patch` routes                                                              | Equivalent             | P3  | 4     |
| LR04 | LR delete (soft)                       | `destroy` + hire dependency checks                                                 | `destroy` + `CompositeLrDependencyGuard`                                            | Equivalent             | P2  | 4/6   |
| LR05 | LR cancel                              | Implicit status flows on legacy                                                    | `POST .../cancel`                                                                   | Equivalent             | P3  | 4     |
| LR06 | LR print / copy PDF                    | Report `reports/lr-copy` + templates                                               | `GET .../print` + document service                                                  | Partial                | P2  | 4/11  |
| LR07 | Create **linked** multi-LR in one form | `lorry_receipts/create-linked`, `createLinked`, linked edit/update private methods | **Linked Bilty Group** after hire (`LinkedBiltyGroupController`) — not at LR create | Intentional Difference | P2  | 6     |
| LR08 | Bulk voucher payment from LR list      | `lorry_receipts/bulk-payment`                                                      | Bulk vouchers + linked bilty pay; no LR-list bulk action                            | Partial                | P2  | 6     |
| LR09 | Market truck → Broker workflow         | Truck ownership + FR linkage                                                       | `LrWorkflowContext::Broker` + `VehicleOwnership::Market`                            | Equivalent             | P2  | 4/6   |
| LR10 | E-way bill fields                      | `EwayBill` model on LR                                                             | `ewayBills` on unified LR (`LorryReceiptController` loads)                          | Equivalent             | P3  | 4     |
| LR11 | LR / GR number uniqueness AJAX         | Legacy validation endpoints on controller                                          | Server validation in `LorryReceiptService` / form requests                          | Not Verified           | P3  | 4     |
| LR12 | Additional charges on LR               | Legacy LR columns                                                                  | `additionalCharges` relationship on Nexus LR                                        | Equivalent             | P3  | 4     |

---

## 3. Freight booking / Lorry hire

Legacy model: `FreightBooking` + `FreightBookingController`. Nexus model: `LorryHire` + `LorryHireController` + domain services.

| ID   | Capability                                          | Legacy evidence                                             | Nexus equivalent                                                                                                                                      | Classification         | Sev | Phase |
| ---- | --------------------------------------------------- | ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- | --- | ----- |
| FH01 | Hire register / index                               | `FreightBookingController::index`, `getFreightBookingsData` | `LorryHireController::index`                                                                                                                          | Equivalent             | P3  | 6     |
| FH02 | Create hire from LR                                 | `FreightBookingController::create/store`                    | `createFromLorryReceipt` / `storeFromLorryReceipt` with independent transporter rate, loading weight, TDS/margin preview (UI remediation 24 Nov 2026) | Equivalent             | P2  | 6     |
| FH03 | Hire detail                                         | `show`                                                      | `LorryHireController::show`                                                                                                                           | Equivalent             | P3  | 6     |
| FH04 | Update hire (rate, weight, transporter, fixed flag) | `update`, `edit`                                            | `LorryHireEditController` + `LorryHireRevisionService` (guarded edit, Custom Amount, revisions; payee via Bilty)                                      | Equivalent             | P1  | 6     |
| FH05 | Cancel hire + settle outstanding                    | cancel/destroy flows + settlement                           | `LorryHireController::cancel` + `LorryHireCancellationService`                                                                                        | Equivalent             | P2  | 6     |
| FH06 | Pre-save confirm step                               | `freight_bookings/confirm` POST                             | Single-step create (no confirm endpoint)                                                                                                              | Intentional Difference | P3  | 6     |
| FH07 | Settlement statement PDF                            | `freight_bookings/{id}/settlement`                          | **Missing** dedicated settlement PDF — **PO-LGC-006** / Phase 11                                                                                      | Deferred Future Phase  | P2  | 11    |
| FH08 | Combined payment (multi hire one action)            | `storeCombinedPayment`                                      | `VoucherPaymentService` + linked bilty group pay                                                                                                      | Partial                | P2  | 6     |
| FH09 | Payment history modal (hire list)                   | `freight_bookings/payment_history`                          | Voucher list filtered by hire; no legacy AJAX history                                                                                                 | Partial                | P3  | 6     |
| FH10 | Bulk memo ID from hire screen                       | `freight_bookings/bulk_memo_id`                             | `BrokerDeliveryController::assignMemo` (delivery register)                                                                                            | Partial                | P2  | 6     |
| FH11 | Voucher payment from hire screen                    | `freight_bookings/voucher_payment`                          | Navigate to voucher create with hire context                                                                                                          | Equivalent             | P3  | 6     |
| FH12 | Hire delete                                         | `destroy` on `FreightBooking`                               | Cancel + soft-delete patterns; no hard destroy route                                                                                                  | Improved               | P3  | 6     |
| FH13 | Per-goods-line freight amounts                      | `FreightBookingCustomAmount`                                | Per `lr_goods_rows` hire lines (domain)                                                                                                               | Equivalent             | P2  | 6     |
| FH14 | TDS snapshot on hire                                | `TransporterTdsCalculator` + FR fields                      | `TransporterTdsCalculationService` + hire snapshots                                                                                                   | Improved               | P2  | 6     |
| FH15 | Document numbering                                  | `allocateNextVoucherNumber` MAX+1                           | `DocumentNumberAllocator`                                                                                                                             | Improved               | P2  | 6     |

---

## 4. Vouchers / payments (transporter)

Legacy: `VoucherPayment` + `VoucherPaymentController`. Nexus: `TransporterVoucher` + `TransporterVoucherController`.

| ID   | Capability                                                      | Legacy evidence                                                 | Nexus equivalent                                                                                   | Classification        | Sev | Phase |
| ---- | --------------------------------------------------------------- | --------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- | --------------------- | --- | ----- |
| VP01 | Voucher register                                                | `voucher_payments.index`                                        | `/broker/vouchers` index                                                                           | Equivalent            | P3  | 6     |
| VP02 | Voucher create                                                  | `create`, `store`                                               | `create`, `store`                                                                                  | Equivalent            | P2  | 6     |
| VP03 | Voucher edit                                                    | `edit`, `update`                                                | Status transition only — **PO-LGC-003**                                                            | PO Decision           | P1  | 6     |
| VP04 | Voucher delete                                                  | `destroy`                                                       | Soft-void policy gated — **PO-LGC-003**                                                            | PO Decision           | P1  | 6     |
| VP05 | Status workflow Bank/Pump→Pending; Cash/Adjust→In Progress→Paid | `updateStatus`, `bulkUpdateStatus`                              | `updateStatus`, `BrokerDailyPaymentsController::bulkStatus`                                        | Equivalent            | P2  | 6     |
| VP06 | Bulk voucher (same payee, multiple amounts)                     | `bulkPayment`, `FreightBookingController::storeCombinedPayment` | `bulkPreview`, `bulkStore`                                                                         | Equivalent            | P2  | 6     |
| VP07 | Daily payments (today filter)                                   | `daily-payments` route                                          | `/broker/daily-payments`                                                                           | Equivalent            | P2  | 6     |
| VP08 | Default status by payment mode                                  | Controller store logic                                          | `TransporterVoucherService` mode→status map                                                        | Equivalent            | P3  | 6     |
| VP09 | Export bank payout (NEFT/RTGS) file                             | `voucher_payments/export-payout`                                | Deferred export pack — Phase 11                                                                    | Deferred Future Phase | P2  | 11    |
| VP10 | Transporter advance / book balance                              | `TransporterAdvanceLedgerService`, negative adjustment helpers  | `TransporterBookBalanceController` + `TransporterBookBalanceService` + statement + Accounting hook | Improved              | P2  | 6     |
| VP11 | Payment modes Bank/Cash/Pump/Adjust                             | `PaymentMode` model                                             | `VoucherPaymentMode` enum incl. `PaidToPump`, `AdjustInAdvance`                                    | Equivalent            | P2  | 6     |
| VP12 | Voucher show by voucher number                                  | `voucher_payments/{voucherNumber}`                              | UUID show route                                                                                    | Improved              | P3  | 6     |

---

## 5. Memo / POD / delivery

| ID   | Capability                                | Legacy evidence                                | Nexus equivalent                                 | Classification | Sev | Phase |
| ---- | ----------------------------------------- | ---------------------------------------------- | ------------------------------------------------ | -------------- | --- | ----- |
| MP01 | Delivery queue (undelivered broker LRs)   | FR/LR delivery fields + reports `pending-memo` | `/broker/deliveries` index                       | Equivalent     | P2  | 6     |
| MP02 | Assign one memo → many same-customer LRs  | `bulk_memo_id`, invoice memo helpers           | `POST /broker/deliveries/memo`                   | Equivalent     | P2  | 6     |
| MP03 | POD upload                                | Legacy file fields on LR/FR                    | `POST .../pod` + document attachments            | Improved       | P2  | 6     |
| MP04 | Mark delivery complete                    | LR delivery status updates                     | `POST .../complete`                              | Equivalent     | P2  | 6     |
| MP05 | Detention / deviation / shortage / damage | FR adjustment columns                          | `broker_adjustments` + `BrokerAdjustmentService` | Equivalent     | P2  | 6     |
| MP06 | Unloading weight history                  | Single `unloading_weight` overwrite risk       | Multiple adjustment rows + recalc                | Improved       | P2  | 6     |

---

## 6. Customer invoices (Broker)

Legacy: `InvoiceController`, `CustomInvoiceController`, `InvoicePayment`. Nexus: `BrokerInvoiceController`, unified read facade.

| ID   | Capability                                        | Legacy evidence                                   | Nexus equivalent                                                 | Classification        | Sev | Phase  |
| ---- | ------------------------------------------------- | ------------------------------------------------- | ---------------------------------------------------------------- | --------------------- | --- | ------ |
| IN01 | Create broker invoice from delivered LRs / memos  | `InvoiceController::store`, memo AJAX routes      | `BrokerInvoiceController::create/preview/store`                  | Equivalent            | P2  | 6      |
| IN02 | Invoice payments + customer TDS                   | `invoices/{id}/payments`                          | `broker/invoices/{id}/payments`                                  | Equivalent            | P2  | 6      |
| IN03 | Edit / update invoice                             | `invoices/{id}/edit`, `update`                    | Cancel + recreate only — **PO-LGC-002**                          | PO Decision           | P1  | 6      |
| IN04 | Cancel / delete invoice                           | `destroy` on invoice                              | `POST .../cancel` on broker invoice                              | Partial               | P2  | 6      |
| IN05 | Print invoice                                     | Blade/PDF templates                               | `print` Inertia/PDF path                                         | Partial               | P2  | 3 / 11 |
| IN06 | Custom invoice (manual lines, LR lookup)          | `CustomInvoiceController` + `CustomInvoiceDetail` | **Missing**                                                      | PO Decision           | P1  | 6      |
| IN07 | Select LRs / memos by customer (AJAX)             | `invoices/lrs`, `invoices/memos/*`                | Server preview in `BrokerInvoiceController::preview`             | Equivalent            | P3  | 6      |
| IN08 | Unified customer invoice list (Broker + OwnFleet) | N/A (legacy app isolated)                         | `CustomerInvoiceController` + `UnifiedCustomerInvoiceReadFacade` | Improved              | P3  | 6      |
| IN09 | Invoice with/without POD sections                 | `InvoiceController` sections                      | Broker invoice engine rules in `BrokerInvoiceService`            | Not Verified          | P2  | 6      |
| IN10 | Sample / non-production tax invoice               | Legacy PDF tests                                  | Sample tax only; production tax gate open                        | Deferred Future Phase | P2  | 3      |

---

## 7. Linked bilty / group payment

| ID   | Capability                         | Legacy evidence                                          | Nexus equivalent                                  | Classification | Sev | Phase |
| ---- | ---------------------------------- | -------------------------------------------------------- | ------------------------------------------------- | -------------- | --- | ----- |
| LB01 | Link ≥2 LRs (shared vehicle/payee) | `linked_freight_booking_id`, `createLinked` LR flow      | `LinkedBiltyGroupController::store`               | Improved       | P2  | 6     |
| LB02 | Proportional voucher allocation    | `VoucherLinked` model                                    | `LinkedBiltyGroupService` proportional + residual | Equivalent     | P2  | 6     |
| LB03 | Pay linked group in one action     | `VoucherPaymentController::bulkPayment`, FR combined pay | `payPreview`, `payStore` on group                 | Equivalent     | P2  | 6     |
| LB04 | Edit linked set from LR screen     | `editLinkedLorryReceipts`                                | Group created post-hoc; LR show links hire/group  | Partial        | P2  | 6     |
| LB05 | JSON fetch LRs by linked id        | `lorryreceipts/by-linked/{linkedId}`                     | Group show page member list                       | Equivalent     | P3  | 6     |

---

## 8. Reports (legacy `reports/*` inventory)

Each row is a distinct legacy report surface (UI ± Excel/PDF export). Nexus operational substitute: filtered **list registers** + `/broker/registers` hub — not 1:1 report reproduction.

| ID  | Report                                  | Legacy route (`ReportController` / nested)               | Nexus equivalent                                   | Classification        | Sev | Phase |
| --- | --------------------------------------- | -------------------------------------------------------- | -------------------------------------------------- | --------------------- | --- | ----- |
| R01 | Consignor–consignee                     | `reports/consignor-consignee` (+ export)                 | LR list filters by parties                         | Partial               | P2  | 11    |
| R02 | TDS (customer/transporter)              | `reports/tds` (+ export)                                 | Tax masters + hire snapshots; **no report UI**     | Deferred Future Phase | P2  | 11    |
| R03 | Dispatch register                       | `reports/dispatch` (+ export)                            | LR list by status/date                             | Partial               | P2  | 11    |
| R04 | Party ledger                            | `PartyLedgerReportController`                            | **Missing**                                        | Deferred Future Phase | P1  | 8/11  |
| R05 | LR copy batch / PDF                     | `reports/lr-copy` (+ export)                             | Per-LR `print` only                                | Partial               | P2  | 11    |
| R06 | Invoice register                        | `reports/invoice` (+ export)                             | `/broker/invoices` list                            | Partial               | P2  | 11    |
| R07 | Shortage & damage                       | `reports/shortage-damage` (+ export)                     | Adjustments on hire; no consolidated report        | Partial               | P2  | 11    |
| R08 | E-way bill register                     | `reports/eway-bill` (+ export)                           | LR e-way data; no report                           | Partial               | P2  | 11    |
| R09 | Pump payment register                   | `reports/pump` (+ export)                                | Voucher filter `PaidToPump`; no pump report        | Partial               | P2  | 11    |
| R10 | Party bill                              | `reports/party-bill` (+ export)                          | **Missing**                                        | Deferred Future Phase | P2  | 11    |
| R11 | Memo copy / print batch                 | `reports/memo-copy` (+ export)                           | Delivery register; no memo PDF batch               | Partial               | P2  | 11    |
| R12 | Pending memo                            | `reports/pending-memo` (+ export)                        | Registers hub `memo_pod_queue` + deliveries filter | Partial               | P2  | 6/11  |
| R13 | Bank payments register                  | `reports/bank` (+ export)                                | Voucher list filter; no export workbook            | Partial               | P2  | 11    |
| R14 | Cash payments register                  | `reports/cash` (+ export)                                | Daily payments + voucher filters                   | Partial               | P2  | 11    |
| R15 | Transporter statement                   | `reports/transporter-report` (+ export)                  | Transporter balances + hire list                   | Partial               | P2  | 11    |
| R16 | Profitability                           | `reports/profitability` (+ export)                       | **Missing** (no broker P&L ops report)             | Deferred Future Phase | P2  | 11    |
| R17 | Pending invoice (aggregate)             | `reports/pending-invoice` (+ export)                     | Registers `pending_customer_invoices` count        | Partial               | P2  | 6/11  |
| R18 | Party-wise pending invoice              | `PartyPendingInvoiceReportController`                    | Broker invoice list filter by customer             | Partial               | P2  | 11    |
| R19 | Transporter master dump                 | `reports/transporter-master` (+ export)                  | Party master export **missing**                    | Deferred Future Phase | P2  | 11    |
| R20 | Voucher receipt export + print tracking | `voucher-receipt-export`, `voucher-receipt-mark-printed` | **Missing**                                        | Deferred Future Phase | P2  | 11    |
| R21 | Customer memos JSON (report helper)     | `reports/customer-memos/{customerId}`                    | Invoice preview API                                | Partial               | P3  | 6     |
| R22 | Broker operational registers hub        | Legacy dashboard/report menus                            | `GET /broker/registers`                            | Improved              | P3  | 6     |

---

## 9. Accounting / inventory (legacy `accounting/*`)

Nexus: **no** chart-of-accounts UI, auto-posting, or Tally export (ADR-010 Pending, Phase 8).

| ID  | Capability                           | Legacy evidence                              | Nexus equivalent         | Classification        | Sev | Phase |
| --- | ------------------------------------ | -------------------------------------------- | ------------------------ | --------------------- | --- | ----- |
| A01 | Day book                             | `accounting/day-book`                        | **Missing**              | Deferred Future Phase | P2  | 8     |
| A02 | Ledger statement                     | `accounting/ledger-statement`                | **Missing**              | Deferred Future Phase | P2  | 8     |
| A03 | Trial balance                        | `accounting/trial-balance`                   | **Missing**              | Deferred Future Phase | P2  | 8     |
| A04 | Profit & loss                        | `accounting/profit-and-loss`                 | **Missing**              | Deferred Future Phase | P2  | 8     |
| A05 | Balance sheet                        | `accounting/balance-sheet`                   | **Missing**              | Deferred Future Phase | P2  | 8     |
| A06 | Export Tally masters XML             | `export-tally-masters`                       | **Missing**              | Deferred Future Phase | P2  | 8     |
| A07 | Export Tally vouchers XML            | `export-tally-vouchers`                      | **Missing**              | Deferred Future Phase | P2  | 8     |
| A08 | GSTR-1 report                        | `accounting/gstr1`                           | **Missing**              | Deferred Future Phase | P2  | 8     |
| A09 | Debtors aging                        | `accounting/debtors-aging`                   | **Missing**              | Deferred Future Phase | P2  | 8     |
| A10 | Bank reconciliation                  | `accounting/bank-reconciliation`             | **Missing**              | Deferred Future Phase | P2  | 8     |
| A11 | Manual accounting vouchers + reverse | `AccountingVoucherController`                | **Missing**              | Deferred Future Phase | P2  | 8     |
| A12 | Ledger master (create/list)          | `LedgerController`                           | **Missing**              | Deferred Future Phase | P3  | 8     |
| A13 | Auto posting from FR/voucher/invoice | `AccountingPostingService` (tests in legacy) | **Missing** (ADR-010)    | Deferred Future Phase | P1  | 8     |
| A14 | Spare parts / store inventory        | Not in Broker legacy scope                   | Phase 10 OwnFleet assets | Deferred Future Phase | P3  | 10    |

---

## 10. Platform / non-Broker (context only)

| ID  | Capability                              | Legacy evidence                  | Nexus equivalent                                  | Classification         | Sev | Phase |
| --- | --------------------------------------- | -------------------------------- | ------------------------------------------------- | ---------------------- | --- | ----- |
| P01 | Separate Broker app + subscription gate | `subscription.active` middleware | Unified workspace + `SubscriptionEntitlementGate` | Deprecated             | —   | —     |
| P02 | Multi-app module chooser                | Separate repos                   | Single Nexus workspace                            | Intentional Difference | —   | —     |
| P03 | Master admin cross-company dashboard    | `master/summary-dashboard`       | Platform admin routes (partial)                   | Partial                | P3  | 2     |
| P04 | Email template admin                    | `email_templates` resource       | Communications templates Phase 9                  | Deferred Future Phase  | P3  | 9     |
| P05 | Company audit log browser               | `audit-logs` (super_admin)       | Owner/branch audit surfaces                       | Partial                | P3  | 2     |
| P06 | Float / PHP round monetary math         | Legacy helpers                   | bcmath string decimals                            | Improved               | P2  | 6     |

---

## Classification summary (countable rows)

Counts include sections **1–9** (operational Broker scope). Section 10 excluded from totals (deprecated/platform).

| Classification         |   Count |
| ---------------------- | ------: |
| Equivalent             |      33 |
| Improved               |      16 |
| Partial                |      29 |
| Missing Current Scope  |       0 |
| Deferred Future Phase  |      21 |
| Intentional Difference |       6 |
| Deprecated             |       1 |
| PO Decision            |       7 |
| Not Verified           |       2 |
| **Total counted**      | **116** |

_Post-challenge (24 Nov): former Missing rows → PO-LGC / Phase 11 Deferred. See gap register._

---

## Capability index (CSV-style)

`id,domain,capability,classification,severity,phase`

```text
M01,masters,Country master,Intentional Difference,P3,3
M02,masters,State master,Intentional Difference,P3,3
M03,masters,City master,Improved,P3,3
M04,masters,Goods types,Equivalent,P3,3
M05,masters,Truck types,Equivalent,P3,3
M06,masters,Pump vendor master,Partial,P2,6
M07,masters,Transporter CRUD,Improved,P3,3
M08,masters,Truck owner CRUD,Intentional Difference,P3,3
M09,masters,Vehicle CRUD,Equivalent,P3,3
M10,masters,Driver master CRUD,Equivalent,P2,6
M11,masters,Consignee master,Intentional Difference,P3,3
M12,masters,Branch management,Improved,P3,2
M13,masters,Company PDF templates,Partial,P2,11
M14,masters,Transporter bank beneficiary,Partial,P2,3
M15,masters,Truck owner bank beneficiary,Partial,P2,3
M16,masters,Platform transporter master registry,Missing Current Scope,P2,PO
M17,masters,Masters bulk CSV upload,Missing Current Scope,P2,3
M18,masters,Standalone advance entry,Improved,P3,6
M19,masters,Moderator role mapping,Improved,P3,2
M20,masters,Legacy in-app subscription,Deprecated,-,-
LR01,lr,LR listing,Improved,P3,4
LR02,lr,LR create,Equivalent,P3,4
LR03,lr,LR edit,Equivalent,P3,4
LR04,lr,LR delete,Equivalent,P2,4
LR05,lr,LR cancel,Equivalent,P3,4
LR06,lr,LR print copy,Partial,P2,11
LR07,lr,Linked multi-LR at create,Intentional Difference,P2,6
LR08,lr,LR list bulk voucher,Partial,P2,6
LR09,lr,Broker market classification,Equivalent,P2,4
LR10,lr,E-way bill on LR,Equivalent,P3,4
LR11,lr,Number uniqueness AJAX,Not Verified,P3,4
LR12,lr,Additional charges,Equivalent,P3,4
FH01,hire,Hire register,Equivalent,P3,6
FH02,hire,Create hire from LR,Equivalent,P2,6
FH03,hire,Hire detail,Equivalent,P3,6
FH04,hire,Update hire freight fields,Equivalent,P1,6
FH05,hire,Cancel hire + settle,Equivalent,P2,6
FH06,hire,Pre-save confirm step,Intentional Difference,P3,6
FH07,hire,Settlement statement PDF,Missing Current Scope,P1,6
FH08,hire,Combined multi-hire payment,Partial,P2,6
FH09,hire,Payment history on hire list,Partial,P3,6
FH10,hire,Bulk memo from hire screen,Partial,P2,6
FH11,hire,Voucher from hire screen,Equivalent,P3,6
FH12,hire,Hire delete pattern,Improved,P3,6
FH13,hire,Per-goods freight lines,Equivalent,P2,6
FH14,hire,TDS snapshot,Improved,P2,6
FH15,hire,Document numbering,Improved,P2,6
VP01,voucher,Voucher register,Equivalent,P3,6
VP02,voucher,Voucher create,Equivalent,P2,6
VP03,voucher,Voucher edit,Missing Current Scope,P1,6
VP04,voucher,Voucher delete,Missing Current Scope,P1,6
VP05,voucher,Status workflow + bulk status,Equivalent,P2,6
VP06,voucher,Bulk voucher create,Equivalent,P2,6
VP07,voucher,Daily payments,Equivalent,P2,6
VP08,voucher,Default status by mode,Equivalent,P3,6
VP09,voucher,Bank payout file export,Missing Current Scope,P1,6
VP10,voucher,Transporter book balance,Improved,P2,6
VP11,voucher,Payment modes,Equivalent,P2,6
VP12,voucher,Voucher show,Improved,P3,6
MP01,memo,Delivery queue,Equivalent,P2,6
MP02,memo,Bulk memo assign,Equivalent,P2,6
MP03,memo,POD upload,Improved,P2,6
MP04,memo,Complete delivery,Equivalent,P2,6
MP05,memo,Detention shortage adjustments,Equivalent,P2,6
MP06,memo,Unloading weight history,Improved,P2,6
IN01,invoice,Create broker invoice,Equivalent,P2,6
IN02,invoice,Payments + customer TDS,Equivalent,P2,6
IN03,invoice,Edit invoice,Missing Current Scope,P1,6
IN04,invoice,Cancel invoice,Partial,P2,6
IN05,invoice,Print invoice,Partial,P2,11
IN06,invoice,Custom manual invoice,PO Decision,P1,6
IN07,invoice,LR/memo selection preview,Equivalent,P3,6
IN08,invoice,Unified customer invoice list,Improved,P3,6
IN09,invoice,POD section rules,Not Verified,P2,6
IN10,invoice,Production tax invoice,Deferred Future Phase,P2,3
LB01,linked,Create linked group,Improved,P2,6
LB02,linked,Proportional pay,Equivalent,P2,6
LB03,linked,Group pay action,Equivalent,P2,6
LB04,linked,Edit linked at LR screen,Partial,P2,6
LB05,linked,Fetch by linked id,Equivalent,P3,6
R01,report,Consignor-consignee report,Partial,P2,11
R02,report,TDS report,Deferred Future Phase,P2,11
R03,report,Dispatch report,Partial,P2,11
R04,report,Party ledger report,Deferred Future Phase,P1,8
R05,report,LR copy batch report,Partial,P2,11
R06,report,Invoice register report,Partial,P2,11
R07,report,Shortage damage report,Partial,P2,11
R08,report,E-way bill report,Partial,P2,11
R09,report,Pump report,Partial,P2,11
R10,report,Party bill report,Deferred Future Phase,P2,11
R11,report,Memo copy report,Partial,P2,11
R12,report,Pending memo report,Partial,P2,6
R13,report,Bank payments report,Partial,P2,11
R14,report,Cash payments report,Partial,P2,11
R15,report,Transporter report,Partial,P2,11
R16,report,Profitability report,Deferred Future Phase,P2,11
R17,report,Pending invoice report,Partial,P2,6
R18,report,Party pending invoice report,Partial,P2,11
R19,report,Transporter master export report,Missing Current Scope,P2,11
R20,report,Voucher receipt export,Missing Current Scope,P2,11
R21,report,Customer memos JSON,Partial,P3,6
R22,report,Broker registers hub,Improved,P3,6
A01,accounting,Day book,Deferred Future Phase,P2,8
A02,accounting,Ledger statement,Deferred Future Phase,P2,8
A03,accounting,Trial balance,Deferred Future Phase,P2,8
A04,accounting,Profit and loss,Deferred Future Phase,P2,8
A05,accounting,Balance sheet,Deferred Future Phase,P2,8
A06,accounting,Tally masters export,Deferred Future Phase,P2,8
A07,accounting,Tally vouchers export,Deferred Future Phase,P2,8
A08,accounting,GSTR-1,Deferred Future Phase,P2,8
A09,accounting,Debtors aging,Deferred Future Phase,P2,8
A10,accounting,Bank reconciliation,Deferred Future Phase,P2,8
A11,accounting,Accounting vouchers,Deferred Future Phase,P2,8
A12,accounting,Ledger master,Deferred Future Phase,P3,8
A13,accounting,Auto GL posting,Deferred Future Phase,P1,8
A14,accounting,Store inventory,N/A broker,Deferred Future Phase,P3,10
```

---

## Top current-scope gaps (P0 / P1)

**P0 (core Broker chain):** No P0 gaps identified for LR → hire → voucher → delivery → invoice → linked pay on inspected routes and domain services; Phase 6 certification and Pest suites claim this path. Residual risk: **Not Verified** rows (IN09, LR11) and incomplete Playwright certification.

**P1 — Missing Current Scope / Partial (authorized Phase 6 operations):**

| Priority | ID        | Gap                             | Notes                                                                           |
| -------- | --------- | ------------------------------- | ------------------------------------------------------------------------------- |
| P1       | VP03–VP04 | Voucher amend / void UI         | Legacy admin could edit/delete vouchers; Nexus only `transitionStatus`.         |
| P1       | VP09      | Bank payout export file         | Legacy `exportPayoutFile`; treasury handoff to bank not replicated.             |
| P1       | IN03      | Broker invoice edit after issue | Legacy `edit`/`update`; Nexus cancel-only correction path.                      |
| P1       | FH07      | Hire settlement statement PDF   | Legacy `settlementStatement` route; operators may rely on printable settlement. |
| P1       | IN06      | Custom invoice workflow         | **PO Decision** — policy for manual lines vs strict LR-linked invoices.         |
| P1       | M17       | Masters bulk upload             | High-volume onboarding parity for transporters/trucks/consignees.               |

**P1 — deferred but high business impact (not Phase 6 scope):**

| ID  | Gap                                    | Phase       |
| --- | -------------------------------------- | ----------- |
| A13 | GL auto-posting from vouchers/invoices | 8 (ADR-010) |
| R04 | Party ledger financial report          | 8/11        |

---

## Method notes

- Legacy routes enumerated from `truckbill_broker/routes/web.php` @ `6a90e955`.
- Nexus routes from `nexus/routes/web.php` @ `efe341d39ea47499cdb16506c0cfaf3a2848d4ab`.
- Report inventory includes every distinct `reports/*` GET route plus accounting report controllers; export routes are paired with parent report rows.
- This document does **not** replace PO acceptance records or Phase 6 certification; it is an evidence-backed parity inventory for planning Phase 6 residual work and Phase 11 reporting.
