# Phase 4 Architecture & Data Design — Enquiry & Unified LR

**Status:** Kickoff design — **not implementation-authorized**  
**Authority:** Architecture.md §§3–6, §10; PRD §§9–10; Rules §§7–8  
**PO-P4-AUTH:** Open  
**Reuse:** Party, Station, Vehicle catalogues, Goods/Package/Truck Type, DocumentAttachment, PermissionAuthorizer, AuditedTransaction, OutboxWriter, PrivateFileService, CompanyAccessService

---

## 1. Bounded contexts

| Context                          | Phase 4 ownership                                                               | Cross-context                                                                           |
| -------------------------------- | ------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| **CRM/Enquiry**                  | Enquiry aggregate, assignment, follow-ups, quotations, conversion orchestration | Reads Party/Station/Truck Type; writes Documents; emits outbox                          |
| **Consignment/LR**               | Unified LR aggregate, goods, EWBs, charges, numbering                           | Created by conversion or manual Branch create; hooks for future Trip/LH (no writes yet) |
| **Masters**                      | Unchanged ownership                                                             | Enquiry/LR reference only                                                               |
| **Documents**                    | Unchanged                                                                       | Morph attach to Enquiry / Quotation / LR                                                |
| **Audit / Outbox**               | Platform infrastructure                                                         | Atomic with mutations                                                                   |
| **Communications**               | **Not implemented**                                                             | Enquiry may enqueue `quotation.broadcast.requested`; Phase 9 consumes                   |
| **Broker / OwnFleet / Partload** | **Out of Phase 4**                                                              | LR `workflow_context` prepares validation only                                          |

Cross-context writes use application services + domain events — not arbitrary FK writes from controllers.

---

## 2. Domain model (proposed)

### 2.1 Enquiry aggregate

```text
Enquiry (company_id, optional branch_id)
  ├── EnquiryGoodsRow[]          (ordered)
  ├── EnquiryAssignmentHistory[] (append-only)
  ├── EnquiryFollowUp[]          (append-only remarks + due dates)
  ├── EnquiryStatusHistory[]     (append-only)
  ├── EnquiryQuotationBroadcastRequest[]  (targets + channel intent + delivery status stub)
  ├── EnquiryQuotation[]
  │     └── QuotationSelectionHistory (via selection events)
  └── DocumentAttachment[] (morph)
```

**Key invariants**

- `company_id` required; never client-trusted.
- Website source → `branch_id` null until assigned (central unassigned queue).
- Manual Branch create → `branch_id` = current branch.
- Exactly one `vehicle_type_id` (Truck Type catalogue); `vehicle_quantity` ≥ 1.
- Status machine (PRD §9): New → Under Review → Quotation Requested → Quotations Received → Selected → Converted; also On Hold / Rejected / Cancelled.
- Converted is terminal for conversion path; Cancelled/Rejected terminal for abandonment.
- Soft-delete only when business rules allow (Pending-equivalent states); no restore (DEC-003).

### 2.2 Quotation

- Unique active `(enquiry_id, transporter_party_id)` where not soft-deleted.
- Selection: at most one Preferred per enquiry; others Not Selected; history rows retained.
- Own Vehicle path: no quotation rows required for conversion eligibility.

### 2.3 Unified LR aggregate

```text
LorryReceipt (company_id, branch_id, workflow_context)
  ├── LrGoodsRow[]
  ├── LrEwayBill[]
  ├── LrAdditionalCharge[]   (positive amounts only)
  ├── LrNumberAllocation     (or sequence ledger)
  ├── DocumentAttachment[]
  └── (future) Trip / LorryHire / Partload refs — nullable FKs reserved, unused in Phase 4
```

**workflow_context:** `broker` | `own_fleet` | `partload` — drives From/To and party validation (Rules §8).

**Status (minimal Phase 4):** Pending → (optional In Progress placeholders) → Delivered | Cancelled. Delivered locks entire LR. Full operational transitions owned by later phases.

### 2.4 Conversion

```text
EnquiryConversion (enquiry_id UNIQUE, company_id, idempotency_key UNIQUE per company,
                   lr_ids[], converted_at, converted_by)
```

