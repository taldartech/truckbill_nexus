# Nexus-Exclusive Requirements Audit (Phases 1–6)

**Audit date:** 24 November 2026  
**Nexus HEAD:** `3aa5bfe5970721d3fff5632e6a1c62b23c456784` (+ demo seeder Rules §9 fix)  
**Phase authority:** `docs/governing/Phases.md` (DEC-001)  
**Scope:** Capabilities **not** represented as parity rows in legacy Broker/OwnFleet apps — unified platform, cross-module, or redesign-only features through Phase 6.  
**Status legend:** **Complete** · **Partial** · **Missing** · **Deferred** · **Intentional** · **PO Decision** · **Not Verified**

Governing PRD, Rules, Architecture, and PO authorization records **override** this inventory when they conflict.

---

## Executive summary

| Phase | Theme                              | vs governing docs   | Primary residuals                                                                           |
| ----- | ---------------------------------- | ------------------- | ------------------------------------------------------------------------------------------- |
| 1     | Engineering foundation             | **Mostly Complete** | Playwright/k6 CI depth; non-prod deploy Deferred                                            |
| 2     | Identity / company / access        | **Mostly Complete** | PRD §7 company create fields Partial; Internal lifecycle UI Partial                         |
| 2A    | Internal CRM                       | **Mostly Complete** | Dashboard KPIs Partial; duplicate-lead policy Not Verified in browser                       |
| 3     | Masters / documents / subscription | **Partial**         | EX-01…07 gates; AUD-CON-006; production PDF/tax Deferred                                    |
| 4     | Enquiry / quotation / unified LR   | **Mostly Complete** | PO-P4-CNV-CARDINALITY still Pending in Phases.md; polish Partial                            |
| 5     | OwnFleet operations                | **Mostly Complete** | Active-trip + pickup/drop remove closed `3aa5bfe`; expense import Deferred; tax PDF Partial |
| 6     | Broker / market-hired              | **Mostly Complete** | Voucher/invoice amend & payout export → PO-LGC / Phase 11 Deferred                          |

**Nexus-exclusive row count:** 83 tracked capabilities (`NX-01`…`NX-84`, excluding NX-33 legacy replacement).  
**Classification (Nexus-only register):** Complete 46 · Partial 22 · Missing 5 · Deferred 5 · Intentional 2 · PO Decision 1 · Not Verified 0

---

## Phase 1 — Engineering foundation

| ID    | Capability                                 | Governing reference       | Evidence (indicative)             | Status   |
| ----- | ------------------------------------------ | ------------------------- | --------------------------------- | -------- |
| NX-01 | Modular monolith + PostgreSQL tenancy      | Architecture              | Migrations, `TenantContext`       | Complete |
| NX-02 | Laravel Fortify web auth (no Sanctum)      | ADR-006                   | Fortify config, auth pages        | Complete |
| NX-03 | Append-only audit + remarks                | ADR-003                   | `audit_entries`, Owner audit UI   | Complete |
| NX-04 | Private file service + malware scan hook   | Architecture              | `PrivateFileService`, files table | Complete |
| NX-05 | Outbox + idempotency for side effects      | Architecture              | Outbox jobs, idempotency keys     | Complete |
| NX-06 | CI: format, types, build, Pest, Larastan   | Phase 1 exit              | `.github/workflows`               | Complete |
| NX-07 | Technical UI shell (non-product)           | Phases §1                 | App chrome, layouts               | Complete |
| NX-08 | Playwright in CI / full E2E gate           | Phase 1 / 11              | Local runs; env-gated in CI       | Partial  |
| NX-09 | k6 load baseline (5k concurrent objective) | Phases §11 / Architecture | Smoke only                        | Deferred |
| NX-10 | Non-production deployment runbook executed | Phase 1 exit              | PO-P1-DEPLOY Deferred             | Deferred |

---

## Phase 2 — Identity / company / access

