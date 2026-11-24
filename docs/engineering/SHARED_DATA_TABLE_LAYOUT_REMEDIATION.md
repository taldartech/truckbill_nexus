# Shared Data Table Layout Remediation

**Status:** Implemented  
**Date:** 24 November 2026  
**Authority:** Product Owner browser remediation assignment (data-table correction)

## Root cause

`ResponsiveDataTable` used `table-fixed` and applied `w-[28%]` to every `priority === 0` column. Registers with two or more priority-zero columns (for example Bilty No. and Date) consumed ~56% of width and compressed operational columns. Blanket cell truncation worsened readability.

## Correction

- Separated **visibility priority** from **sizing** (`width`, `minWidth`, `maxWidth`, `wrap`, `align`).
- Switched to `table-auto` with controlled `overflow-x-auto` on the table container (not the application page).
- Truncation limited to short-id columns or explicit truncate class; wrap columns use `break-words`.
- Action column uses a compact horizontal button group with a sensible minimum width.

## Consumers inspected

Bilty Register, Lorry Hires, Broker Registers, Transporter Vouchers, Daily Payments, Transporter Balances, Customer Invoices / Receipts, Enquiries, Party Master, Vehicle Master, Internal CRM, Owner Panel — shared component fix applies without per-register width hardcoding except where callers set explicit sizing (Bilty Register).

## Verification

- Pest: Bilty register operations + RBAC trips redirect.
- Playwright visual captures under `storage/visual-certification/bilty-lorry-hire/`.
