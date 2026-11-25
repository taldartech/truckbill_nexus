# TruckBill Platform — Full Rules and Calculation Specification

**Status:** Approved by Product Owner — Phase 0 governing baseline.  
**Approval date:** 21 November 2026 | **Approval scope:** Phase 0 governing baseline.  
**Approval subject to:** Accepted Pending, Deferred and Proposed exceptions (PO-EXCEPT-001). User-supplied tax and TDS rules still require professional legal validation before production (TB-TAX-001 remains Pending). Phase numbering follows `Phases.md` only (DEC-001).

## 1. Rule precedence

Security and tenant isolation → immutable financial/audit integrity → explicit module rule → global platform rule → UI convenience. Permission cannot bypass business restrictions. Entitlement, feature availability, role permission and business eligibility must all pass.

## 2. Identity, ownership and access

- Internal Team and Customer identities are mutually exclusive.
- No public self-registration; reuse identities; never overwrite passwords.
- Customer branch access is explicit; no All Branches. New branches do not grant existing users access.
- Exactly one Owner/company; Owner Panel only Owner.
- Only Internal Super Admin/Admin transfers ownership to existing Customer User.
- New Owner receives Owner privileges; old Owner loses access to that company; other users unchanged; new Owner sessions invalidated; history retained.
- Customer single active session across web/mobile; Internal Team multi-session.
- Password change/deactivation invalidates customer sessions.
- **Email changes (DEC-009):** only through authorized administration for Internal and Customer identities; no self-service email change. Pending: verification, notification, uniqueness and audit field details.
- **No self-service account deletion (DEC-006).** Account lifecycle uses authorized administrative processes. Hard deletion and restoration of identities are prohibited (DEC-003).
- **Owner identity deactivation** is distinct from **company deactivation**. A deactivated Owner identity cannot log in.
- **Company deactivation (DEC-002):** Does not deactivate the Owner identity. Branch Panel access is blocked for the Company Owner and all company users of that company. Owner Panel remains accessible for an active Owner (login-capable identity with Owner designation) for company-level administrative and billing information. Must not restore operational Branch Panel access. No data deletion. Authorization must not apply company deactivated status as a blanket deny that blocks this Owner Panel exception (Architecture §3). Pending: exhaustive Owner Panel action list while deactivated — do not invent.
- Ordinary user with no memberships after access revocation is soft-deleted, except loss solely due to Owner/company deactivation.
- Last active Super Admin cannot be demoted/deactivated.

## 3. Roles and permissions

- Action permissions assigned to roles, not users; customer roles assigned per branch.
- Owner designation separate.
- Delete is explicit, never inferred from Admin/Owner.
- Role cannot be deleted. Deactivated role cannot be newly assigned; affected users must be reassigned before branch access continues.
- Permission changes immediate; cache invalidation.
- Internal/customer permission domains separate.
- Super Admin unrestricted system-level.
- Every API, export, print, download, report and background job rechecks authorization.

## 4. Audit and deletion

- Creation does not require remark.
- Every edit/update/deletion requires meaningful remark.
- Cancellation requires reason and applicable cancellation date/time.
- Audit stores actor, timestamp, old/new values, deletion state.
- Mutation and audit event atomic.
- Audit immutable.
- **No hard delete or restore (DEC-003)** for user identities, companies, branches, business records, financial records, documents and audit records.
- Use controlled deactivation or soft deletion only where the applicable module requirements permit it.
- Soft delete requires explicit Delete permission and dependency checks.
- Do not invent one universal deletion workflow for every record type; follow module-specific rules. Unresolved lifecycle details remain Pending.
- Financial posted entries, approved invoices and contract snapshots follow stricter immutability rules.
- Cancellation is not deletion.

## 5. Company/data scope and masters

