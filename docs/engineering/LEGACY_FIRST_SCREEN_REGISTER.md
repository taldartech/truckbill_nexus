# Legacy-First Frontend Reconstruction — Screen Register

**Started:** 25 November 2026  
**Baseline HEAD:** `0f813b791e258b7ee90e6b3254e1df48cefea8d4`  
**Approach:** Reconstruct from `legacy/truckbill_broker` + `legacy/truckbill_ownfleet` LRF/FB/trf CSS and templates; connect existing Nexus backend.

| Screen                                              | Legacy source                                                                           | Status                             | Browser       | Notes                                                 |
| --------------------------------------------------- | --------------------------------------------------------------------------------------- | ---------------------------------- | ------------- | ----------------------------------------------------- |
| Bilty create                                        | Broker `lorry_receipts/create.blade.php` + `lr-form-redesigned.css`; OwnFleet Own panel | IMPLEMENTED — BROWSER CAPTURED     | Yes           | `LrfPage`; ownership-driven Market/Own                |
| Bilty edit                                          | same form                                                                               | IMPLEMENTED — VERIFICATION PENDING | Partial       | Shares create layout                                  |
| Bilty register                                      | Broker/OwnFleet index                                                                   | IMPLEMENTED — AppIndexPage         | Yes           | Off PageShell; legacy column order                    |
| Bilty detail                                        | FB/LR show patterns                                                                     | IMPLEMENTED — BROWSER CAPTURED     | Yes           | `FbDetailPage`; CTAs Create Trip / Manage Hire        |
| Lorry Hire create                                   | Broker `freight_bookings/create.blade.php`                                              | IMPLEMENTED — VERIFICATION PENDING | Prior         | `LrfLinkedHero`                                       |
| Lorry Hire detail                                   | `freight_bookings/show.blade.php` + `fb-show-redesigned.css`                            | IMPLEMENTED — BROWSER CAPTURED     | Yes           | U23-HIRE-HERO closed in layout; 8/4 FB                |
| Lorry Hire register                                 | FB index columns                                                                        | IMPLEMENTED — BROWSER CAPTURED     | Yes           | GR Date → LR → Customer → … → Balance                 |
| Voucher create                                      | `voucher_payments/create.blade.php`                                                     | IMPLEMENTED — BROWSER CAPTURED     | Yes           | Context strip + `LrfSection`                          |
| Voucher detail                                      | voucher show + FB CSS                                                                   | IMPLEMENTED — BROWSER CAPTURED     | Yes           | `FbDetailPage`                                        |
| Voucher register                                    | voucher index                                                                           | IMPLEMENTED — BROWSER CAPTURED     | Yes           | `AppIndexPage`                                        |
| Daily Payments                                      | voucher daily                                                                           | IMPLEMENTED — BROWSER CAPTURED     | Yes           | AppIndex + bulk status                                |
| Transporter Book Balance                            | transporter book                                                                        | IMPLEMENTED — BROWSER CAPTURED     | Yes           | AppIndex statement                                    |
| Linked Bilty create                                 | linked create                                                                           | IMPLEMENTED — BROWSER CAPTURED     | Yes           | `LrfPage` numbered sections                           |
| Linked Bilty detail                                 | linked show / combined pay                                                              | IMPLEMENTED — BROWSER PENDING DEEP | Layout        | `FbDetailPage`; payment forms preserved               |
| Linked Bilty register                               | linked index                                                                            | IMPLEMENTED — BROWSER CAPTURED     | Yes           | AppIndex                                              |
| Combined / Bulk Payment                             | voucher bulk / linked pay                                                               | PARTIAL                            | —             | Linked show + voucher bulk URLs retained              |
| Approved Freight Adjustments                        | hire adjustments                                                                        | IMPLEMENTED (on Hire detail)       | Yes           | Inline on Hire show                                   |
| Delivery / Memo / POD                               | deliveries                                                                              | IMPLEMENTED — BROWSER CAPTURED     | Yes           | AppIndex operational register                         |
| Customer Invoice create                             | invoices                                                                                | IMPLEMENTED — LAYOUT               | Pending       | `LrfPage`                                             |
| Customer Invoice detail                             | invoices show                                                                           | IMPLEMENTED — LAYOUT               | Pending       | `FbDetailPage`                                        |
| Customer Invoice register                           | invoices index                                                                          | IMPLEMENTED — LAYOUT               | Pending       | AppIndex (`/broker/invoices`)                         |
| Customer Receipt                                    | invoice payments                                                                        | PRESERVED ON DETAIL                | —             | Payment form on invoice show                          |
| Enquiry / Quotation                                 | enquiries                                                                               | IMPLEMENTED — LAYOUT               | Pending       | AppIndex / Lrf / Fb                                   |
| POD / Document management                           | document drawer                                                                         | PRESERVED                          | —             | Drawer on bilty/trip                                  |
| Printing workflows                                  | print routes                                                                            | PRESERVED                          | —             | Existing print actions                                |
| Trip index                                          | OwnFleet Vue trips index                                                                | IMPLEMENTED — LAYOUT               | Pending       | AppIndex                                              |
| Trip create / init                                  | OwnFleet create                                                                         | IMPLEMENTED — LAYOUT               | Pending       | `LrfPage`; LR-first only                              |
| Trip operational workspace                          | `TripDetailPage.vue`                                                                    | IMPLEMENTED — BROWSER CAPTURED     | Yes           | `trip-detail` 8/4; expenses inline                    |
| Driver assignment                                   | on trip / bilty Own                                                                     | PRESERVED                          | —             | On create/show fields                                 |
| Multi-point Pickup/Drop                             | Trip multi-point Vue                                                                    | PARTIAL                            | —             | Movements on trip show; no dedicated MP create        |
| Dispatch / Completion                               | trip actions                                                                            | PRESERVED ON SHOW                  | Yes           | Forms on trip show                                    |
| Expense Management                                  | `TripExpensesRedesignedPage.vue`                                                        | PARTIAL — INLINE                   | Yes           | Dedicated route still missing                         |
| Diesel Management                                   | diesel card                                                                             | PARTIAL                            | Yes           | Mileage/diesel side card; not full expected-vs-actual |
| Profitability                                       | trip reports / sidebar                                                                  | PARTIAL                            | Yes           | Financial summary side card                           |
| Party Master index                                  | masters                                                                                 | IMPLEMENTED — LAYOUT               | Capture retry | AppIndex                                              |
| Vehicle Master index                                | masters                                                                                 | IMPLEMENTED — LAYOUT               | Capture retry | AppIndex                                              |
| Station Master index                                | masters                                                                                 | IMPLEMENTED — LAYOUT               | Capture retry | AppIndex                                              |
| Goods / Truck / Tyre / Package / Expense catalogues | catalogues                                                                              | IMPLEMENTED — LAYOUT               | Pending       | AppIndex                                              |
| Bank/Cash Accounts index                            | masters                                                                                 | IMPLEMENTED — LAYOUT               | Pending       | AppIndex                                              |
| Master create/edit/show forms                       | Blade CRUD                                                                              | IMPLEMENTED — LAYOUT               | Pending       | Party/vehicle/station/bank-cash Lrf + Fb show         |

