# Phase 3 — Product Owner Decision Package (F0)

**Date:** 22 November 2026  
**Baseline HEAD:** `74a7d4e4da4e693963e8c179745cee434ce537db` (Actions #55 SUCCESS)  
**Purpose:** Decision-ready approvals required to finish remaining Phase 3 exit criteria.  
**Status:** Awaiting Product Owner decisions — **not** auto-approved.

---

## Approval matrix summary

| Decision ID     | Topic                                   | Status today                     | Blocks                                                           |
| --------------- | --------------------------------------- | -------------------------------- | ---------------------------------------------------------------- |
| AUD-CON-006     | Exact entitlement / state-action matrix | **Pending** (proposed below)     | Fine-grained create/edit/delete cells beyond Confirmed view-only |
| PO-P3-PAY-TEST  | Razorpay Test Mode + checkout + webhook | **Approved** (22 Nov 2026)       | Implemented Batch F; sandbox evidence waits on G-PAY             |
| G-PAY           | Credentials + verified webhook approach | **Open**                         | Sandbox keys, webhook secret, HTTPS reachability                 |
| PO-P1-DEPS      | `razorpay/razorpay` SDK                 | **Option A approved (no SDK)**   | Do not install official SDK                                      |
| PO-P3-PAY-PROD  | Production monetary charges             | **Pending**                      | Real customer charges                                            |
| TB-TAX / issuer | Central invoice issuer + GST validation | **Pending**                      | Legally operative production invoice issuance                    |
| ADR-010         | Accounting posting architecture         | **Pending**                      | Invoice → GL postings                                            |
| Batch D PO      | Batch D UI acceptance                   | Ready for PO (not auto-accepted) | Formal Batch D closure                                           |

Already approved and **not** re-requested: PO-P3-AUTH, PO-P3-DESIGN-FORMAL, PO-P3-UI-AUTH (masters/documents/subscription surfaces), DEC-001…009, Phase 2 deactivation (DEC-002).

---

## F0.1 — AUD-CON-006 proposed matrix (PROPOSED — not approved)

Legend: **A** = allow · **V** = view-only · **B** = block · **R** = recovery/billing only · **—** = N/A  
Cells marked **IMPLEMENTED (Confirmed)** already enforce Confirmed Rules §15. Other cells are **PROPOSED** for PO sign-off.

| Capability                         | Trial | Active | Paid grace | Restricted / expired | Cancelled (post-effective) | Company suspended | Company deactivated | Owner identity deactivated |
| ---------------------------------- | ----- | ------ | ---------- | -------------------- | -------------------------- | ----------------- | ------------------- | -------------------------- |
| Owner Panel — subscription/billing | A\*   | A\*    | A\*        | A\*                  | A\*                        | A\*               | R\* (DEC-002)       | B (no login)\*             |
| Owner Panel — company admin writes | A     | A      | A          | A (PROPOSED)         | A (PROPOSED)               | A (PROPOSED)      | B\*                 | B\*                        |
| Branch Panel — enter / view        | A\*   | A\*    | V\*        | V (PROPOSED=A view)  | V (PROPOSED)               | B\*               | B\*                 | B\*                        |
| Branch — create / edit / delete    | A\*   | A\*    | B\*        | B\*                  | B\*                        | B\*               | B\*                 | B\*                        |
| Document upload / replace / delete | A\*   | A\*    | B\*        | B\*                  | B\*                        | B\*               | B\*                 | B\*                        |
| Document download (authorized)     | A     | A      | A\*        | A (PROPOSED)         | A (PROPOSED)               | B                 | B                   | B                          |
| Module operational create (future) | A     | A      | B          | B                    | B                          | B                 | B                   | B                          |
| Credit consumption                 | A\*   | A\*    | B\*        | B\*                  | B\*                        | B\*               | B\*                 | B\*                        |
| Subscription renewal / pay         | A     | A      | A\*        | A\*                  | A (reactivation)           | A\*               | A\*                 | B                          |
| Notifications / recovery           | A\*   | A\*    | A\*        | A\*                  | A\*                        | A\*               | A\*                 | —                          |

\*Confirmed or already implemented under Rules §15 / DEC-002 / Batch Final integration.

### Explicit Product Owner questions

1. During **paid grace**, confirm uniform Branch mutation block (current Confirmed implementation) **or** allow selected edits (e.g. correct party address)?
2. During **restricted/expired**, may users still **download** previously authorized documents?
3. Does **trial** allow full Branch master CRUD subject only to capacity limits (current Confirmed)?
4. For **company suspension** vs **subscription restricted**, confirm suspension always hard-blocks Branch regardless of paid_through?

**Do not treat this proposal as AUD-CON-006 Approved until PO records an explicit decision.**

---

## F0.2 — PO-P3-PAY / G-PAY (Test Mode vs production)

### PO-P3-PAY-TEST — APPROVED (22 November 2026)

| Item                   | Decision                                                                   |
| ---------------------- | -------------------------------------------------------------------------- |
| Razorpay **Test Mode** | **Approved**                                                               |
| Integration method     | Laravel HTTP client + HMAC-SHA256 (**no SDK**)                             |
| Env vars               | `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET` (test) |
| Webhook HTTP endpoint  | `/webhooks/razorpay` non-prod when flags enabled                           |
| Checkout UI            | Owner Panel Test Mode checkout                                             |
| Activation             | Verified Test Mode settlement may activate in non-prod only                |
| Production charges     | **Not approved** — **PO-P3-PAY-PROD** Pending                              |

### Recommended signature approach (no inventing)

- Checkout: HMAC SHA256 over `order_id|payment_id` with **key secret** (Razorpay docs).
- Webhook: HMAC SHA256 over **raw body** with **webhook secret**; header `X-Razorpay-Signature`.
- Never trust client success flags; never log secrets.
- Existing `PaymentGateway` + `PaymentEventProcessor` already model this; Fake adapter remains for CI.

### G-PAY readiness checklist

- [ ] Test key id / secret available in approved secret store
- [ ] Webhook secret configured
- [ ] Public non-prod HTTPS endpoint reachable by Razorpay
- [ ] Events: `payment.captured`, `payment.failed` (minimum)
- [ ] Idempotency via gateway event id (`x-razorpay-event-id`)

---

## F0.3 — PO-P1-DEPS recommendation

| Option | Package                 | Recommendation                                                  |
| ------ | ----------------------- | --------------------------------------------------------------- |
| A      | No new package          | **Approved with PO-P3-PAY-TEST** — `RazorpayTestPaymentGateway` |
| B      | `razorpay/razorpay:2.*` | **Not approved** — do not install                               |

---

## F0.4 — Billing / tax / ADR-010

| Topic                        | Status                                       | Note                                   |
| ---------------------------- | -------------------------------------------- | -------------------------------------- |
| Invoice state machine        | Foundations complete                         | Draft→Awaiting→Approved/Issued→Emailed |
| FY numbering config          | Config keys exist; production format Pending |
| Issuer legal details         | Pending validation                           |
| GST on subscription invoices | Pending (TB-TAX)                             |
| ADR-010 postings             | Pending — no GL posts                        |

---

## Recommended PO decision order

1. Accept or amend **AUD-CON-006** proposed matrix (or formally accept Confirmed-only enforcement as Phase 3 exception).
2. Authorize **PO-P3-PAY Test Mode** + complete **G-PAY** checklist (still no production charges).
3. Choose **PO-P1-DEPS** Option A or B.
4. Authorize production issuer/tax validation path separately before legally operative invoices.
5. Keep **ADR-010** Pending for a later phase.

Until these are decided, Phase 3 exit criteria that depend on them remain **gated**, and certification must record exceptions rather than inventing authority.