Shared Data=company-wide; Branch-wise Restricted=branch-wide. Party, Vehicle, Bank/Cash follow. Duplicate Parties across branches allowed. Station Master shared across Owner companies. Default transporter scope follows Data Visibility; station can have different default by company/branch; user can override; saved Bilty not affected by later default changes.
Goods Type, Truck Type, Tyre Layout, Document Type, Expense Categories and Package Type Owner-specific. Payment modes centrally seeded: Cash, Bank Transfer, UPI, Cheque, NEFT, RTGS, IMPS, Other. GST rates/TDS rules centrally managed.

## 6. Party and tax rules

Party roles: Customer, Consignor, Consignee, Transporter, Driver, General Vendor, Pump Vendor. Truck Owner merged Transporter. Multiple roles allowed; role mandatory on creation; edit/remove with remark/audit; removing role retains details. No mandatory uniqueness across GST/PAN/mobile/email; no custom role fields; no common opening balance/balance type/credit limit.
Customer/Consignor/Consignee fields: Name*, Contact Person, Contact Number*, Email, GST, PAN, Is GST Applicable default Yes, State*, City*, Address.
Transporter: Name*, Email, GST, PAN*, PAN Status*, Contact Person, Contact Number*, State*, City*, Address, Declaration*, TDS%_, adjustment remark, optional one bank account, Short Name, Beneficiary ID, Number of Goods Vehicles.
Driver: Permanent/Market, Name_, Mobile*, License Number mandatory Permanent, Address, License Expiry. Permanent-only Joining Date, Aadhaar, PAN, Bank Name/Account/IFSC, Emergency Contact Name/Mobile, Monthly Payment Base.
General Vendor: Name*, Contact Person*, Contact Number*, Email, State, City, GST, PAN. Pump Vendor: Name*, Contact Person*, Contact Number*.
GST company config: Regular/Composition/Unregistered; FCM/RCM; GSTIN conditional. Party Is GST Applicable controls GST, no transaction override. Rate snapshot; intra CGST/SGST, inter IGST. Composition Bill of Supply/no GST. Unregistered no GST. RCM shows statutory notice and excludes GST from net payable per configured treatment. **Professional legal/tax validation required before production (external validation; Pending until validated).**
Transporter TDS: Owner declaration valid and <=10 goods vehicles=0%; >10=standard rate. Agency ignores declaration and applies standard rate by PAN/entity type. Invalid/inactive PAN=20%. Active PAN fourth char P/H=1%; C/F/A/B/J/L/T=2%. Manual override requires remark. April 1 declaration resets No and recalculates. Basis original loading weight or original fixed freight; unloading adjustments do not alter. Submission lock affects TDS only. Snapshot basis/rate/amount per Lorry Hire. TDS rules structured AND/OR, priority, active/inactive; no match config error; changes apply new transactions only. Validate legal rules before production.

## 7. Enquiry

External website enquiry uses token-authenticated webhook, enters central unassigned company queue and requires authorized branch assignment. Internal enquiry attaches current branch. One vehicle type per enquiry; quantity may be multiple. Multiple goods rows. One quotation per transporter/enquiry; duplicate prohibited. Edit quotation with remark/audit. Selection history retained; other quotes Not Selected. Own Vehicle skips quotation. Whole enquiry conversion only; all requested vehicles arranged; no partial/split conversion. Duplicate conversion prevented. Retain assignment, follow-up, status and quotation history.

## 8. Unified LR

One LR foundation. Broker From/To optional open text, Consignor/Consignee required. OwnFleet From/To Station Master required, Consignor/Consignee optional. Partload From/To Station Master and Consignor/Consignee required. No loading/delivery address fields.
Goods: Goods Type, Package Type, units, actual/charged weight, rate type/rate/freight, optional goods value. Actual and charged weight separate/manual. Rate types Fixed, Per Package/Unit, Per KG, Per MT, Per Actual Weight, Per Charged Weight. Goods Value row-level and LR-level optional and not synchronized. Multiple EWBs; number required, date optional, Valid Until required. Freight per goods row; additional LR charges multiple positive amount/description; no LR discount.
Numbering Auto/Manual; scope follows Data Visibility; manual alphanumeric; duplicate check. Sequence adjustment requires remark/audit.
Editable until delivery; all goods delivered together; delivered locks entire LR. Cancellation before delivery blocked by active Trip/Lorry Hire; eligibility may reopen after relevant Lorry Hire cancellation. Pending-only soft delete with dependency checks.

