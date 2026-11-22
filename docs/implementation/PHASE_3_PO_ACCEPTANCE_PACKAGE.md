# Phase 3 — Product Owner Acceptance Package

**Date:** 22 November 2026  
**Certification:** `PHASE_3_FINAL_CERTIFICATION_REPORT.md`  
**Decisions:** `PHASE_3_PO_DECISION_PACKAGE.md`  
**Batch G:** `PHASE_3_BATCH_G_CERTIFICATION_REPORT.md`  
**Baseline tip (Batch G start):** `3b84ef21bcc860d65c879e9e1f6377d86f96e487` (Actions #59 SUCCESS)  
**Status:** Ready for **explicit** PO decision — not auto-accepted.

---

## 1. Delivered Phase 3 scope (authorized)

- Party / Vehicle / Bank-Cash / Station / supporting catalogues
- GST Rate + TDS Rule foundations **and Super Admin management UI**
- Common documents (upload/scan/download/replace/soft-delete/storage/drawers)
- Subscription catalogue, pricing, drafts, immutable snapshots
- Trial / grace / restricted Confirmed entitlements; cancel/withdraw; scheduled downgrade finalization
- Capacity, quota, credit, storage presentation on Owner dashboard (no Phase 4 LR/Bilty ops)
- Manual Razorpay **Test Mode** via official `razorpay/razorpay` SDK (preserved)
- Owner checkout, payments, invoices; webhook route
- Draft invoice after verified payment; Super Admin number approval
- Sample / non-production invoice HTML preview, download, email + retry
- Scheduled trial reminders + lifecycle/storage evaluation + scheduled changes + invoice email retry

---

## 2. What Product Owner is asked to decide

### Formal acceptance options

| Option | Meaning                                                                                                                                   |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| A      | **ACCEPT WITH DOCUMENTED EXCEPTIONS** — Phase 3 closed; listed gates deferred                                                             |
| B      | **CONDITIONAL** — accept Masters/Documents/Subscription UI; withhold payment/entitlement closure until G-PAY sandbox matrix + AUD-CON-006 |
| C      | **WITHHOLD** — require listed blockers before Phase 3 acceptance                                                                          |

### Exception list (accept / reject / amend)

| Exception                                                                                | Accept as Phase 3 exception?  |
| ---------------------------------------------------------------------------------------- | ----------------------------- |
| AUD-CON-006 fine-grained cells Pending; Confirmed Rules §15 enforced                     | Yes / No / Amend              |
| Dashboard→local webhook delivery not independently certified (checkout may work locally) | Yes / No                      |
| No production charges (PO-P3-PAY-PROD Pending)                                           | Yes (required)                |
| No legally operative production invoices until issuer/GST validated (sample HTML only)   | Yes / No                      |
| ADR-010 accounting postings Deferred                                                     | Yes / No                      |
| Official Razorpay SDK under PO-P1-DEPS; no PDF package                                   | Yes (already authorized)      |
| Paid renewal-reminder windows (choose A/B/C/D in decision package)                       | Yes / Amend (specify windows) |

---

## 3. Razorpay evidence distinction

| Item                         | Evidence                                               |
| ---------------------------- | ------------------------------------------------------ |
| SDK                          | `razorpay/razorpay` 2.9.3 — not rewritten in Batch G   |
| Env                          | Exactly three `RAZORPAY_*` variables                   |
| PO-reported local checkout   | Working (user-reported)                                |
| CI / Fake doubles            | Automated                                              |
| Live Dashboard→local webhook | **Not independently claimed** in Batch G certification |

---

## 4. Explicit non-claims

- Does **not** approve live customer charges.
- Does **not** invent AUD-CON-006.
- Does **not** waive ADR-010 or issuer/GST validation.
- Does **not** invent renewal reminder windows.
- Does **not** claim Dashboard webhook e2e without independent evidence.
- Engineering must **not** auto-mark Phase 3 Accepted.
- Phase 4 must **not** start from this package.

---

## 5. Recommended next actions after Option A

1. Complete G-PAY sandbox matrix (Checkout + webhook) and attach sanitized ids if not already done operationally.
2. Decide AUD-CON-006 unresolved cells.
3. Choose renewal-reminder Option A/B/C/D.
4. Validate issuer/GST for production invoices.
5. Decide PO-P3-PAY-PROD separately.
6. ADR-010 when accounting architecture is ready.
