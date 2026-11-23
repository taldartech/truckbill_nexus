# Legacy → Nexus Comprehensive Parity Matrix

**Date:** 24 November 2026  
**Purpose:** Source-to-source comparison before / during Phase 6 Broker implementation.  
**Authority:** Nexus PRD / Rules / Architecture / PO decisions override legacy behaviour when they conflict.  
**Legacy refs (read-only clones):**

| Repository                      | Branch        | Verified SHA                               |
| ------------------------------- | ------------- | ------------------------------------------ |
| `taldartech/truckbill` (Broker) | `stable`      | `6a90e955bd7a05497f60a6d0bd72891c880e6d14` |
| `taldartech/truckbill_partload` | `account`     | `fb9b955e64f6370c662a3a157081a96596a33690` |
| `taldartech/truckbill_ownfleet` | `development` | `9423293edd8b4e313198728f687f657d4a64c27f` |
| `taldartech/truckbill_admin`    | `main`        | `b951db3355cc7bc7b1af1898c61d358bdec99ab0` |

**Nexus baseline at Batch 0 start:** `0fed5290320a2c779fbed75991336903102c45b0`  
**GA #87 fix commit:** `ae48109f9b4e9afaf45c01b7c3c445c9ee14e295`

Local clone path: `/Users/ttc/Sites/truckbill/_legacy_refs/` (never commit into these trees).

---

## Classification legend

| Code         | Meaning                                            |
| ------------ | -------------------------------------------------- |
| `DONE`       | Already implemented in Nexus                       |
| `P1-5`       | Legitimate Phase 1–5 gap — close now if still open |
| `P6`         | Phase 6 Broker — implement now                     |
| `P7`         | Phase 7 Partload — defer (handoff only)            |
| `P8`         | Phase 8 Accounting / GL — defer (ADR-010 Pending)  |
| `P9`         | Phase 9 Communications — defer                     |
| `P10`        | Phase 10 Assets — defer                            |
| `P11`        | Phase 11 Reports / legacy migration — defer        |
| `REDESIGN`   | Intentionally redesigned in Nexus                  |
| `DEPRECATED` | Deprecated by approved Nexus requirement           |
| `PO`         | Requires separate Product Owner decision           |

Evidence column uses governing IDs where known (`TB-BRK-*`, Rules §, PRD §).

---

## A. Broker / Market-Hired (legacy `truckbill` → Phase 6)