## 9. OwnFleet Trip

One OwnFleet LR=one Trip. One vehicle/driver for full trip; substitute driver trip-only. Multi-point pickup/drop:

- Pickup: Station, Consignor, Goods, Weight, Pickup Date.
- Drop: Station, Consignee, selected pickups, allocation weight, Rate, Freight, Distance, Package, EWB, Goods Value.
- Pickup can split across drops; drop can combine pickups.
- Total allocated weight per pickup must exactly equal original before completion; partial allowed Pending/In Progress.
- Allocation >0; rate may be zero; freight may be zero per allocation but total freight must >0. Distance/package/EWB/Goods Value optional.
- Distance manual per pickup-drop; automated routing deferred. Pickup Date only; no Drop Date.
  Dispatch Pending→In Progress; starting odometer required, no comparison with last vehicle odometer. Departure datetime optional; blank defaults dispatch timestamp. Completion end odometer > start, actual arrival required/default current editable; distance=end-start. Expected end optional; if set Early/On Time/Delayed else N/A; company timezone.
  Cancel Pending/In Progress only Owner/Admin; cancellation date/time/reason; no cancellation odometer. Cancelled visible, income/expenses retained. Pending-only soft delete with Delete permission, even with expenses; exclude from normal lists/reports/operational calculations/profit but retain income/expense/docs/history. No restore. In Progress/Completed/Cancelled cannot soft-delete.
  Expenses multiple: category/date/amount, optional payment mode, Paid From Bank/Cash, remarks. Diesel litres/rate/odometer required; amount litres×rate. Trip mileage only if reliably attributable else Not Available. Vehicle mileage estimate from consecutive diesel entries without full/partial tank distinction, may fluctuate, not change configured mileage. Planned diesel quantity/rate optional; amount if both; planned efficiency optional; no planned distance. Trip profit=agreed LR freight+additional charges−trip expenses; GST not revenue.
  POD multiple, optional, after delivery; not required to complete. Supporting docs multiple. Global file rules.

## 10. Invoice engine

Completed OwnFleet LR individually or multiple completed uninvoiced LRs same customer. Separate particulars=LR lines; Combined=one freight line with annexure references. Custom positive charges with descriptions (detention, demurrage, halting, loading surcharge, other), included in invoice/GST.
Additional charges editable only Pending/no payments; lock after first payment; edit remark/audit.
Payment fields Amount Received, TDS 194C, Shortage/Damage Deduction, Discount (reason mandatory), Payment Date, Mode, optional Reference, Fill Net. Settlement=Amount Received+TDS+Deductions. Cannot exceed remaining balance. Cumulative TDS<=base freight. Payment immutable after save. Status Pending/Partial/Paid.
Invoice TDS 1% Individual/Transporter, 2% Company/Corporate; base freight excluding GST/additional charges; separate from transporter TDS. GST follows company config and snapshot.
Cancel only unpaid, reason mandatory. Cancelled/deleted unpaid LR may be reinvoiced. Separate Auto/Manual numbering.
Delete only latest eligible Pending/no-payment invoice in scope; second-last only after latest deletion. Shared Data latest company-wide; Restricted latest branch-wide. Number reusable after soft-delete; original record/audit retained; new internal ID; active-only duplicate check.

## 11. Broker/Market-Hired

