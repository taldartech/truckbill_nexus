# TruckBill Platform — Full Living Implementation Memory

**Status:** Approved by Product Owner — Phase 0 governing baseline (living Memory; does not override PRD.md, Architecture.md or Rules.md).  
**Approval date:** 21 November 2026 | **Approval scope:** Phase 0 governing baseline.  
**Approval subject to:** Accepted Pending, Deferred and Proposed exceptions (PO-EXCEPT-001).

## Project
TruckBill by Taldar Tech Consultancy. Tagline “Transport. Billing. Simplified.” Unified Broker, OwnFleet and Partload. Independent new codebase. Legacy apps are not code/architecture/feature references; reserved for later migration.

## Technical baseline
Laravel 13; React + Inertia; PostgreSQL; Redis/Valkey; AWS; S3; Razorpay; Sanctum where appropriate; Reverb/evaluated equivalent; Terraform; GitHub Actions; Pest; Playwright; k6; CloudWatch; Sentry; OpenTelemetry. Proposed ECS Fargate, ALB, CloudFront/WAF, RDS Multi-AZ, ElastiCache and SQS require validation. Modular monolith.

## Brand
Gradient #2FB36F→#2D8CFF. Light backgrounds. Official TruckBill logo. **Product UI font: Poppins** (DEC-007).

## Identity/access
Global identity, mutually exclusive Internal/Customer. No public self-registration. Exactly one Company Owner/company. Owner Panel Owner-only. Customer branch access explicit; Owner auto-access all company branches. Customer single active session across web/mobile; Internal Team multi-session. Internal/customer permissions separate. Last active Super Admin protected.  
**Email changes** only via authorized administration — no self-service email change (DEC-009).  
**No self-service account deletion** (DEC-006).  
**No hard delete or restore** for identities, companies, branches, business/financial/document/audit records (DEC-003). Use controlled deactivation or soft deletion where permitted.  
**Owner identity deactivation** (cannot log in) is distinct from **company deactivation**. Company deactivation: Branch Panel blocked for Owner and all company users; Owner Panel remains for company-level administrative and billing information for an active Owner; authorization must not blanket-deny Owner Panel; data preserved (DEC-002). Exhaustive Owner Panel actions while deactivated remain Pending.

## Global rules
Shared PostgreSQL tenancy. Company/branch scope server-enforced. Edit/delete remarks. Audit mutation atomic. Posted financial entries immutable and corrected by reversal. Access requires entitlement, feature, permission and business eligibility. Reports deferred until source workflows work.  
**Coding gate (DEC-004):** Phase 0 formal approval prerequisite is **satisfied** (PO-APPROVE-001, 21 November 2026). Implementation must follow `Phases.md` sequencing. Phase 1 may be planned under approved boundaries only when separately authorized by the Product Owner.  
**Phase numbering (DEC-001):** `Phases.md` is the sole authority. Phase 1 = engineering foundation. Initial manual Razorpay renewals = Phase 3.  
**Design gates:** Completing design artefacts was not mandatory for Phase 0 approval. Product-specific UI still requires the Design Deliverables Gate; Phase 1 technical UI shell only after separate Phase 1 authorization (`Design.md` §14; `Phases.md`).

## Modules
Enquiry; Unified Consignment/LR; Broker/Market-Hired; OwnFleet Trip; Partload; Accounting; Subscription; in-app notifications; SMS/WhatsApp/Email; Vehicle Documents; Tyres; Batteries; Maintenance; Spare Parts/Store; Workshop/Job Cards.  
**Excluded:** Partload Expense Management (DEC-005). OwnFleet expense management remains in scope.

## Masters
Party roles Customer, Consignor, Consignee, Transporter, Driver, General Vendor, Pump Vendor. Truck Owner merged into Transporter. Party/Vehicle/Bank scope follows Data Visibility. Station Master shared across Owner's companies. Goods Type, Truck Type, Tyre Layout, Document Type, Expense Category and Package Type Owner-specific. Payment modes centrally seeded.

