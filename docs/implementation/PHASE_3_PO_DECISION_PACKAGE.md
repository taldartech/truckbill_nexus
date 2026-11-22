# Phase 3 — Product Owner Decision Package (F0)

**Date:** 22 November 2026  
**Baseline HEAD (Batch G start):** `3b84ef21bcc860d65c879e9e1f6377d86f96e487` (Actions #59 SUCCESS)  
**Purpose:** Decision-ready approvals required to finish remaining Phase 3 exit criteria.  
**Status:** Awaiting Product Owner decisions — **not** auto-approved.

---

## Approval matrix summary

| Decision ID              | Topic                                   | Status today                     | Blocks                                                         |
| ------------------------ | --------------------------------------- | -------------------------------- | -------------------------------------------------------------- |
| AUD-CON-006              | Exact entitlement / state-action matrix | **Pending** (proposed below)     | Fine-grained cells beyond Confirmed §15                        |
| PO-P3-PAY-TEST           | Razorpay Test Mode + checkout + webhook | **Approved**                     | SDK + Owner checkout; G-PAY ops for Dashboard webhook evidence |
| G-PAY                    | Credentials + verified webhook approach | **Open**                         | Dashboard webhook subscription + public HTTPS tunnel delivery  |
| PO-P1-DEPS               | Official `razorpay/razorpay` SDK        | **Approved** (SDK)               | Installed; PDF package **not** requested                       |
| PO-P3-PAY-PROD           | Production monetary charges             | **Pending**                      | Real customer charges                                          |
| TB-TAX / issuer          | Central invoice issuer + GST validation | **Pending**                      | Legally operative production invoice issuance                  |
| ADR-010                  | Accounting posting architecture         | **Pending**                      | Invoice → GL postings                                          |
| Renewal reminder windows | Paid-term reminder schedule             | **Requires PO approval**         | Do not invent days-before-renewal windows                      |
| Batch D/G PO             | UI / Batch G acceptance                 | Ready for PO (not auto-accepted) | Formal Phase 3 closure                                         |

Already approved and **not** re-requested: PO-P3-AUTH, PO-P3-DESIGN-FORMAL, PO-P3-UI-AUTH (masters/documents/subscription surfaces), DEC-001…009, Phase 2 deactivation (DEC-002).

---

## F0.1 — AUD-CON-006 final decision table (REQUIRES PO APPROVAL)

Legend: **A** = allow · **V** = view-only · **B** = block · **R** = recovery/billing only · **—** = N/A

Cell tags: **CONFIRMED** (governing) · **IMPLEMENTED** (enforced in code) · **PROPOSED** · **REQUIRES PO APPROVAL**

| Capability                    | Trial                    | Active                  | Paid grace              | Restricted / expired     | Cancelled (post-effective) | Company suspended        | Company deactivated               | Owner identity deactivated |
| ----------------------------- | ------------------------ | ----------------------- | ----------------------- | ------------------------ | -------------------------- | ------------------------ | --------------------------------- | -------------------------- |
| Owner billing                 | A CONFIRMED+IMPLEMENTED  | A CONFIRMED+IMPLEMENTED | A CONFIRMED+IMPLEMENTED | A CONFIRMED+IMPLEMENTED  | A CONFIRMED+IMPLEMENTED    | A CONFIRMED+IMPLEMENTED  | R CONFIRMED+IMPLEMENTED (DEC-002) | B CONFIRMED+IMPLEMENTED    |
| Owner company administration  | A IMPLEMENTED            | A IMPLEMENTED           | A IMPLEMENTED           | A PROPOSED / REQUIRES PO | A PROPOSED / REQUIRES PO   | A PROPOSED / REQUIRES PO | B CONFIRMED+IMPLEMENTED           | B CONFIRMED+IMPLEMENTED    |
| Branch enter / view           | A CONFIRMED+IMPLEMENTED  | A CONFIRMED+IMPLEMENTED | V CONFIRMED+IMPLEMENTED | V PROPOSED / REQUIRES PO | V PROPOSED / REQUIRES PO   | B CONFIRMED+IMPLEMENTED  | B CONFIRMED+IMPLEMENTED           | B CONFIRMED+IMPLEMENTED    |
| Branch create / edit / delete | A CONFIRMED+IMPLEMENTED  | A CONFIRMED+IMPLEMENTED | B CONFIRMED+IMPLEMENTED | B CONFIRMED+IMPLEMENTED  | B CONFIRMED+IMPLEMENTED    | B CONFIRMED+IMPLEMENTED  | B CONFIRMED+IMPLEMENTED           | B CONFIRMED+IMPLEMENTED    |
| Master data mutations         | A IMPLEMENTED (capacity) | A IMPLEMENTED           | B CONFIRMED+IMPLEMENTED | B CONFIRMED+IMPLEMENTED  | B CONFIRMED+IMPLEMENTED    | B CONFIRMED+IMPLEMENTED  | B CONFIRMED+IMPLEMENTED           | B CONFIRMED+IMPLEMENTED    |
| Document write                | A CONFIRMED+IMPLEMENTED  | A CONFIRMED+IMPLEMENTED | B CONFIRMED+IMPLEMENTED | B CONFIRMED+IMPLEMENTED  | B CONFIRMED+IMPLEMENTED    | B CONFIRMED+IMPLEMENTED  | B CONFIRMED+IMPLEMENTED           | B CONFIRMED+IMPLEMENTED    |
| Document download             | A PROPOSED               | A PROPOSED              | A IMPLEMENTED (path)    | A PROPOSED / REQUIRES PO | A PROPOSED / REQUIRES PO   | B PROPOSED               | B PROPOSED                        | B PROPOSED                 |
| Module operations (Phase 4+)  | A PROPOSED               | A PROPOSED              | B PROPOSED              | B PROPOSED               | B PROPOSED                 | B PROPOSED               | B PROPOSED                        | B PROPOSED                 |
| Credit consumption            | A CONFIRMED+IMPLEMENTED  | A CONFIRMED+IMPLEMENTED | B CONFIRMED+IMPLEMENTED | B CONFIRMED+IMPLEMENTED  | B CONFIRMED+IMPLEMENTED    | B CONFIRMED+IMPLEMENTED  | B CONFIRMED+IMPLEMENTED           | B CONFIRMED+IMPLEMENTED    |
| Renewal / recovery pay        | A IMPLEMENTED            | A IMPLEMENTED           | A CONFIRMED+IMPLEMENTED | A CONFIRMED+IMPLEMENTED  | A (reactivation) PROPOSED  | A CONFIRMED+IMPLEMENTED  | A CONFIRMED+IMPLEMENTED           | B CONFIRMED+IMPLEMENTED    |

### Explicit Product Owner choices (unresolved cells)

1. Paid grace: keep uniform Branch mutation **B** (current) **or** allow selected master edits?
2. Restricted/expired: allow authorized **document download**?
3. Trial: confirm full Branch master CRUD subject only to capacity (current)?
4. Company **suspended** vs subscription restricted: always hard-block Branch regardless of paid_through?
5. Cancelled post-effective: Owner company admin writes **A** or **B**?

**Do not treat this table as AUD-CON-006 Approved until PO records an explicit decision.**

---

## F0.1b — Renewal reminder windows (REQUIRES PO APPROVAL)

Trial reminders **[7, 3, 1] days before trial expiry** are CONFIRMED+IMPLEMENTED.

Paid-term renewal reminders are **not** scheduled. Suggested alternatives for PO choice (do not invent):

| Option | Windows before common renewal            |
| ------ | ---------------------------------------- |
| A      | 14, 7, 3, 1 days                         |
| B      | 7, 3, 1 days (mirror trial)              |
| C      | 30, 14, 7 days                           |
| D      | Defer — trial-only reminders for Phase 3 |

Engineering will implement only after an explicit Option selection.

---

## F0.2 — PO-P3-PAY / G-PAY (Test Mode vs production)

### PO-P3-PAY-TEST — APPROVED (22 November 2026)

| Item                   | Decision                                                                    |
| ---------------------- | --------------------------------------------------------------------------- |
| Razorpay **Test Mode** | **Approved**                                                                |
| Integration method     | Official `razorpay/razorpay` PHP SDK (PO-P1-DEPS) — custom HTTP removed     |
| Env vars               | Exactly `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET` |
| Webhook HTTP endpoint  | `/webhooks/razorpay` non-prod when Test Mode credentials are present        |
| Checkout UI            | Owner Panel Test Mode checkout                                              |
| Activation             | Verified Test Mode settlement may activate in non-prod only                 |
| Production charges     | **Not approved** — **PO-P3-PAY-PROD** Pending                               |

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

## F0.3 — PO-P1-DEPS (Razorpay SDK)

| Option                                | Package                                                            | Status |
| ------------------------------------- | ------------------------------------------------------------------ | ------ |
| Official `razorpay/razorpay` ^2.9.3   | **Approved** — Test Mode only; custom Laravel HTTP adapter removed |
| Third-party Laravel Razorpay wrappers | **Not approved**                                                   |
| Production / live keys                | **Not approved** (PO-P3-PAY-PROD Pending)                          |

Prior “no SDK” preference for Batch F is **superseded** by this explicit Product Owner authorization.

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