## Counts (authorized operational + masters in this register)

| Metric                                                               | Count                                                                                   |
| -------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Total applicable screens listed                                      | 42                                                                                      |
| Reconstructed (layout migrated off generic PageShell where targeted) | 36                                                                                      |
| Browser-captured this pass                                           | 16+ under `storage/visual-certification/legacy-first/`                                  |
| Live legacy visual comparison                                        | Blocked — legacy apps not launched; source Blade/Vue + Nexus screenshots only           |
| Blocked / intentional gaps                                           | Dedicated trip expenses route; archive/restore; stop-scoped expenses; Batch 4 / Phase 7 |

## Regression (continuation pass)

| Check                                                                      | Result                                                                                                                   |
| -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `npm run check` / `types:check` / production build / Pint                  | Pass @ `946f743`                                                                                                         |
| Focused Pest (`LorryHire\|TransporterVoucher\|LinkedBilty\|BrokerInvoice`) | **40 passed**                                                                                                            |
| Full PostgreSQL Pest                                                       | Incomplete locally this pass — disposable Docker PG used for focused slices; full suite still open (U23-FULL-REGRESSION) |
| Disposable MySQL 8.4 Pest                                                  | PO-LGC-018 + dialect/seeder slices **Pass** on port 3307                                                                 |
| Playwright journey A (Bilty Register)                                      | **Pass**                                                                                                                 |
| Playwright journey B/C                                                     | **Pass** (MT rates + Create Lorry Hire CTA)                                                                              |
| Live legacy apps                                                           | Not launched (read-only source + Nexus screenshots only)                                                                 |

## Intentional approved differences (preserve)

- Blank bilty number = auto numbering
- MT operational / KG canonical weights; two-row goods with package type (PO-LGC)
- Intended payee does not auto-create Hire
- Stations as master FKs for OwnFleet
- No co-driver
- Trip expenses remain trip-level until backend stop FK exists

## Legacy browser

Live legacy app launch not completed this pass — structure from Blade/CSS/Vue source evidence. Nexus screenshots captured after branch-panel entry against built assets.

## Screenshot paths (this continuation)

- `storage/visual-certification/legacy-first/02-nexus-hire-show-1440.png`
- `storage/visual-certification/legacy-first/03-nexus-hire-index-1440.png`
- `storage/visual-certification/legacy-first/04-nexus-voucher-show-1440.png`
- `storage/visual-certification/legacy-first/05-nexus-voucher-create-1440.png`
- `storage/visual-certification/legacy-first/05b-nexus-voucher-index-1440.png`
- `storage/visual-certification/legacy-first/05c-nexus-daily-payments-1440.png`
- `storage/visual-certification/legacy-first/05d-nexus-book-balance-1440.png`
- `storage/visual-certification/legacy-first/06-nexus-trip-show-1440.png`
- `storage/visual-certification/legacy-first/07-nexus-bilty-show-1440.png`
- `storage/visual-certification/legacy-first/08-nexus-linked-index-1440.png`
- `storage/visual-certification/legacy-first/09-nexus-linked-create-1440.png`
- `storage/visual-certification/legacy-first/11-nexus-deliveries-1440.png`
- (masters/invoice captures re-run under `/parties`, `/vehicles`, `/broker/invoices`)

## Remaining meaningful differences vs legacy (not certified live)

- Hire/bilty financial summary still uses shared hire-oriented bar chrome for LR billable totals
- Trip workspace expenses are inline (legacy dedicated expenses page)
- Trip diesel card lacks full expected-vs-actual performance metrics
- Multi-point standalone create/edit routes absent (Nexus LR-first)
- Master forms still PageShell
