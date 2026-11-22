# Phase 3 Batch F — Razorpay Test Mode Certification

**Date:** 22 November 2026  
**Authority:** PO-P3-PAY-TEST **APPROVED** (HTTP/HMAC, no SDK)  
**Initial HEAD:** `1a383ebdecba7d87f54669c176b88dabdb28671e` (GitHub Actions #56 SUCCESS)  
**Final HEAD:** _(recorded after local commit)_  
**Verdict:** **IMPLEMENTATION COMPLETE — G-PAY CONFIGURATION PENDING**

No push. No deployment. No production charges. Phase 3 is not auto-accepted.

---

## Distinction of evidence

| Layer                                 | Status                                                             |
| ------------------------------------- | ------------------------------------------------------------------ |
| 1. Implemented payment integration    | **Yes** — adapter, checkout, webhook, verification, renewal wiring |
| 2. HTTP-mocked automated verification | **Yes** — Pest + `Http::fake`                                      |
| 3. Actual Razorpay Test Mode sandbox  | **Blocked** — G-PAY credentials / HTTPS reachability Open          |
| 4. Production readiness               | **No** — PO-P3-PAY-PROD Pending                                    |
| 5. Remaining gates                    | AUD-CON-006, issuer/tax, ADR-010                                   |

---

## A. Authorization recorded

| Gate                                     | Status                        |
| ---------------------------------------- | ----------------------------- |
| PO-P3-PAY-TEST                           | **Approved** — Test Mode only |
| HTTP client + HMAC-SHA256 (no SDK)       | **Approved**                  |
| PO-P3-PAY-PROD                           | **Pending**                   |
| G-PAY credentials + webhook reachability | **Open**                      |
| AUD-CON-006                              | Pending                       |
| Issuer/GST                               | Pending                       |
| ADR-010                                  | Pending                       |

---

## B. Implemented features

| Component                     | Detail                                                                           |
| ----------------------------- | -------------------------------------------------------------------------------- |
| Config                        | `fake` / `razorpay_test` / `razorpay_live` (live unavailable); `RAZORPAY_*` env  |
| `PaymentGatewayAuthorization` | Fail-closed; rejects production env, live keys, incomplete credentials           |
| `RazorpayTestPaymentGateway`  | Orders + checkout/webhook HMAC + payment fetch via Laravel HTTP                  |
| `FakePaymentGateway`          | Preserved for CI                                                                 |
| `PaymentIntentService`        | Test Mode allowed without `live_enabled`; HTTP outside DB lock                   |
| `RazorpayWebhookNormalizer`   | Maps real Razorpay event JSON → domain payload                                   |
| `PaymentEventProcessor`       | Parses **same** signed raw body; idempotent activation                           |
| `CheckoutVerificationService` | Owner callback verify + optional remote capture check                            |
| Webhook                       | `POST /webhooks/razorpay` — CSRF exempt; enabled only when Test Mode flags allow |
| Owner UI                      | Checkout page, simulate (fake), renew entry, Test Mode banner                    |
| Renewal                       | `extendAfterVerifiedRenewal` after verified renewal payment                      |

---

## C. Integration results

### HTTP-mocked (CI)

- Order create amount/currency match
- Checkout signature valid/invalid
- Webhook signature valid/invalid
- Normalized `payment.captured`
- Duplicate event id
- Amount mismatch quarantine
- Owner simulate capture → subscription active
- Webhook closed when disabled; processes when enabled

### Actual Razorpay sandbox

**Not executed.** Requires G-PAY:

1. Test Key ID / Secret / Webhook Secret in secret store
2. `SUBSCRIPTION_PAYMENT_GATEWAY=razorpay_test`
3. `RAZORPAY_MODE=test`
4. `RAZORPAY_TEST_ENABLED=true`
5. `RAZORPAY_WEBHOOK_ENDPOINT_ENABLED=true`
6. Non-production HTTPS URL → `POST {APP_URL}/webhooks/razorpay`
7. Dashboard webhook events: `payment.captured`, `payment.failed` (and `order.paid` if used)

---

## D. Security evidence

| Control                               | Result                             |
| ------------------------------------- | ---------------------------------- |
| No SDK / no new deps                  | Confirmed                          |
| Secrets not exposed to React          | Only public Test key id when ready |
| Payload bound to signed raw body      | Confirmed                          |
| Constant-time HMAC compare            | `hash_equals`                      |
| Production rejects Test Mode          | Confirmed                          |
| Live key id rejected in Test Mode     | Confirmed                          |
| Client amount tampering quarantined   | Confirmed                          |
| Cross-company checkout rejected       | Confirmed                          |
| Idempotent activation                 | Confirmed                          |
| Delayed failure after capture ignored | Confirmed                          |

---

## E. Tests / validation

| Gate                            | Result                                                   |
| ------------------------------- | -------------------------------------------------------- |
| Full Pest                       | **375 tests · 373 passed · 2 skipped · 1905 assertions** |
| Batch F focused                 | Pass                                                     |
| `npm run check` / types / build | Pass                                                     |
| Pint / PHPStan                  | Pass                                                     |

---

## F. G-PAY setup (remaining)

| Step | Action                                                                                  |
| ---- | --------------------------------------------------------------------------------------- |
| 1    | Create Razorpay Test Mode keys                                                          |
| 2    | Create webhook secret; subscribe events                                                 |
| 3    | Place secrets in approved env/secret manager (**never commit**)                         |
| 4    | Point webhook to non-prod `https://<host>/webhooks/razorpay`                            |
| 5    | Enable flags listed above                                                               |
| 6    | Run manual sandbox matrix; attach sanitized order/payment/event ids to a follow-up cert |

Webhook URL format: `{APP_URL}/webhooks/razorpay` (do not invent a deployment hostname here).

---

## G. Remaining decisions

Unchanged Pending: AUD-CON-006, PO-P3-PAY-PROD, issuer/tax, ADR-010.  
Do not treat Batch F as Phase 3 formal acceptance.

---

## H. Final verdict

**IMPLEMENTATION COMPLETE — G-PAY CONFIGURATION PENDING**