| ID    | Capability                                      | Governing reference   | Evidence                           | Status      |
| ----- | ----------------------------------------------- | --------------------- | ---------------------------------- | ----------- |
| NX-11 | Mutually exclusive Internal / Customer identity | TB-AUTH-001, ADR-005  | Identity type enforcement          | Complete    |
| NX-12 | Company Owner designation (protected)           | PRD §3                | Owner-only routes                  | Complete    |
| NX-13 | Owner Panel (subscription, branches, users)     | PRD §6                | `Owner*Controller` routes          | Complete    |
| NX-14 | Branch Panel operational shell                  | Architecture          | Branch middleware group            | Complete    |
| NX-15 | Branch membership + Permission Master RBAC      | Phase 2               | Permissions seed, policies         | Complete    |
| NX-16 | Customer single active **web** session          | ADR-006               | `customer_web_sessions`            | Complete    |
| NX-17 | Internal multi-session + security revocations   | ADR-006               | `WebSessionService`                | Complete    |
| NX-18 | Company / branch switcher                       | PO-P2-DESIGN-SWITCHER | Inertia switcher                   | Complete    |
| NX-19 | Branch CRUD + capacity entitlement              | PRD §7                | `OwnerBranchesController`          | Complete    |
| NX-20 | Membership invite / assign / deactivate         | Phase 2               | `OwnerUsersController`             | Complete    |
| NX-21 | Ownership transfer (service layer)              | P2-WS-7               | `OwnershipTransferService`         | Partial     |
| NX-22 | Internal SA company/identity lifecycle UI       | Phase 2               | Controllers exist; product UI thin | Partial     |
| NX-23 | Full PRD §7 company create (legal/GST/bank)     | PRD §7                | Name-only create path              | Partial     |
| NX-24 | Membership reactivation                         | Rules                 | Explicitly Deferred                | Deferred    |
| NX-25 | Mobile / token auth                             | ADR-006               | Not installed                      | Intentional |

---

## Phase 2A — Internal CRM & onboarding

| ID    | Capability                               | Governing reference  | Evidence                        | Status           |
| ----- | ---------------------------------------- | -------------------- | ------------------------------- | ---------------- |
| NX-26 | Lead CRUD + status transitions           | Phases §2A, PO-P2A-* | `CrmLeadController`             | Complete         |
| NX-27 | Lead assignment + follow-ups             | Same                 | assign / follow-up routes       | Complete         |
| NX-28 | Qualified lead → Customer conversion     | Same                 | convert action + identity reuse | Complete         |
| NX-29 | Support tickets lifecycle                | Same                 | CRM support controllers         | Complete         |
| NX-30 | Role-specific CRM dashboards             | PRD §6               | Dashboard pages                 | Partial          |
| NX-31 | Duplicate lead prevention (30-day)       | PO-P2A-DUPLICATE     | Domain rules + Pest             | Complete         |
| NX-32 | Onboarding → company + trial eligibility | Phases §2A           | Integrated with company create  | Partial          |
| NX-33 | Legacy Admin multi-app CRM               | —                    | Replaced by Nexus CRM           | Deprecated (N/A) |

---

## Phase 3 — Masters / documents / subscription

