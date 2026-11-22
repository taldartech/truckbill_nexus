# Phase 3 — Product Owner Acceptance Package

**Date:** 22 November 2026  
**Certification:** `PHASE_3_FINAL_CERTIFICATION_REPORT.md`  
**Decisions:** `PHASE_3_PO_DECISION_PACKAGE.md`  
**Baseline tip (pre-completion pass):** `1afeb37631000069e32df619214bd220691c3ee9` (Actions #58 SUCCESS)  
**Status:** Ready for **explicit** PO decision — not auto-accepted.

---

## 1. Delivered Phase 3 scope (authorized)

- Party / Vehicle / Bank-Cash / Station / supporting catalogues
- GST Rate + TDS Rule foundations
- Common documents (upload/scan/download/replace/soft-delete/storage/drawers)
- Subscription catalogue, pricing, drafts, immutable snapshots
- Trial / grace / restricted Confirmed entitlements; cancel/withdraw
- Capacity, quota, credit foundations (no Phase 4 LR/Bilty ops)
- Manual Razorpay **Test Mode** via official `razorpay/razorpay` SDK
- Owner checkout, payments, invoices; webhook route
- Draft invoice after verified payment; Super Admin number approval
- Scheduled trial reminders + lifecycle/storage evaluation

---

## 2. What Product Owner is asked to decide

### Formal acceptance options

| Option | Meaning                                                                                                                                   |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| A      | **ACCEPT WITH DOCUMENTED EXCEPTIONS** — Phase 3 closed; listed gates deferred                                                             |
| B      | **CONDITIONAL** — accept Masters/Documents/Subscription UI; withhold payment/entitlement closure until G-PAY sandbox matrix + AUD-CON-006 |
| C      | **WITHHOLD** — require listed blockers before Phase 3 acceptance                                                                          |

### Exception list (accept / reject / amend)

| Exception                                                                                                 | Accept as Phase 3 exception?  |
| --------------------------------------------------------------------------------------------------------- | ----------------------------- |
| AUD-CON-006 fine-grained cells Pending; Confirmed Rules §15 enforced                                      | Yes / No / Amend              |
| Real Razorpay Checkout capture + Dashboard webhook delivery waits on G-PAY ops (SDK + order API verified) | Yes / No                      |
| No production charges (PO-P3-PAY-PROD Pending)                                                            | Yes (required)                |
| No legally operative production invoices until issuer/GST validated                                       | Yes / No                      |
| ADR-010 accounting postings Deferred                                                                      | Yes / No                      |
| Official `razorpay/razorpay` SDK installed under PO-P1-DEPS (custom HTTP removed)                         | Yes (already authorized)      |
| Dedicated renewal-reminder job beyond trial reminders                                                     | Yes / Amend (specify windows) |

---

## 3. Test Mode payment evidence (sanitized)

| Item                  | Evidence                                                                                    |
| --------------------- | ------------------------------------------------------------------------------------------- |
| SDK                   | `razorpay/razorpay` 2.9.3                                                                   |
| Env                   | Exactly three `RAZORPAY_*` variables                                                        |
| Order create          | Success — Test Mode order id prefix `order_U3J3` (₹1.00)                                    |
| Signature API         | Invalid signature rejected by SDK                                                           |
| Card Checkout capture | Not claimed in this package                                                                 |
| Live webhook delivery | Not claimed — register `{APP_URL}/webhooks/razorpay` via HTTPS tunnel in Razorpay Dashboard |

---

## 4. Explicit non-claims

- Does **not** approve live customer charges.
- Does **not** invent AUD-CON-006.
- Does **not** waive ADR-010 or issuer/GST validation.
- Does **not** claim real Checkout/webhook e2e without Dashboard evidence.
- Engineering must **not** auto-mark Phase 3 Accepted.

---

## 5. Recommended next actions after Option A

1. Complete G-PAY sandbox matrix (Checkout + webhook) and attach sanitized ids.
2. Decide AUD-CON-006 proposal cells.
3. Validate issuer/GST for production invoices.
4. Decide PO-P3-PAY-PROD separately (not implied by Test Mode).
5. ADR-010 when accounting architecture is ready.
