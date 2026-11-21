# TruckBill Platform — Full Architecture Specification

**Status:** Draft for review. Infrastructure and provider choices remain subject to validation.

## 1. Technology and deployment
Laravel 13; React + Inertia; PostgreSQL; Redis/Valkey; AWS; private S3; Razorpay; Sanctum where appropriate; Reverb/evaluated equivalent; Terraform; GitHub Actions; Pest, Playwright, k6; CloudWatch, Sentry, OpenTelemetry. Modular monolith with asynchronous workers; no premature microservices.
Proposed AWS: Route 53, CloudFront, WAF, ALB, ECS Fargate, RDS PostgreSQL Multi-AZ, ElastiCache, S3, SQS where useful, Secrets Manager/KMS, CloudWatch. Mumbai region is a candidate, not confirmed. Evaluate PHP-FPM vs Octane/FrankenPHP and RDS Proxy. Size only after workload tests.

## 2. Bounded contexts
Identity/Access; Company/Branch; CRM/Enquiry; Masters; Consignment/LR; Broker; OwnFleet Operations; Partload; Accounting; Subscription/Billing; Notifications; Communications; Asset Management; Inventory/Workshop; Documents; Audit; Reporting. Each owns application services, state and validation. Cross-context writes use explicit application interfaces/domain events, not arbitrary direct table writes.

## 3. Tenancy and authorization
Shared PostgreSQL database. Every tenant record has company_id; branch-specific records have branch_id; Owner-level masters use explicit owner_id. Internal Team has separate authorization. Enforce tenant scope in policies/query layers and database constraints. Evaluate PostgreSQL RLS as defence in depth, never sole control. Background jobs carry explicit tenant/branch context. Cache keys include tenant scope. Exports, downloads, APIs, real-time channels and error responses enforce same boundaries.
Authorization sequence: identity type/active status → company status → branch membership → subscription entitlement → feature availability → role permission → business-state eligibility. Owner Panel requires active Owner designation.

## 4. Identity/session model
Global identity with mutually exclusive Internal/Customer type. Separate role/permission domains. Company Owner modeled separately from membership. Branch memberships explicit. Customer single-active-session across web/mobile uses session/device token registry and revocation; Internal sessions may coexist. Password change, deactivation and ownership-transfer rules trigger specified revocations.

## 5. Data conventions
Relational schema, UUID/opaque public IDs, foreign keys, tenant-aware unique constraints, decimal/numeric money/rates/weights, currency code (INR initial), UTC timestamps with company timezone for business interpretation, soft-delete only where allowed, append-only audit and financial history, versioned contract/tax snapshots, tenant-aware indexes, append-only usage ledger, outbox and idempotency records. Cached counters are never the financial/entitlement source of truth.

## 6. Transactional events and jobs
Business mutation and outbox event commit atomically. Dispatcher publishes; consumers are idempotent; transient failures retry with bounded backoff; exhausted failures enter dead-letter/exception handling. Retain attempts, result and correlation ID. Event examples: EnquiryCreated/Assigned/Converted; LRCreated/Dispatched/Delivered/Cancelled; InvoiceIssued/PaymentRecorded; TripCompleted/ExpenseRecorded; LorryHireCreated/VoucherPaid; ChallanCreated/InwardRegistered/DRCreated; SubscriptionPurchased/Renewed/Expired/Suspended; DocumentUploaded/Expiring; InventoryReceived/Issued/Adjusted; JobCardOpened/Completed. Event payloads carry identifiers/minimum data; consumers reload source and revalidate.

## 7. Accounting bounded context
Chart of Accounts, Account Groups, Ledgers, Voucher Types, Voucher Headers/Lines, Cost Centres, Accounting Periods, Tax Snapshots, Reconciliation and Reversal links. Every posted voucher balances; posted records immutable. Reversal references original. Idempotency identity includes source type, source ID and posting purpose. Same-database operational mutation and accounting posting should be atomic where practical. Async posting must expose pending state and reconcile; never silently report financial completion while required posting is unresolved.

## 8. Subscription bounded context
Catalogue/price books/versions, plans/add-ons, entitlements, company contract, subscription items, common renewal date, immutable contract snapshots/amendments, usage ledger, quota counters, service-specific prepaid wallets, coupons/discounts, Razorpay orders/payments/webhooks, invoice draft/number approval/issued invoice, cancellation and suspension history. Usage ledger append-only; counters rebuildable. Credit reservation prevents concurrent overspend and is idempotent.

## 9. Communications bounded context
Provider adapters per channel; template registry/versioning; recipient and consent resolution; message queue; provider submission; callback processing; delivery history; provider status separate from normalized status; credit reservation/settlement; provider health/rate limits. Failover only where sender, approved template, route and compliance remain valid.

## 10. Document architecture
Private S3; no public object ACL. Authorized short-lived URL or authenticated proxy. Validate extension, MIME/content signature and 2 MB cap server-side. Malware scan before availability. Metadata includes tenant, branch, owner record, version, size, checksum, uploader, timestamps and scan status. Retained attachments count storage. Replacement stores/validates new version before old cleanup. Cleanup checks retention/legal hold.

## 11. Inventory and assets
Vehicle, tyre and battery individually tracked. Installation/removal are lifecycle records. Inventory movement ledger drives on-hand quantities. Weighted average cost; historical issue cost retained. Job Card consumption references stock issue. Maintenance and Job Card share cost references to avoid duplicate expense. Inventory and accounting movement atomic or reconciled.

## 12. API contracts
Versioned endpoints; request schema validation; DTO/resource response boundaries; consistent error envelope; rate limits by user/tenant/endpoint; idempotency keys for payments, financial posting and external retries. Website enquiry webhook token-authenticated, rate-limited, replay-protected. Provider webhooks verify signatures/timestamps. Never trust client company_id/branch_id without membership resolution. API docs versioned.

## 13. Security
TLS, encryption at rest, KMS/Secrets Manager, secure password hashing, CSRF/XSS/SQLi/mass-assignment protection, CSP/security headers, malware scanning, private object storage, signed webhook verification/replay prevention, rate limits, least privilege, session revocation, privileged-action audit and dependency scanning. Internal/customer trust boundaries explicit. Mask sensitive data in logs and notification previews.

## 14. Performance and observability
5,000 concurrent users is measured target. Define workload personas, RPS, read/write mix, queue load, data volume, p95/p99 latency and error rate. Profile query plans, indexes, lock contention, N+1, DB connections and queue lag. Paginate large data. Cache safe reference data with tenant-aware keys/invalidation; cache is not authorization source. Queue exports, scanning, messages. Metrics: API latency/errors, DB connections, queue depth/age, webhook failures, pending accounting, payment reconciliation, storage mismatch and provider health. Structured logs/traces with correlation IDs. Runbooks/alerts.

## 15. Backup, recovery and release
Automated DB backup/PITR; S3 versioning/lifecycle where appropriate; restore drills; define RPO/RTO before production; queue replay/reconciliation. Disaster recovery region strategy requires validation.
CI: formatting/static analysis, unit, feature/integration, tenant authorization, migration checks, frontend build, Playwright critical workflows, dependency/security scan, staging smoke, controlled rollout and rollback. Support macOS/Linux/Windows dev environments.
ADRs record context/options/decision/consequences/date/owner/status/migration. Memory.md records implementation facts but cannot silently override business rules.
