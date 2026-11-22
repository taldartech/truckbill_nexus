# Phase 4 Test Strategy — Enquiry & Unified LR

**Status:** Kickoff plan — execute after PO-P4-AUTH  
**Stack:** Pest feature/unit; existing browser/a11y patterns; no new test deps without PO-P1-DEPS  
**Authority:** Phases.md Phase 4 exit criteria; Rules §§7–8; Architecture §10

---

## 1. Objectives

Prove:

1. Tenant isolation (company + branch)
2. Webhook security (auth, rate, replay, idempotency)
3. Enquiry validation and lifecycle
4. Assignment / follow-up history integrity
5. Quotation uniqueness, comparison, selection history
6. Whole-enquiry conversion only; duplicate + concurrent safety
7. Unified LR context validation, freight, EWB, numbering concurrency
8. Edit / cancel / soft-delete dependency rules
9. Audit consistency and private files
10. Confirmed subscription entitlement gates only

Non-goals: live provider messaging; Trip/LH/Partload workflows; production payment paths.

---

## 2. Test layers

| Layer              | Tool                                                       | Focus                                                         |
| ------------------ | ---------------------------------------------------------- | ------------------------------------------------------------- |
| Unit               | Pest                                                       | Freight calculator; status transitions; numbering helpers     |
| Feature / HTTP     | Pest                                                       | Controllers, webhook, policies, conversion transactions       |
| Domain service     | Pest                                                       | Assignment, quotation, conversion, LR services with factories |
| Browser            | Existing Playwright/browser suite patterns                 | Critical UI flows after G-DESIGN-P4                           |
| Concurrency        | Pest + DB transactions / parallel processes where feasible | Numbering + conversion races                                  |
| Security negatives | Pest                                                       | Cross-tenant, missing permission, forged company_id           |

---

## 3. Coverage matrix

### 3.1 Enquiry validation (TEST-ENQ-*)

| ID           | Case                                            | Type         | Batch |
| ------------ | ----------------------------------------------- | ------------ | ----- |
| TEST-ENQ-001 | Manual create with goods + one vehicle type/qty | Feature      | B     |
| TEST-ENQ-002 | Reject zero/multiple vehicle types              | Feature      | B     |
| TEST-ENQ-003 | Multiple goods rows persist order               | Feature      | B     |
| TEST-ENQ-004 | Manual enquiry binds current branch             | Feature      | B     |
| TEST-ENQ-005 | Status transitions allowed/denied               | Unit/Feature | B     |
| TEST-ENQ-006 | Soft-delete rules; no restore                   | Feature      | B     |
| TEST-ENQ-007 | Document attach/download tenant-safe            | Feature      | B     |
| TEST-ENQ-008 | Audit remark on material edit                   | Feature      | B     |

### 3.2 Website webhook security (TEST-WH-*)

| ID          | Case                                                | Batch |
| ----------- | --------------------------------------------------- | ----- |
| TEST-WH-001 | Valid token creates unassigned enquiry              | B     |
| TEST-WH-002 | Invalid/missing token → 401/403                     | B     |
| TEST-WH-003 | Replay / duplicate Idempotency-Key → single enquiry | B     |
| TEST-WH-004 | Rate limit exceeded                                 | B     |
| TEST-WH-005 | Reject client-supplied foreign company_id           | B     |
| TEST-WH-006 | Schema validation failures                          | B     |
| TEST-WH-007 | Stale timestamp / nonce rejection (per G-WEBHOOK)   | B     |

### 3.3 Company / branch access (TEST-TEN-*)

| ID           | Case                                                          | Batch |
| ------------ | ------------------------------------------------------------- | ----- |
| TEST-TEN-001 | User cannot view other company enquiry                        | All   |
| TEST-TEN-002 | Branch-scoped user cannot access other branch when Restricted | C     |
| TEST-TEN-003 | Unassigned queue company-only; no cross-company               | B     |
| TEST-TEN-004 | Route model binding rejects forged IDs                        | All   |

### 3.4 Assignment & follow-ups (TEST-ASN-*)

| ID           | Case                                                  | Batch |
| ------------ | ----------------------------------------------------- | ----- |
| TEST-ASN-001 | Assign branch to website enquiry                      | C     |
| TEST-ASN-002 | Reassign records history from→to + remark             | C     |
| TEST-ASN-003 | Assign user only if membership allows                 | C     |
| TEST-ASN-004 | Follow-up create/list; history retained after convert | C/F   |
| TEST-ASN-005 | Search/filter combinations                            | C     |

### 3.5 Quotations (TEST-QT-*)

| ID          | Case                                                | Batch |
| ----------- | --------------------------------------------------- | ----- |
| TEST-QT-001 | Create quotation for transporter                    | D     |
| TEST-QT-002 | Duplicate transporter/enquiry rejected              | D     |
| TEST-QT-003 | Edit requires remark; audit row                     | D     |
| TEST-QT-004 | Select preferred; others Not Selected; history      | D     |
| TEST-QT-005 | Broadcast request enqueues outbox; no provider call | D     |
| TEST-QT-006 | Own Vehicle path skips quotation requirement        | D/F   |
| TEST-QT-007 | Compare view data integrity                         | D     |

