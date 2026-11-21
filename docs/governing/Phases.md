# TruckBill Platform — Full Delivery Phases

**Hard gate:** No application coding until all six governing documents are approved.

## Principles
Independent codebase. Legacy app reserved for later migration only. Modular monolith. Vertical slices with tests. Reports deferred until source workflows work. Every phase enforces tenant, permission, audit, accounting and subscription rules.

## Phase 0 — Documentation
Approve PRD, Architecture, Rules, Design, Phases, Memory. Create traceability matrix, ADR/change-control, risk register and test strategy. Exit: all six reviewed and approved.

## Phase 1 — Engineering foundation
Repository, Laravel 13/React/Inertia/PostgreSQL, macOS/Linux/Windows setup, CI, static analysis, Pest, frontend tests, tenant context, audit, file service/S3/malware scanning, outbox, idempotency, queues, observability, UI shell. Exit: CI green; tenant isolation, audit atomicity, upload and queue reliability tested; non-production deployment.

## Phase 2 — Identity/company/access
Global identity, internal/customer separation, auth, customer single session, company/branch, Owner/Branch Panels, membership, branch roles, Permission Masters, ownership transfer, deactivation/suspension, dashboards. Exit: cross-company/branch negative tests, Owner isolation, session revocation, immediate permission invalidation.

## Phase 3 — Masters/documents/subscription
Party/Vehicle/Bank/Station/other masters; GST/TDS masters; common document service; catalogue, contracts, entitlements, trial, capacity, quotas, credits, Razorpay, invoice-number approval, Owner billing. Exit: pricing calculation, webhook idempotency, entitlement, storage/credit reconciliation, immutable contracts.

## Phase 4 — Enquiry and unified LR
Website webhook, manual enquiry, assignment, follow-up, quotation broadcast/entry/selection, whole conversion, unified LR, goods/EWB/numbering. Exit: duplicate conversion prevention, scope, numbering concurrency, edit/cancel/delete dependency tests.

## Phase 5 — OwnFleet operations
Trip from LR, multi-point allocations, dispatch/completion, odometer, expenses/diesel, POD/docs, invoice/payments. Exit: allocation, odometer, profit, invoice and accounting tests.

## Phase 6 — Broker/Market-Hired
Lorry Hire, TDS engine, transporter vouchers, bulk payment, POD/Memo, delivery adjustments, invoice, Linked Bilty/group payment. Exit: TDS snapshots, balances, voucher allocation, cancellation dependencies and accounting reconciliation.

## Phase 7 — Partload
Bilty/payment types, Loading Report, Challan/Main Challan, Inward, QR/Exchange Code, forwarding, DR, bulk invoice. Exit: exact formula, unique DR/GR, forwarding chain, cash/bank/party ledger and accounting reconciliation.

## Phase 8 — Accounting completion
Chart of Accounts, ledgers, voucher UI, auto-posting coverage, opening balances, bank reconciliation, Trial Balance, P&L, Balance Sheet and other reports. Exit: debit=credit, reversals, closed periods, source reconciliation.

## Phase 9 — Notifications and communications
In-app centre, recipient rules, templates, SMS/WhatsApp/Email adapters, callbacks, service credits, usage and failure queues. Exit: deduplicated delivery, accurate provider states, consent and credit reservation tests.

## Phase 10 — OwnFleet assets
Vehicle Documents, Tyres, Batteries, Maintenance, Spare Parts/Store, Workshop/Job Cards, uploads, inventory and accounting. Exit: lifecycle history, weighted-average valuation, stock reconciliation, no duplicate expenses.

## Phase 11 — Reports, migration and certification
Operational, financial, subscription, usage and audit reports. Legacy data migration only after new platform ready. Security, load, backup/restore, recovery and release certification. Exit: migration reconciliation signoff; tenant tests; financial reconciliation; 5,000 concurrent workload with documented RPS/latency; restore drill; no unresolved critical/high security issues; rollback/support runbooks.

## Gates for every phase
Traceability; unit/feature/integration tests; tenant/authorization; audit/deletion; idempotency/retry; accounting; entitlement; accessibility; observability; documentation and runbooks.

## Change control
Identify impacted requirements/modules, explain consequences, update governing documents, create ADR for architecture, update Memory with implemented facts, add tests, and obtain approval for material policy/customer/financial/irreversible changes.