## Subscription facts
15-day first-company trial per Owner. Paid grace configurable 10 days, view-only. One common renewal date. No base fee. Bundle 0/10/15%; tenure monthly0, quarterly5, half-yearly8, yearly15, 3-year full advance25, 3-year annual instalments20; eligible renewal5; monetary cap30%; extra-month coupons excluded. Actual-day proration. **Initial manual Razorpay renewals in Phase 3**; no auto-renew for that delivery. Payment activates independently of invoice-number approval. No in-system refunds. Company-bound contract. Ownership transfer does not change it. Unused Broker/Partload and service credits retained indefinitely in separate ledgers. Storage shared company-wide. Detailed commercial rules: Rules §15; product summary: PRD §19.

## Operations
One OwnFleet LR=one Trip. One vehicle/driver per Trip; substitute trip-only. Market-Hired uses Lorry Hire/vouchers. Partload Paid/To Pay/TBB/FOC. One DR per eligible GR. Shared POD/Memo may attach multiple Bilties. Linked Bilty minimum two.

## Assets/accounting
Tyres/batteries individually tracked with installation/removal history. Maintenance preventive/corrective. Inventory weighted average cost. Job Card parts issue references stock movement; avoid duplicate expenses. Central double-entry accounting; posted vouchers immutable; corrections by reversal. Accounting mappings require accountant/tax-advisor validation before production.

## Files
2 MB/file; JPG/JPEG/PNG/WEBP/PDF/DOC/DOCX/XLS/XLSX. Private S3, server validation, malware scan, authorized access. Retained soft-delete attachments count toward storage. Failed upload no quota. Replacement must succeed before old cleanup.

## Agent protocol
Before work, read all six governing docs and ADRs. Identify acceptance criteria, dependencies, permissions, entitlements, tenant scope, audit/deletion, accounting, documents and events. Add tests. Do not invent policy where specified. Do not convert Pending/Deferred/Proposed items to Confirmed without Product Owner decision. Record assumptions. Ask product owner only for consequential decisions. Phase 0 is approved; do not start Phase 1 implementation until the Product Owner issues a separate Phase 1 authorization.

## Update protocol
Record date, fact, reason, affected modules and ADR/PR/issue when technology/version, schema, API, infrastructure, major constraint, approved requirement change, migration or release milestone changes. Business policy changes require PRD/Rules update and approval first. Memory must not silently override governing docs.

## Current status
- Phase 0 is **formally approved and closed** (PO-APPROVE-001, 21 November 2026).
- The six governing documents and supporting Phase 0 engineering package are the **approved implementation baseline**, subject to PO-EXCEPT-001.
- PO-EXCEPT-001 **accepted**: Pending, Deferred and Proposed items remain open with existing statuses (not reclassified).
- PO-HIST-001 **accepted**: historical discussion residual risk accepted; no additional sampling review required.
- DEC-004 Phase 0 approval prerequisite is **satisfied**. Phase 1 engineering foundation may be **planned** under approved phase boundaries; **Phase 1 implementation starts only on separate Product Owner authorization**.
- Design deliverables remain **Not Started** and are required before product-specific UI (Design Deliverables Gate).
- Legacy migration remains future work.
- Authoritative consistency snapshot: `docs/engineering/PHASE_0_FINAL_CONSISTENCY_REPORT.md`. Approval record: `docs/engineering/PHASE_0_APPROVAL_READINESS_REPORT.md`.

### Phase 0 decision log (documentation)
| Date | Decision | Source |
|---|---|---|
| 2026-11-21 | Phases.md is sole phase-numbering authority; Razorpay manual renewals = Phase 3 | DEC-001 |
| 2026-11-21 | Deactivated company: Branch Panel blocked; Owner Panel remains | DEC-002 |
| 2026-11-21 | No hard delete or restore | DEC-003 |
| 2026-11-21 | No application coding until Phase 0 formal approval (prerequisite now satisfied) | DEC-004 |
| 2026-11-21 | Partload Expense Management excluded | DEC-005 |
| 2026-11-21 | No self-service account deletion | DEC-006 |
| 2026-11-21 | Poppins is product UI font | DEC-007 |
| 2026-11-21 | Version-control `.cursor/rules/truckbill-project.mdc` | DEC-008 |
| 2026-11-21 | Email changes via authorized administration only | DEC-009 |
| 2026-11-21 | Formal Phase 0 approval — governing baseline established | PO-APPROVE-001 |
| 2026-11-21 | Pending/Deferred/Proposed exceptions accepted; statuses unchanged | PO-EXCEPT-001 |
| 2026-11-21 | Historical discussion residual risk accepted; no sampling review | PO-HIST-001 |
