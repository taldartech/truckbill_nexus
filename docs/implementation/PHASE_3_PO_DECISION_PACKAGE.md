# Phase 3 — Product Owner Decision Package (F0)

**Date:** 22 November 2026  
**Accepted baseline (PO-P3-FINAL-ACCEPT):** `68d11b1ecc267e91d3adb37825d94def71334a3a` (Actions **#60 SUCCESS**)  
**Purpose:** Decision register for Phase 3 exit criteria and deferred gates.  
**Status:** **PO-P3-FINAL-ACCEPT APPROVED — OPTION A** — Phase 3 **FORMALLY ACCEPTED WITH DOCUMENTED EXCEPTIONS**. Deferred gates remain open.

Formal record: `PHASE_3_ACCEPTANCE_RECORD.md`.

---

## Approval matrix summary

| Decision ID              | Topic                                   | Status today                    | Notes                                                                 |
| ------------------------ | --------------------------------------- | ------------------------------- | --------------------------------------------------------------------- |
| **PO-P3-FINAL-ACCEPT**   | Phase 3 formal closure                  | **APPROVED — Option A**         | Accepted WITH DOCUMENTED EXCEPTIONS                                   |
| AUD-CON-006              | Exact entitlement / state-action matrix | **Pending** (EX-01)             | Confirmed Rules §15 authoritative                                     |
| PO-P3-PAY-TEST           | Razorpay Test Mode + checkout + webhook | **Approved**                    | SDK + Owner checkout                                                  |
| G-PAY                    | Independent Dashboard webhook evidence  | **Open** (EX-02)                | Local checkout reported working; Dashboard→local evidence outstanding |
| PO-P1-DEPS               | Official `razorpay/razorpay` SDK        | **Approved** (SDK)              | PDF package **not** approved (EX-07)                                  |
| PO-P3-PAY-PROD           | Production monetary charges             | **Pending** (EX-03)             | Real customer charges blocked                                         |
| TB-TAX / issuer          | Central invoice issuer + GST validation | **Pending** (EX-04)             | Sample invoices only                                                  |
| ADR-010                  | Accounting posting architecture         | **Pending** (EX-05)             | No GL posts                                                           |
| Renewal reminder windows | Paid-term reminder schedule             | **Deferred — Option D** (EX-06) | Trial reminders retained                                              |
| Production PDF invoices  | PDF dependency / generation             | **Deferred** (EX-07)            | Sample HTML accepted                                                  |
| Phase 4                  | Enquiry / unified LR                    | **Not authorized**              | Requires separate PO authorization                                    |

Already approved earlier: PO-P3-AUTH, PO-P3-DESIGN-FORMAL, PO-P3-UI-AUTH, DEC-001…009, Phase 2 deactivation (DEC-002).

---

## F0.1 — AUD-CON-006 (EX-01 — Pending under Phase 3 acceptance)

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

**PO-P3-FINAL-ACCEPT** accepts Phase 3 closure with AUD-CON-006 remaining **Pending**. Unresolved cells still **REQUIRE PO APPROVAL** before implementation beyond Confirmed Rules §15. No unapproved entitlement behavior is authorized.

### Remaining Product Owner questions (still open)

1. Paid grace: keep uniform Branch mutation **B** (current) **or** allow selected master edits?
2. Restricted/expired: allow authorized **document download**?
3. Trial: confirm full Branch master CRUD subject only to capacity (current)?
4. Company **suspended** vs subscription restricted: always hard-block Branch regardless of paid_through?
5. Cancelled post-effective: Owner company admin writes **A** or **B**?

---

## F0.1b — Renewal reminder windows (EX-06 — Option D Deferred)

Trial reminders **[7, 3, 1] days before trial expiry** are CONFIRMED+IMPLEMENTED.

**PO-P3-FINAL-ACCEPT:** Paid-term renewal reminders — **Option D Deferred** for Phase 3. No paid-renewal reminder schedule is approved. Engineering must not invent windows until a later explicit Option A/B/C (or other) decision.

| Option | Windows before common renewal            | Disposition                           |
| ------ | ---------------------------------------- | ------------------------------------- |
| A      | 14, 7, 3, 1 days                         | Not selected                          |
| B      | 7, 3, 1 days (mirror trial)              | Not selected                          |
| C      | 30, 14, 7 days                           | Not selected                          |
| D      | Defer — trial-only reminders for Phase 3 | **Selected under PO-P3-FINAL-ACCEPT** |

---

## F0.2 — PO-P3-PAY / G-PAY (Test Mode vs production)

### PO-P3-PAY-TEST — APPROVED (22 November 2026)

| Item                   | Decision                                                                    |
| ---------------------- | --------------------------------------------------------------------------- |
| Razorpay **Test Mode** | **Approved**                                                                |
| Integration method     | Official `razorpay/razorpay` PHP SDK (PO-P1-DEPS)                           |
| Env vars               | Exactly `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET` |
| Webhook HTTP endpoint  | `/webhooks/razorpay` non-prod when Test Mode credentials are present        |
| Checkout UI            | Owner Panel Test Mode checkout                                              |
| Activation             | Verified Test Mode settlement may activate in non-prod only                 |
| Production charges     | **Not approved** — **PO-P3-PAY-PROD** Pending (EX-03)                       |

### G-PAY (EX-02 — Open under Phase 3 acceptance)

- PO reports Razorpay works locally.
- Independent evidence of Razorpay Dashboard-to-local webhook delivery remains **outstanding**.
- Phase 3 closure **accepts this evidence gap**; do not fabricate webhook delivery results.

### G-PAY readiness checklist (still operational)

- [ ] Test key id / secret available in approved secret store
- [ ] Webhook secret configured
- [ ] Public non-prod HTTPS endpoint reachable by Razorpay
- [ ] Events: `payment.captured`, `payment.failed` (minimum)
- [ ] Idempotency via gateway event id (`x-razorpay-event-id`)

---

## F0.3 — PO-P1-DEPS

| Option                                | Package                                   | Status           |
| ------------------------------------- | ----------------------------------------- | ---------------- |
| Official `razorpay/razorpay` ^2.9.3   | **Approved** — Test Mode                  | Installed        |
| Third-party Laravel Razorpay wrappers | **Not approved**                          | —                |
| Production / live keys                | **Not approved** (PO-P3-PAY-PROD Pending) | EX-03            |
| Production PDF / dompdf (or similar)  | **Not approved** (EX-07 Deferred)         | Sample HTML only |

---

## F0.4 — Billing / tax / ADR-010

| Topic                        | Status                                       | Note                                   |
| ---------------------------- | -------------------------------------------- | -------------------------------------- |
| Invoice state machine        | Foundations complete                         | Draft→Awaiting→Approved/Issued→Emailed |
| FY numbering config          | Config keys exist; production format Pending | —                                      |
| Issuer legal details         | Pending validation (EX-04)                   | Sample invoices only                   |
| GST on subscription invoices | Pending (TB-TAX)                             | EX-04                                  |
| ADR-010 postings             | Pending — no GL posts (EX-05)                | —                                      |
| Production PDF invoices      | Deferred (EX-07)                             | HTML sample accepted                   |

---

## Post-acceptance decision order (remaining gates)

1. AUD-CON-006 unresolved cells (EX-01) when ready.
2. Complete G-PAY independent webhook evidence (EX-02) when ready.
3. Issuer/GST validation before production invoices (EX-04).
4. PO-P3-PAY-PROD separately (EX-03).
5. ADR-010 when accounting architecture is ready (EX-05).
6. Paid renewal reminders if needed later (not Option D).
7. Separate **Phase 4 authorization** before Enquiry / LR work.
