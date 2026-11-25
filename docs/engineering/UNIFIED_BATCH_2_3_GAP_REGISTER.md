# Unified Batch 2 & 3 — Gap Register

**Date:** 25 November 2026  
**Authority:** Combined Batch 2 Broker UI/UX + Batch 3 OwnFleet alignment + PO-LGC-018 critical corrections  
**Baseline HEAD:** pre-correction `83d7bef`  
**Status:** Active — business-rule corrections in progress; Batch 2/3 not closed

## Classification

| ID                     | Gap                                                          | Class             | Status                                                                           |
| ---------------------- | ------------------------------------------------------------ | ----------------- | -------------------------------------------------------------------------------- |
| U23-BILTY-OWN-PANEL    | Own vehicle panel shows Primary Driver; hide Pay To          | Vehicle-dependent | Aligned                                                                          |
| U23-SAVE-TRIP          | Save & Manage Expenses → `redirect_to_trip`                  | Vehicle-dependent | Aligned                                                                          |
| U23-QC-OWN-VEHICLE     | Inline Own Vehicle quick-create (type/tyre/driver)           | Shared            | Aligned                                                                          |
| U23-COMBOBOX           | Single searchable combobox standard                          | Shared            | Aligned (196a7ae+)                                                               |
| U23-PAY-TO             | Market Pay To Owner\|Agency + intended payee                 | Market            | Aligned; Hire inherits payee (PO-LGC-018)                                        |
| U23-HIRE-COMPACT       | Hire create compact chrome + numbered sections               | Market visual     | Aligned (this pass)                                                              |
| U23-REGISTER-COMPACT   | Bilty Register compact chrome                                | Shared visual     | Aligned                                                                          |
| U23-VOUCHER-COMPACT    | Voucher create compact chrome                                | Market visual     | Aligned                                                                          |
| U23-VOUCHER-SEARCH     | Searchable hire + account selectors on voucher create        | Market            | Aligned (this pass)                                                              |
| U23-LINKED-LAYOUT      | Linked Bilty create two-panel Document \| Vehicle & Payee    | Market visual     | Aligned (this pass)                                                              |
| U23-HIRE-SHOW          | Hire show compact financial cockpit                          | Market visual     | Partial (compact done; density)                                                  |
| U23-HIRE-HERO          | Legacy Freight Booking linked-bilty hero / hire charges grid | Market visual     | Aligned (hero payload + inherit payee)                                           |
| U23-CUSTOMER-MANDATORY | Blank Billing Customer blocked; detail shows name            | Shared critical   | Aligned (PO-LGC-018)                                                             |
| U23-BROKER-ROUTE       | Broker From/To from Consignor/Consignee                      | Market critical   | Aligned (PO-LGC-018)                                                             |
| U23-RATE-MT            | Four new rate types; MT UI; historical `rate_semantics`      | Shared critical   | Aligned + certified (PO-LGC-018 pass)                                            |
| U23-DUP-HIRE           | False active Hire → redirect to existing / uniqueness kept   | Market critical   | Aligned; frontend lock + conflict recover                                        |
| U23-CONFIRM-UI         | Structured multi-column confirmation dialogs                 | Shared visual     | Aligned (partial; continue polish)                                               |
| U23-TRIP-ODO           | Odometer on trip create (legacy Vue) vs dispatch on show     | Own intentional   | Retain Nexus (dispatch)                                                          |
| U23-MULTI-POINT        | Multi-point pickup/drop UI polish                            | Own               | Aligned dedicated `/trips/{id}/multi-point`                                      |
| U23-EXPENSE-UX         | Manage Expenses visual parity                                | Own visual        | Aligned dedicated `/trips/{id}/expenses`                                         |
| U23-BULK-VOUCHER-UI    | Combined/Bulk Payment operator UI                            | Market            | Aligned layout (`broker/vouchers/bulk`)                                          |
| U23-DIESEL-VARIANCE    | Expected vs actual diesel presentation                       | Own               | Aligned when planned diesel recorded                                             |
| U23-OWNFLEET-DISCOVER  | Dashboard + sidebar OwnFleet entry points                    | Own               | **Superseded** — no separate Start Trip / Active Trips nav                       |
| U23-UNIFIED-BILTY      | Truck-first unified Bilty; Own save creates Trip atomically  | Shared critical   | Aligned (this PO directive)                                                      |
| U23-REGISTER-ACTIONS   | Market Create/View Hire; Own Change Status + Manage Expenses | Shared critical   | Aligned                                                                          |
| U23-STATUS-LIFECYCLE   | Pending→In Progress→Delivered→Invoiced (Broker Hire sync)    | Shared critical   | Aligned                                                                          |
| U23-BOOK-BALANCE       | Transporter Book Balance on Party Master + Adjust in Advance | Market critical   | Aligned                                                                          |
| U23-NEG-ADJ            | Balance Due / Negative Adjusted Amount split                 | Market critical   | Aligned                                                                          |
| U23-VOUCHER-REMAINING  | Remaining payable after Pending/In Progress reservation      | Market critical   | Aligned                                                                          |
| U23-PRE-DELIVERY-ADJ   | Freight adjustments editable before Delivered                | Market            | Aligned (hire show; not gated on delivery)                                       |
| U23-PENDING-DELETE     | Soft-delete Pending Bilty (+ draft Trip cascade)             | Shared            | Aligned                                                                          |
| U23-FCM-GST-SPLIT      | Forward Charge CGST/SGST or IGST on customer invoice         | Market critical   | Aligned                                                                          |
| U23-OPTIONAL-DETAILS   | GR Number / Invoice Number / Goods Value / Reference By      | Shared            | Aligned                                                                          |
| U23-REQUIRED-ASTERISK  | Red * on mandatory labels via shared Label `required`        | Shared            | In progress (shared Label + combobox wired)                                      |
| U23-INVOICE-UNIFIED    | Already unified invoice — visual polish                      | Shared            | Partial                                                                          |
| U23-LEGACY-SCREENSHOTS | Three-way visual certification artifacts                     | Certification     | Open (live legacy apps still blocked)                                            |
| U23-FULL-REGRESSION    | Full PG + MySQL + Playwright matrix                          | Certification     | Focused ACC/Partload/Catalogue dual-DB green; full suite + Playwright still open |
| U23-HIRE-MULTI-PAY     | Hire register multi-select → bulk voucher                    | Market            | Aligned (26 Nov 2026 ERP directive)                                              |
| U23-PAYOUT-EXPORT      | Bank payout CSV export from voucher register                 | Market            | Aligned (26 Nov 2026 ERP directive)                                              |
| U23-HIRE-VOUCHER-CTA   | Hire index Record voucher CTA for outstanding hires          | Market            | Aligned (was missing from listPayload)                                           |
| U23-PARTLOAD-CHALLAN   | Partload Challan consolidation + dispatch                    | Partload          | Aligned foundation (26 Nov 2026 ERP)                                             |
| U23-PARTLOAD-INWARD-DR | Partload Inward Register + Delivery Receipt                  | Partload          | Aligned foundation (26 Nov 2026 ERP)                                             |
| U23-ACC-F0             | Accounting COA/periods/posting engine                        | Accounting        | Aligned foundation (`1b48769`)                                                   |
| U23-ACC-UI             | Day Book / Ledger / Trial Balance / manual voucher UI        | Accounting        | Aligned foundation (26 Nov 2026 ERP)                                             |
| U23-ACC-PNL-BS         | Profit & Loss + Balance Sheet reports                        | Accounting        | Aligned (`5811d66`)                                                              |
| U23-ACC-VOUCHER-POST   | Transporter voucher Paid → GL Payment posting                | Accounting        | Aligned (`5811d66`)                                                              |
| U23-ACC-LEDGER-QA      | Accounting ledger quick-add on voucher create                | Accounting        | Aligned (`5811d66`)                                                              |
| U23-PARTLOAD-MAIN      | Partload Main Challan consolidation + cascade dispatch       | Partload          | Aligned (`0c59683`)                                                              |
| U23-ACC-OPENING        | Opening balance dry-run + idempotent Opening voucher         | Accounting        | Aligned (`b9a4708`)                                                              |
| U23-ACC-OPENING-UI     | Opening balance operator entry UI                            | Accounting        | Aligned (`a443743`)                                                              |
| U23-MYSQL-FK           | MySQL composite FK SET NULL / identifier length              | Infra             | Aligned for ACC/Partload migrations (`b9a4708`)                                  |
| U23-ACC-CASH-BANK      | Cash Book / Bank Book reports                                | Accounting        | Aligned (`ada76d5`)                                                              |
| U23-ACC-PERIOD-REOPEN  | Controlled reopen of closed accounting periods               | Accounting        | Aligned (`22d1428`)                                                              |
| U23-ACC-CONTRA-UI      | Cash/Bank Contra fund transfer UI                            | Accounting        | Aligned (`1e891d6`)                                                              |
| U23-ACC-OUTSTANDING    | Outstanding control-account enquiry                          | Accounting        | Aligned (`df5ee79`)                                                              |
| U23-ACC-TAX-SUMMARY    | GST/TDS tax control-account summary                          | Accounting        | Aligned (`d00af8b` / `6de1b87`)                                                  |
| U23-PARTLOAD-PENDING   | Partload Pending Loading register                            | Partload          | Aligned (`2eeb9f1`)                                                              |
| U23-QA-CATALOGUE       | Bilty Goods/Package + Trip Expense Category quick-add        | Shared            | Aligned (`4c23b24`+)                                                             |
| U23-QA-STATION         | Bilty Station quick-add (owner stations)                     | Shared            | Aligned (26 Nov 2026 ERP)                                                        |
| U23-QA-VEHICLE-NESTED  | Nested Truck Type / Tyre Layout quick-add on vehicle sheet   | Shared            | Aligned (`fe92bc4`)                                                              |
| U23-QA-BANK-CASH       | Bank/Cash quick-add on voucher + trip expense forms          | Shared            | Aligned (26 Nov 2026 ERP)                                                        |
| U23-PARTLOAD-INTRANSIT | Partload In Transit (Dispatched Challans) register           | Partload          | Aligned (`e1d5967`)                                                              |
| U23-PARTLOAD-PEND-DEL  | Partload Pending Delivery register                           | Partload          | Aligned (26 Nov 2026 ERP)                                                        |

## PO directive (25 Nov 2026) — superseding earlier OwnFleet navigation

- No separate mandatory LR + Trip creation flow.
- Truck-first unified Bilty form; Own Vehicle submission creates internal Trip atomically.
- Remove OwnFleet — Start Trip / Active Trips as mandatory operator entry points.
- Bilty Register is the primary operational workspace for Market and Own vehicles.
- Preserve internal Trip entities for expenses, diesel, movement, and multi-Bilty trips.

## Intentional approved differences

- Stations master FKs for OwnFleet (vs legacy free-text cities).
- Separate Bilty vs Lorry Hire / Trip entities (Trip remains internal, not a separate operator process).
- Intended payee does not auto-create Hire.
- Odometer captured at Trip dispatch/complete (Change Status), not bilty create.
- No Co-driver.
- Own Vehicle Pay To / Agency hidden.