Market vehicle Bilty→Lorry Hire→transporter vouchers→Memo/POD delivery→post-delivery adjustments→customer invoice.
Transporter freight per goods row corresponding LR row; rate type/rate/amount; Fixed Amount override. TDS rules as section 6; store Owner/Agency and original basis snapshot.
Vouchers multiple advance/final and partial. Modes Bank, Cash, Paid to Pump, Adjust in Advance. Bank/Paid to Pump Pending; Cash/Adjust in Advance In Progress. Daily page defaults today; multiselect; Pending→In Progress→Paid. Bulk only same transporter and vehicle; separate amount per voucher; common mode/type/date/remark. Transporter Book Balance auto-updates.
Lorry Hire cancellation Owner/Admin only; show freight/TDS/adjustments/payments/outstanding; ask settle: Yes transfers applicable outstanding to Transporter Book Balance; No retains against cancelled Lorry Hire. Reason/audit. Active Lorry Hire cannot delete. Cancelled Lorry Hire auto-soft-deleted if associated LR soft-deleted. Active Lorry Hire blocks LR deletion.
Bilty cancel only after Lorry Hire cancelled; delivered cannot cancel. Pending-only soft delete; active Lorry Hire blocks; cancelled Lorry Hire auto-soft-deleted with Bilty. In Progress/Delivered/Cancelled cannot soft-delete.
One POD/Memo number can attach multiple Bilties; bulk same-customer assignment; individual delivery record; multiple attachments.
Adjustments: detention adds transporter balance; deviations add transporter balance; misc expenses deduct transporter balance; unloading weight recalculates customer/transporter freight using lower loading/unloading unless fixed; shortage/damage deduct transporter balance and customer invoice. Multiple unloading adjustments preserve old/new weight/date/time/user/reason; original loading unchanged; latest unloading used; TDS original. Multiple shortage/damage retain type/quantity/rate/amount/date/reason/creator/time.
Customer invoice only after delivery/adjustments. Select Billing Customer. With POD/Memo section multi-select; without section individual; both on same invoice for same customer; prevent duplicates.
Linked Bilty: min 2; common group no/date/vehicle/payee/driver/reference; each independent LR/GR/invoice/customer/consignor/consignee/stations/goods/units/weight/rate/amount. Lorry Hire per Bilty before voucher payment. Group payment proportionate by each Lorry Hire outstanding after TDS/adjustments/prior payments; no over-allocation; separate voucher/payment per Lorry Hire.

## 12. Partload

