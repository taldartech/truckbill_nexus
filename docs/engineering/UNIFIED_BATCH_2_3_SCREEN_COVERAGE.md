# Unified Batch 2 & 3 — Screen Coverage Register

**Date:** 24 November 2026  
**Status:** Living register — not all screens Complete

| Screen ID         | Business operation     | Legacy Broker           | Legacy OwnFleet    | Common UI        | Market-specific     | Own-specific       | Nexus source                  | Visual  | Interaction    | Status  |
| ----------------- | ---------------------- | ----------------------- | ------------------ | ---------------- | ------------------- | ------------------ | ----------------------------- | ------- | -------------- | ------- |
| UB-BILTY-CREATE   | Create Bilty           | `lorry_receipts/create` | OwnFleet GR create | Shared form      | Pay To, Hire CTA    | Driver, Trip CTA   | `lorry-receipts/lr-form.tsx`  | Partial | Aligned core   | Partial |
| UB-BILTY-INDEX    | Bilty Register         | LR index                | Trip/GR lists      | Unified register | Hire actions        | Expenses actions   | `lorry-receipts/index.tsx`    | Partial | Aligned        | Partial |
| UB-HIRE-CREATE    | Create Lorry Hire      | Freight Booking         | N/A                | —                | Payee, hire charges | —                  | `lorry-hires/create.tsx`      | Partial | Aligned        | Partial |
| UB-HIRE-SHOW      | Hire detail            | FB show                 | N/A                | —                | Voucher, adjust     | —                  | `lorry-hires/show.tsx`        | Partial | Aligned        | Partial |
| UB-VOUCHER-CREATE | Voucher Payment modal  | `#voucherPaymentModal`  | N/A                | Combobox         | Register selection  | —                  | `voucher-payment-dialog.tsx`  | Yes     | Aligned        | Yes     |
| UB-LINKED-CREATE  | Linked Bilty group     | linked create           | N/A                | Two-panel        | Market vehicle      | —                  | `linked-bilties/create.tsx`   | Partial | Aligned        | Partial |
| UB-TRIP-SHOW      | Trip / Manage Expenses | N/A                     | Trip detail        | Trip workspace   | —                   | Expenses, profit   | `trips/show.tsx`              | Partial | Aligned        | Partial |
| UB-INVOICE        | Customer invoice       | Invoice                 | Trip invoice       | Unified          | Eligible market LRs | Eligible own trips | broker/ownfleet invoice pages | Partial | Aligned domain | Partial |
| UB-RECEIPT        | Customer receipt       | Receipt                 | Receipt            | Unified          | —                   | —                  | existing receipt UI           | Partial | Aligned domain | Partial |

**Evidence notes:** Legacy browser launch incomplete this session — Broker/OwnFleet rows are primarily source-verified. Nexus Bilty create screenshots under `storage/visual-certification/batch2-uiux/`.