| ID    | Capability                                          | Governing reference     | Evidence                                 | Status      |
| ----- | --------------------------------------------------- | ----------------------- | ---------------------------------------- | ----------- |
| NX-34 | Unified Party + role types (transporter, driver, …) | Phase 3                 | `PartyController`                        | Complete    |
| NX-35 | Vehicle + ownership (OwnFleet vs Market)            | Phase 3/4               | `VehicleOwnership` enum                  | Complete    |
| NX-36 | Bank/Cash accounts per branch                       | Phase 3                 | Branch bank-cash UI                      | Complete    |
| NX-37 | Station master + default transporter                | Phase 3                 | Owner/Branch stations                    | Complete    |
| NX-38 | Owner catalogues (goods, truck types, expense cats) | Phase 3                 | Catalogue controllers                    | Complete    |
| NX-39 | GST/TDS platform tax masters                        | Rules §6                | Tax master services                      | Complete    |
| NX-40 | Document attachments (upload/replace/soft-delete)   | Phase 3                 | `DocumentAttachmentService`              | Complete    |
| NX-41 | Subscription catalogue + draft checkout (test)      | Phase 3, PO-P3-PAY-TEST | Owner subscription UI                    | Complete    |
| NX-42 | Entitlement + capacity resolver                     | Rules §15               | `SubscriptionEntitlementGate`            | Partial     |
| NX-43 | Immutable subscription contracts                    | Phase 3                 | Contract tables                          | Complete    |
| NX-44 | Manual Razorpay renewal (non-auto)                  | Phase 3                 | Renewal flow; **PO-P3-PAY-PROD Pending** | Partial     |
| NX-45 | AUD-CON-006 exact trial/grace matrix                | EX-01                   | **Pending** — do not invent              | PO Decision |
| NX-46 | Production tax invoice PDF                          | EX production PDF       | Sample layouts only                      | Deferred    |
| NX-47 | ADR-010 accounting postings                         | Pending ADR             | No GL                                    | Deferred    |
| NX-48 | Invoice number approval workflow                    | Phase 3                 | Owner approval path                      | Complete    |

---

## Phase 4 — Enquiry & unified LR

| ID    | Capability                                               | Governing reference   | Evidence                               | Status   |
| ----- | -------------------------------------------------------- | --------------------- | -------------------------------------- | -------- |
| NX-49 | Website enquiry webhook (secured)                        | Phase 4 Batch B       | `EnquiryWebhookController`             | Complete |
| NX-50 | Manual enquiry CRUD (Branch)                             | Phase 4               | `EnquiryController`                    | Complete |
| NX-51 | Assignment + follow-ups                                  | Phase 4               | assign / follow-up routes              | Complete |
| NX-52 | Quotation broadcast + entry + select                     | ADR-013               | quotation-broadcasts routes            | Complete |
| NX-53 | Whole enquiry conversion → LR(s)                         | Phase 4               | convert + preview                      | Complete |
| NX-54 | **PO-P4-CNV-CARDINALITY**                                | Phases.md **Pending** | Implementation exists; gate open       | Partial  |
| NX-55 | Unified LR domain (Broker / OwnFleet / Partload context) | Phase 4               | `LrWorkflowContext`, shared controller | Complete |
| NX-56 | LR goods rows + active uniqueness (DEC-003)              | Architecture          | Unique index + Pest                    | Complete |
| NX-57 | E-way bill on LR                                         | Phase 4               | `ewayBills` relationship               | Complete |
| NX-58 | Document number allocator (concurrency-safe)             | Architecture          | `DocumentNumberAllocator`              | Complete |
| NX-59 | Composite LR dependency guard (hire/trip)                | Rules §8/11           | `CompositeLrDependencyGuard`           | Complete |
| NX-60 | Enquiry product UI polish (follow-up complete, etc.)     | Certification notes   | Functional; UX gaps                    | Partial  |

---

## Phase 5 — OwnFleet (Nexus design — not in legacy trip model)

| ID    | Capability                                 | Governing reference | Evidence                           | Status      |
| ----- | ------------------------------------------ | ------------------- | ---------------------------------- | ----------- |
| NX-61 | Trip **only** from OwnFleet LR (1:1)       | PRD §11             | `store-from-lr`                    | Complete    |
| NX-62 | Multi-point pickups / drops / allocations  | Phase 5             | `TripMovementService`              | Complete    |
| NX-63 | Dispatch / complete + odometer             | Phase 5             | Trip status machine                | Complete    |
| NX-64 | Trip expenses + diesel accountability      | Rules §9            | `TripExpenseService`               | Complete    |
| NX-65 | Trip profit summary (server)               | Phase 5             | Service + tests                    | Complete    |
| NX-66 | Trip invoice: charge edit pre-payment      | PRD §12             | `updateCharges`                    | Complete    |
| NX-67 | Invoice payments immutable (no destroy)    | PRD §12             | No destroy route                   | Complete    |
| NX-68 | Vehicle trip date-window overlap guard     | Phase 5 exit        | **Not implemented**                | Missing     |
| NX-69 | Trip expense bulk import                   | Phase 5             | **Not implemented**                | Missing     |
| NX-70 | Pickup/drop edit after add                 | Operational need    | POST-only graph                    | Partial     |
| NX-71 | Unified customer invoice read facade       | Phase 5/6           | `UnifiedCustomerInvoiceReadFacade` | Complete    |
| NX-72 | LR Delivered **not** auto on trip complete | Approved exception  | Contract test                      | Intentional |

