# TruckBill Platform — Full Delivery Phases

**Status:** Approved by Product Owner — Phase 0 governing baseline.  
**Approval date:** 21 November 2026 | **Approval scope:** Phase 0 governing baseline.  
**Approval subject to:** Accepted Pending, Deferred and Proposed exceptions (PO-EXCEPT-001).  
**DEC-004:** Phase 0 formal approval prerequisite is **satisfied** (PO-APPROVE-001). Implementation must still follow this document’s phase sequencing. **PO-P1-AUTH APPROVED** (21 November 2026) authorizes Phase 1 Engineering Foundation under `docs/implementation/PHASE_1_EXECUTION_PLAN.md`. **PO-P2-AUTH APPROVED** (22 November 2026) authorizes Phase 2 Identity / Company / Access under `docs/implementation/PHASE_2_EXECUTION_PLAN.md`. **PO-P2-FINAL-ACCEPT APPROVED WITH DOCUMENTED EXCEPTIONS** (22 November 2026): Phase 2 formally closed. **PO-P3-AUTH APPROVED** (22 November 2026) authorized Phase 3 under `docs/implementation/PHASE_3_EXECUTION_PLAN.md`. **PO-P3-FINAL-ACCEPT APPROVED — OPTION A** (22 November 2026): Phase 3 **FORMALLY ACCEPTED WITH DOCUMENTED EXCEPTIONS** against baseline `68d11b1ecc267e91d3adb37825d94def71334a3a` (Actions #60 SUCCESS) — see `docs/implementation/PHASE_3_ACCEPTANCE_RECORD.md`. Phase 0–3 closure alone does not authorize later phases; **Phase 4+ requires separate Product Owner authorization**. Deferred Phase 3 gates remain open (AUD-CON-006, G-PAY webhook evidence, PO-P3-PAY-PROD, issuer/GST, ADR-010, paid renewal reminders Option D Deferred, production PDF).  
**Phase numbering authority:** This document is the single authoritative source for delivery phase numbers (DEC-001 / ADR-011). Do not overload “Phase 1” to mean subscription billing.

## Principles

Independent codebase. Legacy app reserved for later migration only. Modular monolith. Vertical slices with tests. Reports deferred until source workflows work. Every phase enforces tenant, permission, audit, accounting and subscription rules.

## Phase 0 — Documentation

Finalize and obtain formal approval of PRD, Architecture, Rules, Design, Phases, Memory. Expand traceability matrix, ADR/change-control, risk register and test strategy. Maintain the design deliverables register (`docs/implementation/PHASE_0_DELIVERABLES.md`) with accurate Not Started / Pending / Completed status.  
**Design deliverables are not a mandatory Phase 0 approval exit criterion.** Missing design artefacts may remain Not Started at Phase 0 approval.  
Exit: all six governing documents formally approved by the product owner; critical conflicts resolved or explicitly deferred; Phase 0 finalization / consistency reports accepted for review outcomes.

**Phase 0 closure (recorded):** Formally approved and closed on **21 November 2026** (PO-APPROVE-001). Exceptions accepted under PO-EXCEPT-001. Historical discussion residual risk accepted under PO-HIST-001 (no additional sampling review required). The six governing documents and supporting Phase 0 engineering package are the approved implementation baseline, subject to those exceptions.

## Phase 1 — Engineering foundation

Repository, Laravel 13/React/Inertia/PostgreSQL, macOS/Linux/Windows setup, CI, static analysis, Pest, frontend tests, tenant context, audit, file service/S3/malware scanning, outbox, idempotency, queues, observability, and a **technical UI shell**. Exit: CI green; tenant isolation, audit atomicity, upload and queue reliability tested; non-production deployment.  
**Note:** Phase 1 does **not** include Razorpay subscription billing. Billing is Phase 3.

### Phase 1 technical UI shell vs product UI (Design Deliverables Gate)

Phase 0 approval satisfies the DEC-004 approval prerequisite. Phase 1 implementation, including the technical UI shell, requires separate explicit Product Owner authorization. When so authorized, Phase 1 may implement a **technical UI shell** only: application chrome/layouts, shared non-product scaffolding, auth surfaces already in the starter (aligned to DEC-006/009), design-token plumbing, and empty/placeholder shells needed to prove tenant/authz wiring.

**Design Deliverables Gate (separate from Phase 0 approval):** Product-specific screens — including Owner Panel / Branch Panel product information architecture, operational workflows, billing product UI, and other product module screens — must not be implemented until the required design artefacts for those screens are produced and reviewed (`Design.md` §14; `PHASE_0_DELIVERABLES.md`). Do not treat “UI shell” as permission to build product workflows early.

## Phase 2 — Identity/company/access

Global identity, internal/customer separation, auth, customer single session, company/branch, Owner/Branch Panels, membership, branch roles, Permission Masters, ownership transfer, deactivation/suspension, dashboards. Exit: cross-company/branch negative tests, Owner isolation, session revocation, immediate permission invalidation.

## Phase 3 — Masters/documents/subscription

Party/Vehicle/Bank/Station/other masters; GST/TDS masters; common document service; catalogue, contracts, entitlements, trial, capacity, quotas, credits, **initial manual Razorpay renewals** (no auto-renew), invoice-number approval, Owner billing. Exit: pricing calculation, webhook idempotency, entitlement, storage/credit reconciliation, immutable contracts.

**Phase 3 closure (recorded):** **FORMALLY ACCEPTED WITH DOCUMENTED EXCEPTIONS** on **22 November 2026** (**PO-P3-FINAL-ACCEPT**, Option A). Accepted baseline `68d11b1ecc267e91d3adb37825d94def71334a3a` (Actions #60 SUCCESS). Exceptions EX-01…EX-07 remain open gates (AUD-CON-006 Pending; G-PAY webhook evidence Open; PO-P3-PAY-PROD Pending; issuer/GST Pending; ADR-010 Pending; paid renewal reminders Option D Deferred; production PDF Deferred). Production deployment and Phase 4 implementation are **not** approved by Phase 3 acceptance. Record: `docs/implementation/PHASE_3_ACCEPTANCE_RECORD.md`.

## Phase 4 — Enquiry and unified LR

Website webhook, manual enquiry, assignment, follow-up, quotation broadcast/entry/selection, whole conversion, unified LR, goods/EWB/numbering. Exit: duplicate conversion prevention, scope, numbering concurrency, edit/cancel/delete dependency tests.  
**Kickoff package (22 November 2026):** Planning artefacts under `docs/implementation/PHASE_4_*.md`. Kickoff tip `193e6dc68b2509e90de4aa2990b1136260e80746`.  
**Authorization status:** **PO-P4-AUTH APPROVED**; **PO-P4-BATCH-B-AUTH APPROVED** (23 November 2026) — see `PHASE_4_AUTHORIZATION_RECORD.md`. Batch A complete; Batch B backend authorized. **PO-P4-DESIGN-FORMAL** and **PO-P4-CNV-CARDINALITY** remain **Pending**. No product UI until design formal approval.

## Phase 5 — OwnFleet operations

Trip from LR, multi-point allocations, dispatch/completion, odometer, expenses/diesel, POD/docs, invoice/payments. Exit: allocation, odometer, profit, invoice and accounting tests.  
**Note:** OwnFleet expense management remains in scope. Partload Expense Management remains excluded (DEC-005).

## Phase 6 — Broker/Market-Hired

Lorry Hire, TDS engine, transporter vouchers, bulk payment, POD/Memo, delivery adjustments, invoice, Linked Bilty/group payment. Exit: TDS snapshots, balances, voucher allocation, cancellation dependencies and accounting reconciliation.

## Phase 7 — Partload

Bilty/payment types, Loading Report, Challan/Main Challan, Inward, QR/Exchange Code, forwarding, DR, bulk invoice. **Partload Expense Management is out of scope.** Exit: exact formula, unique DR/GR, forwarding chain, cash/bank/party ledger and accounting reconciliation.

## Phase 8 — Accounting completion

Chart of Accounts, ledgers, voucher UI, auto-posting coverage, opening balances, bank reconciliation, Trial Balance, P&L, Balance Sheet and other reports. Exit: debit=credit, reversals, closed periods, source reconciliation. Accounting mappings remain subject to accountant/tax-advisor validation.

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