**Excluded (DEC-005):** Partload Expense Management is out of scope. This does not restrict OwnFleet expense management (Rules §9 trip expenses/diesel and OwnFleet-related catalogue add-ons in §15).
Paid, To Pay, TBB, FOC. Charges Freight, Bilty Charge, Surcharge, Handling, Risk/Door Delivery, Cartage, custom, Less Advance. Cartage Cash/Due linked ledger. TBB freight may finalize later. GST liability Consignor/Consignee/Transporter/Exempted under company GST config; intra CGST/SGST, inter IGST. Multiple EWBs (number/validity), declared goods value, customer invoice number, Private Marka, Delivery At transporter. To Station auto-selects station default; manually change; later default changes do not alter saved Bilty.
Status Pending→In Transit on outgoing Challan (not Loading Report)→Inward/Delivery/Forward→Delivered via DR. Pending Bilty can cancel even with advance; retain history/reason. Delivered cannot cancel. Pending-only soft delete with dependencies.
Loading Report: date/from/to/truck/driver/remark; Pending and eligible inward/forward items; select physically loaded; print grouped by destination with Bilty/GR, Private Marka, Consignee, goods, packages/totals; generated on fly, not saved, no status change, multiple destinations.
Challan header From/To Station, Truck, Driver, Date, Auto/Manual No, Remark. To Station defaults transporter from Station Master, editable; Delivery At separate transporter. One Challan may contain Bilties assigned to different transporters. Auto totals: Weight, Nugs, To Pay, Paid/Advance, Crossing Freight, Cartage/Dalali/PF, GST. User enters Labour Commission, Kat, Home Delivery, Truck Freight, Cash Receive, Cash Paid. To Account any Party. Formula exactly:
`TOTAL = toPayAmount + crossingFreightTotal - labour_charge - kat - home_delivery_charge - truck_freight + cash_payment - cash_received`
Cash Receive=cash_received; Cash Paid=cash_payment. Do not invent transporter-wise settlement breakout.
Main Challan consolidates multiple regular Challans; sub-Challan belongs max one Main. Header Main No/date/from/to/remark; Truck/Driver stay on sub-Challan. Calculated totals include Weight, Nugs, To Pay, Paid/Advance, Crossing Freight, Cartage/Dalali/PF, GST, Labour, Kat, Home Delivery, Truck Freight, Cash Receive, Cash Paid, TOTAL, To Account. To Account and amounts auto-calc but editable; overrides persist. Number Auto/Manual, Data Visibility scope, alphanumeric manual, duplicate check, sequence changes remark/audit.
One Inward Register per incoming Challan. External manual; TruckBill QR/Exchange Code. Anyone can scan/enter; no login-based exchange authorization or sender revocation; code fetches data. Receiver reviews before create. Packages and Weight each one final editable value. Header Inward No/date/from/to receiving branch station/incoming Challan no/date/truck/driver/remark. Details GR/Bilty/date/from/to/consignor/consignee/goods/packages/weight/booking basis/freight/home delivery/Private Marka/Agent/disposition. Delivery if destination equals receiving branch station; otherwise Forward/Crossing. Forward independently with new vehicle/driver/transporter; original GR retained.
DR fields DR No/date/GR/mode Cash/Bank/Due/Consignee/Haste/Payment Due From/Freight/Handling/Delivery/Stationery/Demurrage/Other/Carriage/Subtotal/GST On Total Charge or Delivery Charge/GST/GST Pay By Consignor/Consignee/Transporter/Less/Total. GR popup shows inward detail. One DR/GR; one mode only. Cash→receiving branch cash; Bank→branch bank; Due→Payment Due From. Save auto-posts and marks GR Delivered. Final positive/negative Challan amount updates selected To Account party ledger; cash/bank changes only on actual payment. Party settlement separate from cash/bank. DR numbering common config Auto/Manual scope Data Visibility; sequence changes remark/audit. Edit with Edit/remark/audit/accounting. Delete with Delete/dependency/remark/soft delete; reverse entries/cash-bank and revert GR only if no downstream dependency; else block; preserve original and reversals.
Paid: full at booking Cash/Bank, no partial. To Pay: full at DR, no partial; DR Due is bulk invoice receivable, no immediate cash/bank. TBB invoice regardless of Bilty status; receivable when invoice created; partial/multiple payments. FOC all booking/freight charges zero (freight, bilty, surcharge, handling, risk/door, cartage, custom, less advance), no invoice/receivable. Bulk Invoice may include eligible To Pay and Due records; exclude invoiced; unified invoice rules.

## 13. Accounting

Central double-entry. Company books, branch attribution, FY April–March, chart of accounts/groups/ledgers, cost centres, opening balances. Vouchers Receipt, Payment, Contra, Journal, Sales, Purchase, Credit Note, Debit Note, Bank Reconciliation.
Posting intent: customer invoice Dr Customer / Cr revenue and GST; customer payment Dr bank/cash / Cr customer; invoice TDS Dr TDS receivable / Cr customer; Lorry Hire Dr freight expense / Cr transporter payable; transporter advance Dr advance / Cr bank/cash; transporter payment Dr payable / Cr bank/cash; transporter TDS Dr payable / Cr TDS payable; Trip expense Dr expense / Cr bank/cash/payable; Partload Paid Dr cash/bank / Cr income; To Pay DR Dr receivable / Cr income/GST; TBB invoice Dr Billing Customer / Cr income/GST; DR settlement according to mode. These mappings are posting intent and require accounting/tax review before production (**external validation required; Pending until validated**).
Reports: Account Ledger, Party Ledger, Cash Book, Bank Book, Day Book, Trial Balance, P&L, Balance Sheet, Receivables, Payables, Expense Summary, GST Summary, TDS Summary, Voucher Register, Bank Reconciliation.
Every posted voucher balances and is immutable. Correction by reversal/replacement. Source-linked idempotency. Closed period blocks unauthorized backdating. Cash/bank movement distinct from party-ledger settlement. Reports derive from ledger.