- One conversion per enquiry (unique `enquiry_id`).
- Idempotency key on client/API retries.
- Transaction: lock enquiry row → validate whole-enquiry readiness → create LR(s) → mark Converted → outbox `EnquiryConverted` + `LRCreated` → audit.

**LR cardinality recommendation (Pending PO confirm if multi-vehicle):** For `vehicle_quantity` N, create N Unified LR records sharing enquiry linkage (or one LR with quantity — **recommend N LRs** to match “all vehicles arranged” and later one OwnFleet LR=one Trip). Record as **PO-P4-CNV-CARDINALITY** if not explicit in governing docs.

---

## 3. PostgreSQL schema sketch

> Illustrative; migrations only after PO-P4-AUTH. UUID primary keys; UTC timestamps; soft deletes where allowed.

### 3.1 Core tables

| Table                                   | Purpose            | Notable constraints                                                                                                                           |
| --------------------------------------- | ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `enquiries`                             | Header             | FK company; nullable branch; source enum; status; vehicle_type_id; vehicle_quantity; enquiry_no unique per company (or scope); `converted_at` |
| `enquiry_goods_rows`                    | Goods lines        | FK enquiry; ordered position; weights numeric                                                                                                 |
| `enquiry_assignment_histories`          | Branch/user assign | Append-only; from/to branch/user; remark; actor                                                                                               |
| `enquiry_follow_ups`                    | Follow-ups         | Due date; remark; actor; soft-delete optional                                                                                                 |
| `enquiry_status_histories`              | Status changes     | Append-only                                                                                                                                   |
| `enquiry_quotation_broadcast_requests`  | Broadcast intent   | Transporter set; channels JSON; status queued/submitted/failed stub                                                                           |
| `enquiry_quotations`                    | Quotes             | Unique (enquiry_id, transporter_party_id) WHERE deleted_at IS NULL                                                                            |
| `enquiry_quotation_selection_histories` | Selection audit    | Append-only                                                                                                                                   |
| `enquiry_conversions`                   | Conversion ledger  | UNIQUE enquiry_id; UNIQUE (company_id, idempotency_key)                                                                                       |
| `lorry_receipts`                        | Unified LR         | company+branch; workflow_context; lr_number; status; from/to text+station FKs; consignor/consignee nullable per context                       |
| `lr_goods_rows`                         | Goods + freight    | Rate type enum; actual/charged weight; freight numeric                                                                                        |
| `lr_eway_bills`                         | EWBs               | number required; valid_until required; date optional                                                                                          |
| `lr_additional_charges`                 | Positive charges   | amount > 0                                                                                                                                    |
| `lr_number_sequences`                   | Auto numbering     | Scope key (company or company+branch); next_value; lock row                                                                                   |
| `lr_number_allocations`                 | Issued numbers     | UNIQUE active (scope, lr_number)                                                                                                              |

### 3.2 Indexes (tenant-aware)

- `(company_id, status)`, `(company_id, branch_id, status)`, `(company_id, expected_loading_date)`
- Unassigned queue: `(company_id) WHERE branch_id IS NULL AND deleted_at IS NULL`
- Quotations: unique partial index on transporter
- LR: `(company_id, branch_id, lr_number)` unique active; `(company_id, status)`
- Sequences: `FOR UPDATE` on sequence row inside transaction

### 3.3 Money / weight

- `numeric(18,3)` weights; `numeric(18,2)` money; currency INR initial.
- Freight calculation service: Fixed | Per Package/Unit | Per KG | Per MT | Per Actual | Per Charged — unit tests goldens.

---

## 4. Numbering

| Entity  | Mode                                 | Scope                                                           | Concurrency                          |
| ------- | ------------------------------------ | --------------------------------------------------------------- | ------------------------------------ |
| Enquiry | Auto (recommended) / Manual optional | Company (website + multi-branch)                                | Sequence row lock + unique           |
| LR      | Auto / Manual (Rules §8)             | Follows Data Visibility (Shared → company; Restricted → branch) | Sequence lock + unique active number |

Manual: alphanumeric; duplicate check against active records; sequence adjustment requires remark + audit.

**G-NUMBER:** Exact Data Visibility interaction for new numbering settings remains Pending/Deferred from Phase 2 — freeze before Batch E.

---

## 5. Services (proposed packages)

