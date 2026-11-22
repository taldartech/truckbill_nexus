# Phase 4 Batch A Certification Report — Domain Foundations

**Date:** 23 November 2026  
**Batch:** A — Domain foundations and database schema  
**Authorization:** PO-P4-AUTH APPROVED (23 November 2026)  
**Baseline at authorization:** `2c3f351444ac7e80ffd0a723475c4b5e8a3234f5` (Actions #62)  
**Batch A feature commit:** `f1faec8ca92ced9b7fdfe1bdd6305742947e81ef`  
**Status:** **COMPLETE — READY FOR BATCH B REVIEW** (no Batch B auto-start)

---

## 1. Baseline

| Item               | Value                                                      |
| ------------------ | ---------------------------------------------------------- |
| Kickoff tip        | `193e6dc…`                                                 |
| Auth baseline      | `2c3f351…`                                                 |
| Phase 3 acceptance | `68d11b1…` / EX-01…EX-07 preserved                         |
| ADRs added         | ADR-013 (broadcast boundary), ADR-014 (conversion LR-only) |

---

## 2. Authorization decisions recorded

| Decision              | Status                                        |
| --------------------- | --------------------------------------------- |
| PO-P4-AUTH            | APPROVED                                      |
| PO-P4-BROADCAST       | APPROVED — Option A                           |
| PO-P4-CONVERT         | APPROVED — Option A                           |
| PO-P4-PERM            | APPROVED (register; no blanket grants)        |
| PO-P4-NUMBER          | APPROVED                                      |
| PO-P4-WEBHOOK         | APPROVED — V1 (no public endpoint in Batch A) |
| PO-P4-CNV-CARDINALITY | **PENDING**                                   |
| PO-P4-DESIGN-FORMAL   | **PENDING**                                   |

Record: `PHASE_4_AUTHORIZATION_RECORD.md`  
Cardinality proposal: `PHASE_4_CONVERSION_CARDINALITY_DECISION_PROPOSAL.md`

---

## 3. Schema inventory

Migration: `2026_11_23_010000_create_phase4_batch_a_enquiry_and_lr_foundations.php`

| Area                 | Tables                                                                                                                                       |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Numbering            | `document_number_sequences`, `document_number_allocations`, `document_number_adjustments`                                                    |
| Enquiry              | `enquiries`, `enquiry_goods_rows`, `enquiry_assignment_histories`, `enquiry_follow_ups`, `enquiry_status_histories`                          |
| Quotation            | `enquiry_quotations`, `enquiry_quotation_selection_histories`, `enquiry_quotation_broadcast_requests`, `enquiry_quotation_broadcast_targets` |
| Webhook foundations  | `company_enquiry_webhook_credentials`, `enquiry_external_references`                                                                         |
| Unified LR           | `lorry_receipts`, `lr_goods_rows`, `lr_eway_bills`, `lr_additional_charges`                                                                  |
| Conversion (neutral) | `enquiry_conversions`, `enquiry_conversion_lorry_receipts`                                                                                   |

---

## 4. Models / enums / services

- Domains: `Enquiry`, `Consignment`, `Numbering`
- Enums: enquiry status/source, quotation selection, broadcast request status, LR workflow/status, freight rate types, numbering types/scopes/modes
- Services: `DocumentNumberAllocator`, `EnquiryWebhookCredentialService`, `CompositeLrDependencyGuard`
- Factories with enquiry/LR states (unassigned, assigned, withGoods, broker/ownFleet/partload, etc.)
- Conversion schema supports 1..N LR links without fixing N

---

## 5. Permissions

Registered Customer-domain codes per PO-P4-PERM via `RbacSeeder::seedCustomerPhase4Permissions()`.  
**Not** assigned to default roles. Delete and `lr.number.adjust` remain ungranted by seed.

---

## 6. Webhook security foundations

- Hashed SHA-256 tokens; prefix lookup; hidden `token_hash`
- Create / rotate / revoke
- Durable `(company_id, external_reference)` uniqueness
- **No** public webhook route in Batch A

---

## 7. Numbering foundations

- Company and branch scopes; auto/manual allocation; sequence adjust with remark
- Partial unique indexes for active allocations
- Concurrent allocateNext covered in Pest

---

## 8. Tests / evidence

| Suite                                                 | Result                              |
| ----------------------------------------------------- | ----------------------------------- |
| `tests/Feature/Phase4/Phase4BatchAFoundationTest.php` | Pass                                |
| `tests/Feature/DatabaseSeederTest.php`                | Pass (Phase 4 catalogue assertions) |
| Full `php artisan test --compact`                     | See commit validation notes         |

---

## 9. Known limitations

- No Enquiry/LR HTTP endpoints or product UI
- No webhook public endpoint
- No conversion execution / goods allocation
- No freight calculator service yet (Batch E)
- Webhook operational params (overlap duration, retry limits) are technical — not claimed as PO policy
- `PO-P4-CNV-CARDINALITY` Pending

---

## 10. Remaining gates

- Batch B: implement webhook endpoint + manual enquiry under approved V1
- PO-P4-CNV-CARDINALITY before conversion behaviour
- PO-P4-DESIGN-FORMAL before product UI

---

## 11. Batch B readiness

**Yes — backend Batch B (website webhook + manual enquiry create) may begin under PO-P4-AUTH and PO-P4-WEBHOOK V1**, subject to documenting any remaining technical webhook parameters. Missing UI design approval is **not** a backend blocker.

**Do not auto-start Batch B in this task.**

---

## Verdict

**BATCH A COMPLETE — READY FOR BATCH B REVIEW**