## 14. Files and storage

2 MB/file; JPG/JPEG/PNG/WEBP/PDF/DOC/DOCX/XLS/XLSX. Private S3, server validation, malware scan, authorized access. Failed upload no quota. Soft-deleted attachments retained and count toward quota. Replacement only releases old after new succeeds and old eligible for cleanup. Temporary uploads count after permanent attachment/storage. Periodic S3 inventory reconciliation.

## 15. Subscription rules

15-day trial first company per Owner only; subsequent no trial. Starts on successful company creation; all features subject to configurable limits; no grace; Owner Panel remains. Notifications activation, 7/3/1 days, expiry.
Paid grace configurable 10 days, view-only; after restriction; Owner Panel remains.
One company billing account/common renewal date. No base fee. Capacity: 2 branches incl HO, 3 seats each (6 total); extra branch ₹299/month including 3 seats; extra user ₹60/month or ₹650/year; additional truck ₹500/year prorated; Owner not billable.
Core plans/add-ons are configurable starting catalogue values, not hardcoded. Initial Core prices: Broker Micro 600 LR ₹5,000; Starter 1,200 ₹9,000; Growth 3,600 ₹12,500; Business 10,000 ₹30,000. OwnFleet Lite 5 vehicles ₹5,000; Starter 15 ₹10,000; Growth 50 ₹20,000; Business 100 ₹36,000. Partload Starter 2,000 ₹4,000; Growth 6,000 ₹9,000; Business 18,000 ₹25,000. Initial add-ons: Lorry Hire/Voucher ₹3,999; Invoice ₹3,999; Advanced Expense ₹2,499; Vehicle Documents ₹1,999; Tyre ₹3,999; Battery ₹1,999; Vehicle Maintenance ₹2,999; Spare Parts/Store ₹3,999; Workshop/Job Cards ₹3,999; Challan/Main Challan ₹1,999; Inward/DR ₹1,999; Accounting ₹5,999 yearly. GPS, messaging, compliance API, storage usage-based.
Bundle 0/10/15. Tenure monthly0, quarterly5, half-yearly8, yearly15, 3-year full advance25, 3-year annual instalments20. Eligible renewal 5%. Coupon percent/fixed/extra months. Monetary cap 30%; extra months excluded. Order base→bundle→tenure→renewal→coupon. Display list=base/0.75. Monthly/quarterly/half-yearly amount = annual × subscribed months/12, then tenure discount; yearly annual; 3-year annual×3 before discount.
Actual-day proration: annual price × remaining days / total days in annual term; include activation day; leap-aware; common date unchanged; round 2 decimals; apply discounts/GST after prorated base; activate after payment; preserve calculation. Mid-term second module gets 10% on new prorated item; third gets 15%; no retroactive repricing/refund.
Upgrade immediate/prorated; downgrade next renewal; add-on immediate after payment; module/add-on removal next common renewal no refund; all items renew together. Early renewal starts after current expiry. Capacity increases immediate after payment/prorated; decreases next renewal. Revalidate downgrade limits at effective date; if excess, retain current plan and notify.
Broker/Partload quotas prorated monthly 1/12, quarterly 3/12, half-yearly 6/12, yearly full; 3-year quota refreshes each 12-month year; fractional floor; coupon extra months grant prorated quota. Unused included/purchased credits carry indefinitely, separate ledgers; no transfer/cash value. 80/100 alerts; at 100 block new billable LR/Bilty. Broker top-up ₹10/LR min20 multiples10; Partload ₹4/Bilty min50 multiples10; prepaid only, Razorpay success.
OwnFleet active vehicles include idle/maintenance; sold/scrapped/retired/deactivated excluded; deactivation releases; reactivation requires capacity.
Other service rates/limits centrally configured; prepaid service-specific ledgers; successful billable consumption only; no postpaid. Purchased credits retained indefinitely but usable only when service active. At 100 block that service only; other modules continue. Reserve in-flight requests idempotently; failed operations release reservations.
Storage shared company-wide; 80% alert, 100% blocks new upload/storage-consuming operations; existing authorized files accessible. Additional capacity after payment, prorated. Count actual file sizes, including retained soft-delete attachments. Failed uploads do not count. Replacement old remains until new succeeds and cleanup eligible. Periodic reconciliation.
Price protection: active contract retains agreed price until renewal; new price applies new purchases immediately and existing at renewal; prefer 30-day notice; negotiated override explicit/audited.
Cancellation: Owner request, effective common renewal; active until paid-through subject to expiry rules; no refund/credit; withdrawable before renewal. Individual add-on same. Reactivation uses current catalogue, verified payment, explicit modules/add-ons and new common renewal date; data retained.
Subscription is company-bound/non-transferable. Ownership transfer leaves contract, modules, add-ons, price, renewal and payments unchanged; new Owner gets billing access; old loses it; pending payment remains company-associated. Deactivation/suspension does not pause term or renewal. Suspension immediately blocks Branch Panel, Owner Panel remains; reactivation authorized Internal Team/audit.
Contract snapshots immutable. Catalogue changes affect new purchases/renewals. Corrections authorized with reason/audit; agreed midterm changes explicit amendment/effective date.
Initial manual Razorpay renewals are delivered in **Phase 3** per `Phases.md` (DEC-001); no mandate/auto deduction for that delivery. Do not refer to subscription billing as “Phase 1.” Verify signature/webhook; successful payment activates; failure no extension. Successful webhook completes after browser close; retries idempotent. Payment and invoice number approval independent. Draft invoice after verified payment; proposed sequential number editable before approval; lifecycle Draft→Awaiting Number Approval→Approved/Issued→Emailed. Approval only invoice number; no rejection. Approved number locked even for Super Admin. Email failure does not reverse approval. Invoice issuer details centrally managed. Financial-year numbering configurable. No in-system refund initiation/reversal. External refund handled outside system; manual record adjustment with evidence/remark/audit, original preserved.
Reconciliation: webhooks primary; scheduled compare Order ID, Payment ID, company, amount, currency. Mismatch/unmatched/delayed to exception queue. Manual confirmation only with verifiable evidence, authorization, remark/audit.
Broker/Partload credits remain indefinitely; cannot consume while module inactive/restricted; reactivation restores. Service credits same, service-specific. Storage/usage visible in Owner Panel.