### 3.6 Conversion (TEST-CNV-*)

| ID           | Case                                                      | Batch |
| ------------ | --------------------------------------------------------- | ----- |
| TEST-CNV-001 | Happy path creates LR(s); enquiry Converted               | F     |
| TEST-CNV-002 | Partial vehicle arrangement blocked                       | F     |
| TEST-CNV-003 | Second convert same enquiry → no-op/409; no second LR set | F     |
| TEST-CNV-004 | Concurrent converts: exactly one wins                     | F     |
| TEST-CNV-005 | Idempotency-Key replay returns same conversion            | F     |
| TEST-CNV-006 | History (assignment/quote) preserved post-convert         | F     |
| TEST-CNV-007 | No Trip / LorryHire / Challan rows created                | F     |

### 3.7 Unified LR (TEST-LR-*)

| ID          | Case                                                       | Batch |
| ----------- | ---------------------------------------------------------- | ----- |
| TEST-LR-001 | Broker validation: parties required; From/To optional text | E     |
| TEST-LR-002 | OwnFleet: stations required; parties optional              | E     |
| TEST-LR-003 | Partload: stations + parties required                      | E     |
| TEST-LR-004 | Reject loading/delivery address fields if submitted        | E     |
| TEST-LR-005 | Freight calc per rate type (table-driven)                  | E     |
| TEST-LR-006 | Actual vs charged weight independent                       | E     |
| TEST-LR-007 | Goods value row vs LR not synced                           | E     |
| TEST-LR-008 | EWB: number + Valid Until required; date optional          | E     |
| TEST-LR-009 | Additional charges positive only; no discount              | E     |
| TEST-LR-010 | Auto-number unique under concurrency                       | E     |
| TEST-LR-011 | Manual number duplicate rejected                           | E     |
| TEST-LR-012 | Sequence adjust remark + audit                             | E     |
| TEST-LR-013 | Edit until delivery; lock after Delivered                  | E     |
| TEST-LR-014 | Cancel before delivery; dependency guard hook              | E     |
| TEST-LR-015 | Soft-delete Pending-only + dependency fail                 | E     |
| TEST-LR-016 | Documents private access                                   | E     |
| TEST-LR-017 | Print preview authorized only                              | G     |

### 3.8 Audit / subscription / UI

| ID           | Case                                                          | Batch |
| ------------ | ------------------------------------------------------------- | ----- |
| TEST-AUD-001 | Mutation + audit + outbox atomic rollback                     | All   |
| TEST-SUB-001 | Paid grace blocks Branch enquiry/LR mutations (Confirmed §15) | B/E   |
| TEST-SUB-002 | Do not assert invented AUD-CON-006 cells                      | All   |
| TEST-UI-001  | Enquiry list filters/responsive smoke                         | G     |
| TEST-UI-002  | Conversion preview a11y (labels, focus, status text)          | G     |
| TEST-UI-003  | LR form context banner + validation messages                  | G     |

---

## 4. Concurrency test approach

1. **Numbering:** Two parallel HTTP requests (or `DB::transaction` with barriers) allocating next LR number → assert distinct numbers and sequence integrity.
2. **Conversion:** Two concurrent POSTs with same enquiry → assert single `enquiry_conversions` row and expected LR count.
3. Use PostgreSQL in CI (project default); avoid SQLite-only assumptions for `FOR UPDATE` / partial unique indexes.

---

## 5. Fixtures & factories

- Factories: Enquiry, EnquiryGoodsRow, EnquiryQuotation, LorryReceipt, LrGoodsRow, LrEwayBill, sequences.
- States: `unassigned`, `assigned`, `withQuotations`, `selected`, `converted`, `ownVehicle`.
- LR states: `broker`, `ownFleet`, `partload`, `delivered`, `cancelled`.
- Reuse Party (Customer/Transporter/Consignor/Consignee), Station, Truck Type, Document factories.

---

## 6. Exit criteria mapping (Phases.md)

| Exit criterion                    | Primary tests    |
| --------------------------------- | ---------------- |
| Duplicate conversion prevention   | TEST-CNV-002…005 |
| Scope / tenant correctness        | TEST-TEN-*       |
| Numbering concurrency             | TEST-LR-010…012  |
| Edit / cancel / delete dependency | TEST-LR-013…015  |

---

## 7. CI gates (post-auth batches)

Same as Phase 3: `npm run check`, `npm run types:check`, `npm run build`, Pint, Larastan, `php artisan test --compact`, migrate fresh in CI.

Browser suite: run critical paths in Batch G; do not block Batch A–F backend certification on full browser if G-DESIGN-P4 still open — document exception if UI deferred.

---

## 8. Defect severity for Phase 4 cert

| Severity | Examples                                                                |
| -------- | ----------------------------------------------------------------------- |
| Blocker  | Cross-tenant leak; double conversion; duplicate LR numbers; hard delete |
| Major    | Missing audit; quotation uniqueness fail; wrong context validation      |
| Minor    | Copy/UX polish; non-critical filter edge                                |
| Deferred | Provider delivery status; Trip blockers until Phase 5–6                 |
