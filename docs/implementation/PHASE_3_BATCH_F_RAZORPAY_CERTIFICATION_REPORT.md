# Phase 3 Batch F — Razorpay Test Mode Certification

**Date:** 22 November 2026  
**Authority:** PO-P3-PAY-TEST **APPROVED** · PO-P1-DEPS **APPROVED** for official `razorpay/razorpay` SDK only  
**Initial Batch F HEAD:** `1a383ebdecba7d87f54669c176b88dabdb28671e`  
**SDK refactor baseline:** `a58f032d9cbb9ef44d01c72e84065df041e0f7c5`  
**Final HEAD:** _(recorded after local commit)_  
**Verdict:** **IMPLEMENTATION COMPLETE — G-PAY SANDBOX EVIDENCE PENDING**

No push. No deployment. No production charges. Phase 3 is not auto-accepted.

---

## Distinction of evidence

| Layer                                         | Status                                                                           |
| --------------------------------------------- | -------------------------------------------------------------------------------- |
| 1. Implemented payment integration            | **Yes** — official SDK adapter, checkout, webhook, verification, renewal         |
| 2. SDK-boundary mocked automated verification | **Yes** — Pest + `RazorpayClient` doubles                                        |
| 3. Actual Razorpay Test Mode sandbox          | **Partial** — order API verified earlier; full card/webhook e2e not claimed here |
| 4. Production readiness                       | **No** — PO-P3-PAY-PROD Pending                                                  |
| 5. Remaining gates                            | AUD-CON-006, issuer/tax, ADR-010                                                 |

---

## A. Authorization recorded

| Gate                                           | Status                                                              |
| ---------------------------------------------- | ------------------------------------------------------------------- |
| PO-P3-PAY-TEST                                 | **Approved** — Test Mode only                                       |
| Official PHP SDK (`razorpay/razorpay`)         | **Approved** (PO-P1-DEPS case) — supersedes prior no-SDK preference |
| Custom Laravel HTTP client adapter             | **Removed**                                                         |
| PO-P3-PAY-PROD                                 | **Pending**                                                         |
| G-PAY credentials + HTTPS webhook reachability | **Open** until sandbox matrix completed                             |
| AUD-CON-006 / Issuer/GST / ADR-010             | Pending                                                             |

---

## B. Implemented features

| Component                                          | Detail                                                                          |
| -------------------------------------------------- | ------------------------------------------------------------------------------- |
| Config                                             | Exactly `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`     |
| Selection                                          | Automatic from `APP_ENV` + Key ID prefix (`rzp_test_` / `rzp_live_`)            |
| `PaymentGatewayAuthorization`                      | Fail-closed; production + live keys rejected                                    |
| `RazorpaySdkClient` / `RazorpayTestPaymentGateway` | Official SDK order/payment/signature APIs                                       |
| `FakePaymentGateway`                               | CI / missing credentials (no env secret)                                        |
| Checkout                                           | Capture-only fulfillment; Owner UX errors without generic aborts                |
| Webhook                                            | `POST /webhooks/razorpay` — CSRF exempt; non-production + test credentials only |

Setup guide: `docs/implementation/RAZORPAY_TEST_MODE_SETUP.md`

---

## C. Original checkout failure (corrected)

**Root cause:** Idempotent reuse of a prior `fake` intent (`order_fake_*`) after enabling Razorpay Test Mode, so Checkout opened an invalid Razorpay order id.

**Corrections:** Gateway-scoped idempotency; capture-only remote verification; checkout error handling; obsolete multi-flag config removed in favor of three env vars + SDK.

---

## D. Security evidence

| Control                                             | Result                  |
| --------------------------------------------------- | ----------------------- |
| Official SDK only (no third-party Laravel wrappers) | Confirmed               |
| Secrets not exposed to React                        | Only public Test key id |
| SDK signature verification (checkout + webhook)     | Confirmed               |
| Payload bound to signed raw webhook body            | Confirmed               |
| Production rejects Razorpay                         | Confirmed               |
| Live key id rejected                                | Confirmed               |
| Authorized ≠ captured                               | Confirmed               |
| Idempotent activation                               | Confirmed               |

---

## E. Tests / validation

| Gate                              | Result                                                   |
| --------------------------------- | -------------------------------------------------------- |
| Full Pest                         | **382 tests · 380 passed · 2 skipped · 1945 assertions** |
| Batch E/F focused                 | Pass                                                     |
| Composer validate / platform-reqs | Pass (`razorpay/razorpay` 2.9.3; PHP 8.5)                |
| `npm run check` / types / build   | Pass                                                     |
| Pint / PHPStan                    | Pass                                                     |

---

## F. G-PAY remaining

1. Confirm Test Mode keys + webhook secret in secret store
2. Optional HTTPS tunnel → `{APP_URL}/webhooks/razorpay`
3. Dashboard events: `payment.captured`, `payment.failed`
4. Manual sandbox matrix with sanitized order/payment/event ids

---

## G. Remaining decisions

Unchanged Pending: AUD-CON-006, PO-P3-PAY-PROD, issuer/tax, ADR-010.  
Do not treat Batch F / SDK refactor as Phase 3 formal acceptance.

---

## H. Final verdict

**IMPLEMENTATION COMPLETE — G-PAY SANDBOX EVIDENCE PENDING**