## 16. Communications

Central SMS/WhatsApp/Email platform. Provider status and billable status separate. Webhook signature verified/deduplicated. Consent/opt-out enforced. SMS applicable TRAI/DLT, WhatsApp approved templates/opt-in, email SPF/DKIM/DMARC. Templates versioned. Failed non-billable provider operation releases reservation. Provider-specific billable status is configurable. 80% alert; 100% block only exhausted service.

## 17. Assets

Vehicle documents: RC, Insurance, Fitness, PUC, National/State Permit, Road Tax, other configurable. Multiple history; renew creates new record; expired visible; reminders.
Tyres individually tracked; position from Tyre Layout; fitting/removal odometer and date; each reuse new cycle; cumulative history.
Batteries individually tracked; install/removal cycles, dates, odometer, reason, user, warranty.
Maintenance preventive/corrective; due by date/odometer; cost feeds Accounting once; downtime.
Inventory: part master, receipt/issue/return/adjustment/transfer/purchase return/job card issue; weighted average cost; prevent negative by default; adjustment permission/remark; damaged/expired separate.
Job Card: Draft/Open/Inspection/Work in Progress/On Hold/Completed/Closed; cancellation separate with reason. Labour, parts, external services, attachments, before/after photos, completion notes. Part issue decreases stock; return separate movement; external workshop may create vendor payable; no duplicate expense.