---

## Phase 6 — Broker / market-hired (Nexus design)

| ID    | Capability                                          | Governing reference | Evidence                        | Status   |
| ----- | --------------------------------------------------- | ------------------- | ------------------------------- | -------- |
| NX-73 | Lorry Hire from LR + TDS snapshots                  | Phase 6             | `LorryHire*` domain             | Complete |
| NX-74 | Transporter book balance ledger (redesign)          | Rules §11           | `TransporterBookBalanceService` | Complete |
| NX-75 | Transporter vouchers + bulk + daily payments        | Phase 6             | Broker voucher routes           | Complete |
| NX-76 | Broker delivery / memo / POD / adjustments          | Phase 6             | `BrokerDeliveryController`      | Complete |
| NX-77 | Broker customer invoice engine (distinct from trip) | PRD §13             | `BrokerInvoiceService`          | Complete |
| NX-78 | Linked Bilty group + proportional pay               | TB-BRK-003          | `LinkedBiltyGroupService`       | Complete |
| NX-79 | Broker operational registers hub                    | Phase 6             | `/broker/registers`             | Complete |
| NX-80 | Voucher amend / void after create                   | Legacy parity gap   | Status-only transitions         | Missing  |
| NX-81 | Bank payout export file                             | Treasury handoff    | No export route                 | Missing  |
| NX-82 | Broker invoice edit after issue                     | Correction policy   | Cancel-only                     | Missing  |
| NX-83 | Direct hire field update (vs adjustments-only)      | Phase 6             | Partial recalc path             | Partial  |
| NX-84 | Hire settlement statement PDF                       | Operator print      | No dedicated route              | Partial  |

_Register ends at NX-84; NX-33 marks deprecated legacy Admin CRM replacement (excluded from totals)._

---

## Nexus-only vs legacy (why this audit exists)

| Nexus capability                                         | Legacy analogue                            |
| -------------------------------------------------------- | ------------------------------------------ |
| Single workspace (Broker + OwnFleet + Partload contexts) | Three separate apps + Admin                |
| Enquiry → quotation → LR conversion                      | Not in Broker/OwnFleet apps                |
| Internal CRM (leads, tickets)                            | Legacy `truckbill_admin` (different model) |
| Entitlement / subscription in Owner Panel                | Per-app `SubscriptionGuard`                |
| Customer web single session                              | Not in legacy Broker                       |
| LR-linked OwnFleet trips                                 | Standalone legacy trips                    |
| Linked Bilty post-hire grouping                          | Linked at LR create in Broker              |
| Book balance + Adjust-in-Advance                         | Standalone advance payment screen          |
| bcmath money (no float)                                  | PHP float helpers                          |

---

## Certification cross-reference

| Document                                                | Relationship                        |
| ------------------------------------------------------- | ----------------------------------- |
| `docs/engineering/PHASE_1_TO_5_FINAL_CODEBASE_AUDIT.md` | Prior certification window (23 Nov) |
| `docs/engineering/PHASE_5_6_FINAL_GAP_REGISTER.md`      | CI / Playwright residuals           |
| `docs/audit/LEGACY_*_FUNCTIONAL_PARITY.md`              | Legacy comparison (complementary)   |

---

_Re-validate after PO closes Pending gates (AUD-CON-006, PO-P4-CNV-CARDINALITY, ADR-010, production PDF) or material route changes._
