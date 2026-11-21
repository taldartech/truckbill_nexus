# TruckBill Platform — Full Design Specification

## 1. Brand and experience
Light-first professional logistics SaaS. TruckBill gradient #2FB36F→#2D8CFF; supporting #4CAF50, #81C784, #388E3C, #2196F3, #FFC107, #F8F9FA, #F5F7FA, #212529. Avoid dark full-page backgrounds. Use official logo. Poppins preferred for marketing; product font optimized for legibility. Status never indicated by colour alone.

## 2. Application shell and navigation
Owner Panel (Company Owner only): company switcher, company/branch/user management, company settings, subscription, billing, invoices, credits, capacity, usage, storage and company audit.
Branch Panel: dashboard, Enquiry, Operations (Consignment/LR, Broker, OwnFleet, Partload), Accounting, Assets (Vehicle Documents, Tyres, Batteries, Maintenance, Spare Parts/Store, Workshop/Job Cards), Masters, Notifications, Settings.
Owner can return to Owner Panel without new login. Navigation hides inaccessible items but server-side authorization remains authoritative.

## 3. Shared page/table patterns
Page title/context, primary action, search/filter, server-side pagination, accessible status, loading/empty/error/permission states, confirmation for irreversible action, mandatory remark dialog for edit/delete/cancel. Detail tabs as relevant: Overview, Goods/Operations, Documents, Financials, History, Audit. Export only with permission and audit. Bulk actions validate records individually and report partial failures. Financial bulk actions require per-record amount preview.

## 4. Form standards
Group by business meaning; required labels; searchable dropdowns; conditional workflow fields; preserve values on validation error; formula and inputs visible for calculated values; server validation authoritative; duplicate-submit prevention; financial preview before posting. Date/time fields use company timezone and explain defaults.

## 5. Enquiry screens
List columns: source, status, customer, route, goods/weight, vehicle requirement, expected loading date, branch and assignee. Company-level unassigned external queue. Detail tabs for goods, follow-ups, assignment history, quotations, attachments and conversion. Market-Hired quotation comparison view with rate basis, total charges, advance, payment terms, delivery estimate and validity.

## 6. Unified LR and operational screens
Context-aware LR form with common goods grid, EWB, numbering and workflow panels. Show From/To/party requirements by workflow. OwnFleet Trip detail: vehicle/driver, multi-point route/allocation, dispatch/completion odometer, expenses, POD/docs, invoice, financial summary and history. Broker Lorry Hire detail: transporter freight, TDS basis, vouchers, outstanding, adjustments, POD/Memo and invoice. Partload screens: Bilty, Loading Report, Challan, Main Challan, Inward Register, DR. Print-friendly operational documents. Challan computed fields and manual overrides visibly differentiated. QR/Exchange Code data preview before inward creation.

## 7. Accounting UI
Tally-inspired keyboard-friendly voucher entry. Ledger autocomplete, debit/credit columns, running totals, balance validation, draft/posted status, source record, voucher preview/print, ledger drill-down and reversal relationship. Screens for Day Book, account/party ledger, Trial Balance, P&L, Balance Sheet, Cash Book, Bank Book and reconciliation.

## 8. Subscription UI
Owner Panel shows active modules/add-ons, contract, common renewal date, capacity, upgrade/downgrade/removal, renewal quote, discount breakdown, coupon, Razorpay checkout, payment history, invoice number approval, Broker/Partload credits, usage-service credits and storage. Display list price, agreed base, each discount, taxable amount, GST and payable distinctly. Show trial/grace/expiry/suspension states. Do not imply auto-renewal in Phase 1.

## 9. Notification centre
Bell/unread count, recent dropdown, dedicated centre, category/date/read filters, mark individual/all, authorized deep links. Do not leak protected record data in previews. Mandatory subscription notifications cannot be disabled.

## 10. Communications UI
Provider/channel configuration by authorized role. Template registry with variable validation, preview and approval. Message history: masked recipient, channel, template/version, provider reference/status, normalized status, credit impact. Usage: purchased, reserved, consumed, remaining. Clearly distinguish Submitted, Sent, Delivered, Read, Failed.

## 11. Asset management UI
Vehicle document expiry dashboard and history. Tyre inventory and vehicle layout positions; installation/removal timeline and mileage. Battery inventory/lifecycle. Maintenance calendar and due reminders. Spare parts stock/reorder/movement ledger. Job Card board and detail with labour, parts, docs, status, downtime and cost. Asset history remains accessible after vehicle retirement.

## 12. Documents
Drag/drop and browse. Display 2 MB cap and allowed formats. Upload progress and malware scan state. Failed scan/validation means unavailable. Authorized preview/download. Replace/delete requires permission and remark. Show version history. No public S3 links.

## 13. Print and accessibility
Print-friendly LR, invoice, Loading Report, Challan, Main Challan, DR and accounting vouchers. Use company logo/signature only if configured. Correct company/branch and numbering scope.
Keyboard navigation, visible focus, labels, contrast, text status, responsive desktop/tablet and critical mobile workflows, consistent date/currency/weight formatting. Avoid dense ungrouped forms.

## 14. Pre-coding design deliverables
Navigation map, screen inventory, role access map, workflow wireframes, field specs, table columns/filters, empty/loading/error states, print layouts, responsive rules and reusable component catalogue.