## 18. Acceptance

Every feature requires field validation, state transition tests, permission/tenant tests, audit/remark tests, cancellation/deletion dependency tests, accounting/reversal tests, entitlement tests, idempotency/retry tests, file/notification tests and operational monitoring. No production release without security, reconciliation, backup/restore and load certification.

## 18A. Geographic Master Enforcement

All Company, Branch, Party, Station and other Master-based geographic inputs must use valid State and City Master relationships. Custom State and City values are permitted exclusively for CRM Leads, including public website Lead submissions. Shared geographic services must not provide unrestricted text fallback for Master-based entities.

- When geography is optional, empty values remain permitted; any provided location must resolve to an active Master record with a valid State↔City relationship.
- Missing locations are created only through the shared Add State / Add City quick-create workflow (catalogue-safe State codes; duplicate City prevention).
- Denormalized State/City name columns on Master-based entities, where retained for presentation or search, must be derived from the selected Master records — never trusted from client free text.
- CRM Lead geography is stored as text without mandatory State/City foreign keys. Selecting a Master on a Lead form persists the Master name as text and does not invent a global Master from unmatched Lead text.

## 19. Codebase Cleanliness, Minimalism and Dead Code Elimination

These rules are permanent and mandatory for every implementation task.

### No unnecessary files

Do not create files without a clear implementation, testing, configuration or documentation purpose. Remove obsolete exclusive components, controllers, services, requests, utilities, types, routes, assets and duplicate documentation after verifying no valid consumer remains.

### No unnecessary database fields

Every column must have an identifiable business, technical, security or historical integrity purpose. Do not retain fields merely because a legacy system used them, a prior iteration needed them, a template expected them, they are hidden from the UI, or they might become useful someday. When a field becomes obsolete, migrate consumers and remove it through a safe forward migration.

### No hidden obsolete functionality

Removing a feature means removing its exclusive UI, routes, controllers, services, validation, permissions, types, configuration and tests. Do not hide a navigation entry or form field while retaining an unnecessary implementation. Preserve shared infrastructure and historical records where required.

### No duplicate implementations

Reuse existing domain services, form components, validation, query builders, authorization, API clients, confirmation frameworks and master-creation components. Do not create parallel implementations for the same business operation.

### No unnecessary lines of code

Remove unused imports, variables, functions, unreachable branches, obsolete commented-out code, redundant wrappers/transforms, duplicate validation and temporary debugging statements after a feature is replaced.

### No speculative architecture

Implement only approved requirements. Do not add abstractions, fields, feature flags or services for hypothetical future use without a documented reason. Prefer the simplest maintainable design that satisfies the approved rules.

### No excessive documentation

Maintain authoritative documents. Prefer updating an existing record over creating parallel progress/audit documents for small corrections. Avoid contradictory governing artefacts.

### No unnecessary dependencies

Review new packages before installation. Remove unused packages only after confirming no consumers. Prefer existing project capabilities. Do not remove required security, testing or build dependencies merely to reduce counts.

### Safe cleanup

Cleanliness must never compromise historical financial records, audit history, migration integrity, security, authorization, test coverage, public API compatibility, approved business rules or operational functionality. Do not delete legitimate defensive code to reduce line count.

### Mandatory cleanup before every task completion (Definition of Done)

Before completing any implementation task: review the full diff; remove unused imports/variables and temporary debug code; remove obsolete exclusive routes/components/helpers; review newly introduced database fields and duplicated business logic; update or remove obsolete documentation; run applicable static analysis and relevant tests. A feature is not complete if its replaced exclusive implementation remains unnecessarily in the codebase.