| Feature                         | Legacy source                                         | Legacy field / formula                                                                         | Nexus equivalent                                               | Governing           | Status                        | Phase             | Impact    | Dependency                   | PO                        | Action                                | Test                   | Evidence                            |
| ------------------------------- | ----------------------------------------------------- | ---------------------------------------------------------------------------------------------- | -------------------------------------------------------------- | ------------------- | ----------------------------- | ----------------- | --------- | ---------------------------- | ------------------------- | ------------------------------------- | ---------------------- | ----------------------------------- |
| Market vehicle → Broker LR      | `LorryReceiptController`; truck ownership             | Market truck → Broker bilty                                                                    | `LrVehicleClassificationService` + `LrWorkflowContext::Broker` | PRD §10/§13         | `DONE`                        | —                 | High      | Vehicle Master               | Approved                  | Preserve                              | LR classification Pest | Phase 5 handoff                     |
| Module chooser                  | Legacy multi-app                                      | Separate apps                                                                                  | Unified workspace; no module picker                            | Architecture        | `REDESIGN`                    | —                 | UX        | —                            | Approved                  | Do not recreate                       | UI smoke               | Design                              |
| Freight Booking / Lorry Hire    | `FreightBooking` model; `FreightBookingController`    | `tr_fr_rate`, `is_fixed_amount`, `tr_amount`, `tds_amount`, `balance_amount`                   | `lorry_hires` + goods lines                                    | PRD §13; Rules §11  | Missing                       | `P6`              | Critical  | Broker LR, Party Transporter | Authorized 24 Nov 2026    | Implement domain                      | LorryHire Pest         | Batch A–B                           |
| Goods-wise transporter freight  | Legacy single FR row + custom amounts                 | `FreightBookingCustomAmount`                                                                   | Per-`lr_goods_rows` hire lines                                 | PRD §13             | Missing                       | `P6`              | Critical  | LR goods                     | Authorized                | Implement                             | Multi-goods Pest       | —                                   |
| Create FR freight               | `FreightBookingCalculationService::calculateForStore` | Fixed → `tr_amount=input`; else `tr_fr_rate × weight`; `tds = roundUp(tr×%)`; `balance=tr−tds` | Server `LorryHireCalculationService` (bcmath)                  | Rules §11           | Missing                       | `P6`              | Critical  | TDS engine                   | Authorized                | Implement decimal-safe                | Fixed/variable Pest    | Legacy calc doc                     |
| Update FR / unloading           | `calculateForUpdate`                                  | Variable: `tr = rate × min(load,unload)`; TDS on **original load** when changeable             | Same; TDS basis immutable snapshot                             | PRD §13             | Missing                       | `P6`              | Critical  | Adjustments                  | Authorized                | Implement                             | Unloading TDS Pest     | —                                   |
| Transporter TDS                 | `TransporterTdsCalculator`; FR `%`                    | Owner ≤10 GV = 0%; Agency ignore decl; invalid PAN 20%; P/H 1%; C/F/A/B/J/L/T 2%               | `TransporterTdsCalculationService` + rule evaluator            | Rules §6/§11        | `DONE` (engine); wire to hire | `P6`              | Critical  | Party role attrs             | Approved                  | Snapshot on hire create               | TDS Pest suite         | `TRANSPORTER_TDS_CALCULATION_SPEC`  |
| April 1 declaration reset       | Legacy cron                                           | Reset declaration                                                                              | `TransporterDeclarationAnnualResetService`                     | Rules §6            | `DONE`                        | —                 | Medium    | Scheduler                    | Approved                  | Reuse; no duplicate job               | Reset Pest             | Phase 3/tax                         |
| Voucher payments                | `VoucherPayment` / `VoucherPaymentController`         | Modes Bank/Cash/Pump/Adjust; status Pending→In Progress→Paid                                   | `transporter_vouchers`                                         | Rules §11           | Missing                       | `P6`              | Critical  | Lorry Hire                   | Authorized                | Implement lifecycle                   | Voucher Pest           | —                                   |
| Default voucher status          | Controller store                                      | Bank/Pump→Pending; Cash/Adjust→In Progress                                                     | Same                                                           | Rules §11           | Missing                       | `P6`              | High      | —                            | Authorized                | Mirror approved defaults              | Mode Pest              | —                                   |
| Daily payments                  | Reports / daily UI                                    | Filter today company-local                                                                     | Broker daily payments screen                                   | Rules §11           | Missing                       | `P6`              | High      | Vouchers                     | Authorized                | Implement                             | Filter Pest            | —                                   |
| Bulk voucher                    | Combined payment                                      | Same transporter+vehicle; separate amounts                                                     | Bulk create N vouchers                                         | Rules §11           | Missing                       | `P6`              | High      | Locking                      | Authorized                | Atomic multi-write                    | Bulk Pest              | —                                   |
| Negative advance adjustment     | `applyNegativeAdjustment*`                            | When balance&lt;0 push to transporter advance ledger                                           | Controlled `transporter_book_balance_entries`                  | Rules §11           | Missing                       | `P6`              | High      | Book Balance                 | Authorized                | Redesign ledger; no float copy        | Balance Pest           | Legacy uses float — **do not copy** |
| Transporter advance ledger      | `TransporterAdvanceLedgerService`; `AdvancePayment`   | Running advance                                                                                | Book Balance ledger                                            | Rules §11           | Missing                       | `P6`              | High      | Vouchers                     | Authorized                | Audited signed entries                | Ledger Pest            | `REDESIGN`                          |
| Lorry Hire cancel + settle Y/N  | FR cancel flows                                       | Preview; settle → advance; else retain                                                         | Cancel service + book balance                                  | PRD §13             | Missing                       | `P6`              | Critical  | Owner/Admin perm             | Authorized                | Implement                             | Cancel Pest            | —                                   |
| LR cancel blocked by active FR  | LR controller                                         | Active hire blocks                                                                             | `LorryHireLrDependencyGuard` in `CompositeLrDependencyGuard`   | Rules §8/§11        | Missing                       | `P6`              | Critical  | Composite guard              | Authorized                | Register guard                        | Negative Pest          | Trip guard pattern                  |
| Soft-delete Pending-only        | LR rules                                              | Active hire blocks delete                                                                      | Same via composite guard                                       | Rules §8            | Partial (Trip only)           | `P6`              | High      | Soft delete                  | Authorized                | Extend guard                          | Soft-delete Pest       | —                                   |
| Memo / POD delivery             | LR delivery fields; memo share                        | One memo → many same-customer bilties                                                          | `broker_deliveries`                                            | PRD §13             | Missing                       | `P6`              | High      | Documents                    | Authorized                | Bulk same-customer only               | Delivery Pest          | —                                   |
| Detention / deviation / misc    | FR columns                                            | +detention +deviation −misc                                                                    | `broker_adjustments`                                           | Rules §11           | Missing                       | `P6`              | High      | Hire                         | Authorized                | Typed adjustments                     | Adj Pest               | —                                   |
| Shortage / damage               | FR qty/rate/amount                                    | Deducts transporter + customer                                                                 | Adjustment records; invoice impact                             | PRD §13             | Missing                       | `P6`              | High      | Invoice                      | Authorized                | No double-deduct                      | Adj Pest               | —                                   |
| Unloading weight history        | LR `unloading_weight` overwrite risk                  | Latest valid unload; preserve load                                                             | Multiple unloading adjustment rows                             | PRD §13             | Missing                       | `P6`              | High      | Freight recalc               | Authorized                | History rows                          | Unload Pest            | —                                   |
| Customer invoice (Broker)       | `InvoiceController`                                   | Delivered LRs; with/without POD sections                                                       | `broker_invoices` (not `trip_invoices`)                        | PRD §12/§13         | Missing                       | `P6`              | Critical  | Delivery                     | Authorized                | Distinct engine                       | Invoice Pest           | Do not reuse TripInvoiceService     |
| Invoice payments / TDS customer | `InvoicePayment`                                      | Partial; customer TDS separate                                                                 | Broker invoice payments                                        | Rules §10/§11       | Missing                       | `P6`              | High      | Invoice                      | Authorized                | Separate from transporter TDS         | Payment Pest           | —                                   |
| Linked Bilty group              | `linked_freight_booking_id`; `VoucherLinked`          | Min 2; shared vehicle/payee; proportional pay                                                  | `linked_bilty_groups` + members                                | PRD §13; TB-BRK-003 | Missing                       | `P6`              | Critical  | Per-bilty hire               | Authorized                | Proportional bcmath + residual        | Linked Pest            | —                                   |
| Hire number via MAX()+1         | `allocateNextVoucherNumber`                           | `max+1` lock                                                                                   | `DocumentNumberAllocator`                                      | Architecture        | `REDESIGN`                    | `P6`              | Integrity | Numbering                    | Approved                  | Never MAX()+1                         | Concurrency Pest       | —                                   |
| Float monetary math             | Legacy helpers                                        | `float` / `round`                                                                              | bcmath string decimals                                         | Rules calc          | `REDESIGN`                    | `P6`              | Integrity | —                            | Approved                  | Decimal-safe only                     | Money Pest             | —                                   |
| Auth / subscription of legacy   | `SubscriptionGuard` etc.                              | Separate product provisioning                                                                  | Nexus Identity + Entitlement                                   | Architecture        | `DEPRECATED`                  | —                 | —         | —                            | Approved                  | Do not copy                           | —                      | —                                   |
| GL / Tally export               | `AccountingPostingService`; Tally XML                 | Postings                                                                                       | ADR-010 Pending                                                | Rules §12           | Defer                         | `P8`              | —         | Accountant validation        | Pending                   | No fake GL                            | —                      | —                                   |
| Operational registers           | `ReportController`                                    | Hire/voucher/memo/pending invoice                                                              | Phase 6 ops views only                                         | Phases §6/§11       | Missing                       | `P6` / rest `P11` | Medium    | Data                         | Authorized for ops subset | Implement ops views; full reports P11 | Report smoke           | —                                   |