```text
App\Domains\Enquiry\
  EnquiryService
  EnquiryAssignmentService
  EnquiryFollowUpService
  EnquiryQuotationService
  EnquiryBroadcastRequestService   // enqueue only
  EnquiryConversionService
  EnquiryNumberService
  Policies / Enums / DTOs

App\Domains\Consignment\
  LorryReceiptService
  LrFreightCalculator
  LrNumberingService
  LrValidationContextFactory       // broker/own_fleet/partload rules
  LrCancellationService
  Policies / Enums / DTOs
```

Reuse: `DocumentAttachmentService`, `AuditedTransaction`, `OutboxWriter`, `PermissionAuthorizer`, Party/Station lookups.

---

## 6. Outbox events

| Event                           | When                    | Consumers (later)             |
| ------------------------------- | ----------------------- | ----------------------------- |
| `enquiry.created`               | Manual or webhook       | Notifications                 |
| `enquiry.assigned`              | Branch/user assign      | Notify assignee               |
| `enquiry.status_changed`        | Status transition       | —                             |
| `quotation.broadcast.requested` | Broadcast request saved | Phase 9 Communications        |
| `enquiry.converted`             | Conversion committed    | Notifications                 |
| `lr.created` / `lr.cancelled`   | LR lifecycle            | Future Trip/LH; notifications |

Payloads: identifiers + correlation; consumers reload source.

---

## 7. Idempotency & concurrency

| Operation         | Strategy                                                                         |
| ----------------- | -------------------------------------------------------------------------------- |
| Website webhook   | Idempotency-Key header OR content hash + company token; store `webhook_receipts` |
| Conversion        | Unique enquiry_id + idempotency_key; `lockForUpdate` on enquiry                  |
| LR auto-number    | Lock sequence row; insert allocation; unique constraint safety net               |
| Quotation create  | Unique partial index; catch conflict → domain error                              |
| Broadcast enqueue | Idempotent per (enquiry, request batch id)                                       |

---

## 8. Webhook security design

- Company-scoped secret token (hashed at rest); rotation with grace period (**G-WEBHOOK**).
- HTTPS only; rate limit per token/IP; timestamp/nonce or signed payload for replay protection.
- Never accept client `company_id` without token→company resolution.
- Reject oversized payloads; schema validation; malware-safe: no file upload on webhook v1 (docs via Branch later) unless PO expands contract.

---

## 9. Authorization sketch (Pending G-PERM)

Recommended permission families (PO must approve codes):

| Code family                                                           | Actions           |
| --------------------------------------------------------------------- | ----------------- |
| `enquiry.view` / `create` / `edit` / `assign` / `convert` / `delete`  | Enquiry lifecycle |
| `quotation.view` / `create` / `edit` / `select` / `broadcast`         | Quotations        |
| `lr.view` / `create` / `edit` / `cancel` / `delete` / `number.adjust` | LR                |

Until approved, do not hard-code into seeders. Owner bypass patterns follow existing Branch panel Owner rules.

Subscription: Confirmed Rules §15 only — Branch mutations blocked in paid grace etc.; do not invent AUD-CON-006 module cells.

---

## 10. Future integration seams (no Phase 5–7 code)

| Seam                  | Design                                                                                                         |
| --------------------- | -------------------------------------------------------------------------------------------------------------- |
| OwnFleet Trip         | `lorry_receipts.id` → future `trips.lorry_receipt_id` UNIQUE                                                   |
| Broker Lorry Hire     | future `lorry_hires.lorry_receipt_id`                                                                          |
| Partload Challan/DR   | future operational tables reference LR                                                                         |
| Cancellation blockers | Interface `LrDependencyGuard` with no-op / empty implementations returning “no active Trip/LH” until Phase 5–6 |

---

## 11. Explicit non-goals

- Three separate LR engines
- WhatsApp/SMS provider adapters
- Accounting postings (ADR-010 Pending)
- Sanctum / mobile tokens (ADR-006)
- Sibling `website/` form implementation (contract only)

---

## 12. ADR candidates (after PO-P4-AUTH)

| Candidate | Topic                                           |
| --------- | ----------------------------------------------- |
| ADR-011   | Enquiry→LR conversion cardinality & idempotency |
| ADR-012   | Unified LR workflow_context validation strategy |
| ADR-013   | Quotation broadcast outbox boundary vs Phase 9  |
