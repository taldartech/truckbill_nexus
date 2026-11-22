# Phase 4 Requirements Traceability — Enquiry & Unified LR

**Status:** Kickoff control artefact — does not replace governing documents  
**Authority:** PRD §§9–10; Rules §§7–8; Phases.md Phase 4; Architecture §§3–6, §10  
**Phase 3 exceptions:** Unchanged (EX-01…EX-07)  
**PO-P4-AUTH:** Not granted

Parent register: `docs/governing/Requirements-Traceability-Starter.md` (TB-ENQ-001, TB-LR-001, TB-LR-002).

### Legend

| Status      | Meaning                                |
| ----------- | -------------------------------------- |
| Confirmed   | Binding governing rule                 |
| Pending     | Requires PO decision before freeze     |
| Deferred    | Explicitly later / Phase 3+ exception  |
| Recommended | Engineering recommendation awaiting PO |

---

## 1. Enquiry (TB-ENQ-*)

| ID         | Requirement                                                                                                                                              | Type                   | Source                      | Status      | Batch | Acceptance test |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- | --------------------------- | ----------- | ----- | --------------- |
| TB-ENQ-001 | Website + manual enquiry; whole conversion only                                                                                                          | Functional             | PRD §9; Rules §7            | Confirmed   | B–F   | TEST-ENQ-001    |
| TB-ENQ-002 | Website webhook token-authenticated; central unassigned company queue; requires branch assignment                                                        | Integration / Security | Rules §7; Architecture §10  | Confirmed   | B     | TEST-ENQ-002    |
| TB-ENQ-003 | Webhook contract (payload schema, token rotation, idempotency header)                                                                                    | Integration            | Traceability note           | **Pending** | B     | TEST-ENQ-003    |
| TB-ENQ-004 | Manual enquiry associates current branch                                                                                                                 | Business Rule          | Rules §7                    | Confirmed   | B     | TEST-ENQ-004    |
| TB-ENQ-005 | Fields: No/Date, customer, pickup/delivery locations, goods rows, weights, one vehicle type + quantity, expected loading, instructions, source, assignee | Field                  | PRD §9                      | Confirmed   | B     | TEST-ENQ-005    |
| TB-ENQ-006 | Exactly one vehicle type per enquiry; quantity may be >1                                                                                                 | Business Rule          | PRD §9; Rules §7            | Confirmed   | B     | TEST-ENQ-006    |
| TB-ENQ-007 | Multiple goods rows                                                                                                                                      | Field                  | PRD §9                      | Confirmed   | B     | TEST-ENQ-007    |
| TB-ENQ-008 | Statuses: New, Under Review, Quotation Requested, Quotations Received, Selected, Converted, On Hold, Rejected, Cancelled                                 | State                  | PRD §9                      | Confirmed   | B–C   | TEST-ENQ-008    |
| TB-ENQ-009 | Follow-ups, remarks, attachments, status + assignment history retained                                                                                   | Functional             | PRD §9; Rules §7            | Confirmed   | C     | TEST-ENQ-009    |
| TB-ENQ-010 | Assign/reassign branch and authorized user with history                                                                                                  | Functional             | Rules §7                    | Confirmed   | C     | TEST-ENQ-010    |
| TB-ENQ-011 | Search/filter by source, status, customer, route, dates, branch, assignee                                                                                | Functional             | Design §5                   | Confirmed   | C/G   | TEST-ENQ-011    |
| TB-ENQ-012 | Tenant-aware authz; company + branch isolation                                                                                                           | Security               | Architecture §3; TB-SEC-001 | Confirmed   | All   | TEST-ENQ-012    |
| TB-ENQ-013 | Supporting documents via common document service                                                                                                         | Functional             | Rules §14; Phase 3 docs     | Confirmed   | B     | TEST-ENQ-013    |
| TB-ENQ-014 | Complete audit history; edit remarks where required                                                                                                      | Audit                  | Rules §4                    | Confirmed   | All   | TEST-ENQ-014    |

---

## 2. Quotation (TB-QT-*)