### Approved outstanding formula (Nexus)

```
Transporter Freight (variable) = ApplicableWeight × ApprovedRate
Transporter Freight (fixed)    = ApprovedFixedAmount
ApplicableWeight               = min(LoadingWeight, UnloadingWeight) when unload applies; else LoadingWeight
TDS Basis                      = Original loading freight OR original fixed freight (immutable snapshot)
TDS Amount                     = TDS Basis × ApplicableRate%
Net Payable                    = Freight + PositiveAdj − NegativeAdj − TDS
Outstanding                    = Net Payable − SettledVoucherAmounts ± ApplicableBookBalanceEntries
Group allocation               = proportional to each hire EligibleOutstanding; residual deterministic
```

---

## B. Partload (legacy `truckbill_partload` → Phase 7 — **no implementation now**)

| Feature                    | Legacy controllers / notes                   | Nexus                                        | Phase        | Action           |
| -------------------------- | -------------------------------------------- | -------------------------------------------- | ------------ | ---------------- |
| Bilty                      | `BiltyController`                            | Unified LR Partload context exists partially | `P7`         | Handoff only     |
| Loading Report             | `LoadingReportController`                    | Missing                                      | `P7`         | Defer            |
| Challan / Main Challan     | `ChallanController`, `MainChallanController` | Missing                                      | `P7`         | Defer            |
| Inward Register            | `InwardRegisterController`                   | Missing                                      | `P7`         | Defer            |
| Delivery Receipt           | `DeliveryReceiptController`                  | Missing                                      | `P7`         | Defer            |
| Agent settlements          | Agent + voucher flows                        | Missing                                      | `P7`         | Defer            |
| Partload vouchers/invoices | `VoucherController`, `InvoiceController`     | Distinct from Broker                         | `P7`         | Defer            |
| Masters overlap            | Party/Station/Vehicle/Goods                  | Nexus Masters `DONE`                         | Shared       | Reuse            |
| Accounting bridges         | Ledger reports                               | `P8`                                         | Defer        |
| Expense management         | —                                            | **Excluded** DEC-005                         | `DEPRECATED` | Do not implement |

