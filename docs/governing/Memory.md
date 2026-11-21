# TruckBill Platform — Full Living Implementation Memory

**Status:** Initial handover baseline. Update continuously. Does not override PRD.md, Architecture.md or Rules.md.

## Project
TruckBill by Taldar Tech Consultancy. Tagline “Transport. Billing. Simplified.” Unified Broker, OwnFleet and Partload. Independent new codebase. Legacy apps are not code/architecture/feature references; reserved for later migration.

## Technical baseline
Laravel 13; React + Inertia; PostgreSQL; Redis/Valkey; AWS; S3; Razorpay; Sanctum where appropriate; Reverb/evaluated equivalent; Terraform; GitHub Actions; Pest; Playwright; k6; CloudWatch; Sentry; OpenTelemetry. Proposed ECS Fargate, ALB, CloudFront/WAF, RDS Multi-AZ, ElastiCache and SQS require validation. Modular monolith.

## Brand
Gradient #2FB36F→#2D8CFF. Light backgrounds. Official TruckBill logo.

## Identity/access
Global identity, mutually exclusive Internal/Customer. No public self-registration. Exactly one Company Owner/company. Owner Panel Owner-only. Customer branch access explicit; Owner auto-access all company branches. Customer single active session across web/mobile; Internal Team multi-session. Internal/customer permissions separate. Last active Super Admin protected.

## Global rules
Shared PostgreSQL tenancy. Company/branch scope server-enforced. No hard delete or restore. Edit/delete remarks. Audit mutation atomic. Posted financial entries immutable and corrected by reversal. Access requires entitlement, feature, permission and business eligibility. Reports deferred until source workflows work. No coding before six docs approved.

## Modules
Enquiry; Unified Consignment/LR; Broker/Market-Hired; OwnFleet Trip; Partload; Accounting; Subscription; in-app notifications; SMS/WhatsApp/Email; Vehicle Documents; Tyres; Batteries; Maintenance; Spare Parts/Store; Workshop/Job Cards.

## Masters
Party roles Customer, Consignor, Consignee, Transporter, Driver, General Vendor, Pump Vendor. Truck Owner merged into Transporter. Party/Vehicle/Bank scope follows Data Visibility. Station Master shared across Owner's companies. Goods Type, Truck Type, Tyre Layout, Document Type, Expense Category and Package Type Owner-specific. Payment modes centrally seeded.

## Subscription facts
15-day first-company trial per Owner. Paid grace configurable 10 days, view-only. One common renewal date. No base fee. Bundle 0/10/15%; tenure monthly0, quarterly5, half-yearly8, yearly15, 3-year full advance25, 3-year annual instalments20; eligible renewal5; monetary cap30%; extra-month coupons excluded. Actual-day proration. Razorpay manual Phase 1; no auto-renew. Payment activates independently of invoice-number approval. No in-system refunds. Company-bound contract. Ownership transfer does not change it. Unused Broker/Partload and service credits retained indefinitely in separate ledgers. Storage shared company-wide.

## Operations
One OwnFleet LR=one Trip. One vehicle/driver per Trip; substitute trip-only. Market-Hired uses Lorry Hire/vouchers. Partload Paid/To Pay/TBB/FOC. One DR per eligible GR. Shared POD/Memo may attach multiple Bilties. Linked Bilty minimum two.

## Assets/accounting
Tyres/batteries individually tracked with installation/removal history. Maintenance preventive/corrective. Inventory weighted average cost. Job Card parts issue references stock movement; avoid duplicate expenses. Central double-entry accounting; posted vouchers immutable; corrections by reversal.

## Files
2 MB/file; JPG/JPEG/PNG/WEBP/PDF/DOC/DOCX/XLS/XLSX. Private S3, server validation, malware scan, authorized access. Retained soft-delete attachments count toward storage. Failed upload no quota. Replacement must succeed before old cleanup.

## Agent protocol
Before work, read all six governing docs and ADRs. Identify acceptance criteria, dependencies, permissions, entitlements, tenant scope, audit/deletion, accounting, documents and events. Add tests. Do not invent policy where specified. Record assumptions. Ask product owner only for consequential decisions.

## Update protocol
Record date, fact, reason, affected modules and ADR/PR/issue when technology/version, schema, API, infrastructure, major constraint, approved requirement change, migration or release milestone changes. Business policy changes require PRD/Rules update and approval first. Memory must not silently override governing docs.

## Current status
Full requirements-preservation draft prepared. Not approved. No application code authorized. Legacy migration is future work.