| ID        | Requirement                                                                                                                                                    | Type                 | Source                     | Status                                  | Batch | Test        |
| --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------- | -------------------------- | --------------------------------------- | ----- | ----------- |
| TB-QT-001 | Select transporters (Party role Transporter)                                                                                                                   | Functional           | PRD §9                     | Confirmed                               | D     | TEST-QT-001 |
| TB-QT-002 | Broadcast route/goods/weight/vehicle/loading via configured channels; retain history + delivery status where supported                                         | Integration          | PRD §9                     | Confirmed intent                        | D     | TEST-QT-002 |
| TB-QT-003 | Phase 4 implements broadcast **request enqueue + history**; provider adapters Phase 9                                                                          | Exception / Boundary | Phases §9; Architecture §9 | **Recommended**                         | D     | TEST-QT-003 |
| TB-QT-004 | Manual quotation fields: transporter, freight, rate basis, vehicle type/qty, advance, payment terms, delivery duration, validity, extra charges, remarks, docs | Field                | PRD §9                     | Confirmed                               | D     | TEST-QT-004 |
| TB-QT-005 | One quotation per transporter/enquiry; duplicate prohibited                                                                                                    | Business Rule        | PRD §9; Rules §7           | Confirmed                               | D     | TEST-QT-005 |
| TB-QT-006 | Edit quotation with remark/audit                                                                                                                               | Audit                | Rules §7                   | Confirmed                               | D     | TEST-QT-006 |
| TB-QT-007 | Compare quotations; select preferred; others Not Selected; selection history                                                                                   | Functional           | PRD §9; Design §5          | Confirmed                               | D     | TEST-QT-007 |
| TB-QT-008 | Own Vehicle: no broadcast/quotation; proceed toward conversion                                                                                                 | Business Rule        | PRD §9; Rules §7           | Confirmed                               | D/F   | TEST-QT-008 |
| TB-QT-009 | Selected quotation links to resulting LR (Bilty/Lorry Hire linkage full ops later)                                                                             | Functional           | PRD §9                     | Confirmed (LR link Phase 4; LH Phase 6) | F     | TEST-QT-009 |

---

## 3. Conversion (TB-CNV-*)

| ID         | Requirement                                                           | Type                | Source                    | Status                    | Batch | Test         |
| ---------- | --------------------------------------------------------------------- | ------------------- | ------------------------- | ------------------------- | ----- | ------------ |
| TB-CNV-001 | Whole enquiry only; all requested vehicles arranged; no partial/split | Business Rule       | PRD §9; Rules §7          | Confirmed                 | F     | TEST-CNV-001 |
| TB-CNV-002 | Duplicate conversion prevented; idempotent                            | State / Integration | Rules §7; Architecture §6 | Confirmed                 | F     | TEST-CNV-002 |
| TB-CNV-003 | Concurrent conversion transactional                                   | Non-functional      | Architecture §5–6         | Confirmed                 | F     | TEST-CNV-003 |
| TB-CNV-004 | Preserve enquiry, assignment, follow-up, quotation history            | Functional          | Rules §7                  | Confirmed                 | F     | TEST-CNV-004 |
| TB-CNV-005 | Create Unified LR record(s) appropriate to workflow context           | Functional          | PRD §10                   | Confirmed                 | F     | TEST-CNV-005 |
| TB-CNV-006 | Do not create Trip / Lorry Hire / Partload Challan in Phase 4         | Boundary            | Phases §§5–7              | **Recommended Confirmed** | F     | TEST-CNV-006 |

---

## 4. Unified LR (TB-LR-*)

