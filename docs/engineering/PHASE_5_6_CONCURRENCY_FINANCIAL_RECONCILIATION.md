# Phase 5 & 6 Concurrency and Financial Reconciliation

**Date:** 24 November 2026  
**Commit:** `657db4f3dd2d675eb7bc2214282f10b4f5df6fa1`

## Invariants covered

- OwnFleet: `Trip Profit = Agreed Freight + Additional Charges − Trip Expenses`
- OwnFleet / Broker customer invoices: `Invoice Total = Settlements + Outstanding`
- Broker hire: paid + outstanding − book delta reconciles to net payable
- Adjust-in-advance book entries do not double-count

## Concurrency approach

Repository pattern uses `lockForUpdate` + nested transactions (same as Phase 4 numbering). Multi-process second PDO is not present; tests document and exercise the row-lock serialization path for:

- Parallel broker invoice creation on the same LR
- Serialized OwnFleet / Broker payments
- Voucher reservation / overpay rejection
- Linked Bilty bulk voucher overpay

Suite: `tests/Feature/Financial/FinancialInvariantsTest.php`, `InvoiceFinancialConcurrencyTest.php` — 16 passed with scalability suite on PostgreSQL.