---

## C. OwnFleet (legacy `truckbill_ownfleet` → Phase 5 vs later)

| Feature                         | Nexus status           | Phase      | Notes            |
| ------------------------------- | ---------------------- | ---------- | ---------------- |
| Trip 1:1 OwnFleet LR            | `DONE`                 | —          | `TripService`    |
| Pickups / Drops / Allocations   | `DONE`                 | —          | Multi-point      |
| Dispatch / Complete / Cancel    | `DONE`                 | —          | Status machine   |
| Expenses / Diesel               | `DONE` (expense table) | —          | Preserve         |
| Trip invoices / payments        | `DONE`                 | —          | Sample tax only  |
| No-Freight redaction            | `DONE` / harden        | `P1-5`     | Keep server-side |
| Attendance / wages              | Missing                | `P10` / PO | Not Phase 6      |
| Tyres / Batteries / Maintenance | Missing                | `P10`      | Not Phase 6      |
| GPS                             | Usage-based later      | `P9`/`P10` | Not Phase 6      |

---

## D. Admin / CRM (legacy `truckbill_admin`)

| Feature                              | Nexus                              | Phase               | Action                           |
| ------------------------------------ | ---------------------------------- | ------------------- | -------------------------------- |
| Leads / notes / follow-ups           | Internal CRM (authorized surfaces) | 2A / later          | Do not recreate legacy CRM app   |
| OTP / cross-product onboarding       | Legacy provisioning                | `DEPRECATED`        | Nexus invitations + Owner create |
| Roles / permissions                  | Permission Master                  | `DONE`              | Extend Broker codes only         |
| Pricing / promo / subscriptions      | Nexus Subscription                 | `DONE` / EX pending | No parallel engine               |
| Company provisioning across products | Multi-repo API                     | `DEPRECATED`        | Unified company workspace        |

---

## E. Phase 1–5 residual gaps (close alongside Broker)

| Gap                                         | Status at Batch 0       | Action                                                                     |
| ------------------------------------------- | ----------------------- | -------------------------------------------------------------------------- |
| GA Actions #87 Larastan seeder `??`         | Fixed `ae48109`         | Keep business assertion                                                    |
| Enquiry freight No-Freight redaction        | Fixed prior (`0fed529`) | Regression tests                                                           |
| OwnFleet entitlement on Trip/Invoice UI     | Fixed prior             | Preserve                                                                   |
| Branch dashboard Owner \|\| `PLATFORM_VIEW` | Fixed prior             | Preserve                                                                   |
| RBAC HTTP matrix completeness               | Interim PARTIAL         | Expand Broker + residual denials in Batch I                                |
| Premium UI depth (not PageShell-only)       | Partial structural IA   | Continue on Broker screens; polish remaining Phase 1–5 surfaces in Batch I |
| Production tax / live Razorpay / ADR-010 GL | External Pending        | Do not claim                                                               |

---

## F. Explicit non-goals during Phase 6

- Phase 7 Partload implementation
- Live Razorpay / production tax invoices
- GL postings (ADR-010 Pending)
- Legacy data migration (Phase 11)
- Attendance, tyres, batteries, vehicle maintenance (Phase 10)
- Copying legacy auth, subscription, AdminLTE UI, or float math
- Git push / production deploy / Homebrew MySQL datadir mutation

---

## G. Authorization record

**PO directive (24 November 2026):** Explicitly authorizes Phase 6 Broker / Market-Hired full-stack implementation under this instruction. Phase 7 remains unauthorized for coding. Historical Phase 0–5 acceptance records are preserved unchanged.