| ID        | Requirement                                                                                                                 | Type          | Source                  | Status                                                | Batch | Test        |
| --------- | --------------------------------------------------------------------------------------------------------------------------- | ------------- | ----------------------- | ----------------------------------------------------- | ----- | ----------- |
| TB-LR-001 | One common LR foundation for Broker/OwnFleet/Partload                                                                       | Functional    | PRD §10; Rules §8       | Confirmed                                             | E     | TEST-LR-001 |
| TB-LR-002 | Delivered locks entire LR; all goods delivered together                                                                     | State         | PRD §10; Rules §8       | Confirmed                                             | E     | TEST-LR-002 |
| TB-LR-003 | No Loading/Delivery Address fields; From/To + parties only                                                                  | Field         | PRD §10; Rules §8       | Confirmed                                             | E     | TEST-LR-003 |
| TB-LR-004 | Broker: From/To optional open text; Consignor/Consignee required Party                                                      | Business Rule | Rules §8                | Confirmed                                             | E     | TEST-LR-004 |
| TB-LR-005 | OwnFleet: From/To Station required; Consignor/Consignee optional Party                                                      | Business Rule | Rules §8                | Confirmed                                             | E     | TEST-LR-005 |
| TB-LR-006 | Partload: From/To Station + Consignor/Consignee required                                                                    | Business Rule | Rules §8                | Confirmed                                             | E     | TEST-LR-006 |
| TB-LR-007 | Goods row fields + rate types Fixed/Per Package/Unit/KG/MT/Actual/Charged                                                   | Calculation   | PRD §10; Rules §8       | Confirmed                                             | E     | TEST-LR-007 |
| TB-LR-008 | Actual vs charged weight separate/manual                                                                                    | Field         | Rules §8                | Confirmed                                             | E     | TEST-LR-008 |
| TB-LR-009 | Goods Value row-level and LR-level optional; not synchronized                                                               | Field         | Rules §8                | Confirmed                                             | E     | TEST-LR-009 |
| TB-LR-010 | Multiple EWBs: number required; date optional; Valid Until required                                                         | Field         | Rules §8                | Confirmed                                             | E     | TEST-LR-010 |
| TB-LR-011 | Freight per goods row; LR total = sum; additional positive charges; no LR discount                                          | Calculation   | Rules §8                | Confirmed                                             | E     | TEST-LR-011 |
| TB-LR-012 | Numbering Auto/Manual; scope follows Data Visibility; manual alphanumeric + duplicate check; sequence adjust + remark/audit | Functional    | Rules §8; Design §6     | Confirmed intent; scope settings **Deferred/Pending** | E     | TEST-LR-012 |
| TB-LR-013 | Editable until delivery                                                                                                     | State         | Rules §8                | Confirmed                                             | E     | TEST-LR-013 |
| TB-LR-014 | Cancel before delivery blocked by active Trip/Lorry Hire (hooks for future); soft-delete Pending-only + dependencies        | Business Rule | Rules §8                | Confirmed                                             | E     | TEST-LR-014 |
| TB-LR-015 | Document attachments on LR                                                                                                  | Functional    | Design §6; Phase 3 docs | Confirmed                                             | E     | TEST-LR-015 |
| TB-LR-016 | Print-friendly LR preview                                                                                                   | UI            | Design § print          | Confirmed                                             | G     | TEST-LR-016 |
| TB-LR-017 | Distinguish consignment vs arrangement vs operational movement                                                              | Architecture  | PRD §10                 | Confirmed                                             | E     | —           |

---

## 5. Cross-cutting

| ID               | Requirement                         | Status                          | Notes                |
| ---------------- | ----------------------------------- | ------------------------------- | -------------------- |
| TB-SEC-001       | Company/branch isolation            | Confirmed                       | Every batch          |
| TB-AUD-001       | Mandatory remarks + immutable audit | Confirmed                       | Mutations            |
| TB-SUB-STATE     | Confirmed Rules §15 only            | Confirmed / AUD-CON-006 Pending | Do not invent        |
| TB-FILE-001      | Private files                       | Confirmed                       | Phase 3 reuse        |
| TB-EVT-001       | Outbox events Enquiry*/LR*          | Confirmed intent                | Architecture §6      |
| TB-PERM-P4       | Enquiry/LR permission catalogue     | **Pending**                     | See decision package |
| Phase 3 EX-01…07 | Unchanged                           | Accepted exceptions             | Do not reopen        |

---

## 6. Out of Phase 4 (trace only)

| ID                            | Deferred to       |
| ----------------------------- | ----------------- |
| TB-OWNF-001 Trip              | Phase 5           |
| TB-BRK-001 Lorry Hire         | Phase 6           |
| TB-PTL-001 Challan/DR         | Phase 7           |
| TB-COM-001 Provider messaging | Phase 9           |
| TB-ACC-001 GL                 | Phase 8 / ADR-010 |

## Batch B implementation note (23 November 2026)

Backend intake for TB-ENQ website webhook + manual create/edit certified under `PHASE_4_BATCH_B_CERTIFICATION_REPORT.md` and `WEBSITE_ENQUIRY_WEBHOOK_V1_API.md`. Product UI rows remain Pending (PO-P4-DESIGN-FORMAL).
