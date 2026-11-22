# Phase 3 Batch E — Subscription Lifecycle & Payment Foundations

**Date:** 22 November 2026  
**Authority:** PO-P3-AUTH · PO-P3-DESIGN-FORMAL · PO-P3-UI-AUTH  
**Initial HEAD:** `163a191d17d0812b022cbb43b03d70f4374c1023` (GitHub Actions #54 SUCCESS)  
**Final HEAD:** `c10fddaefa4bedf758b39ab29db57d271b69109e`  
**Verdict:** **COMPLETE — AUTHORIZED BATCH E SCOPE VERIFIED**

This report does **not** grant Product Owner acceptance of Batch E or Phase 3. No push, no deployment, no live Razorpay.

---

## Distinction of authority

| Layer                          | Status in this batch                                                        |
| ------------------------------ | --------------------------------------------------------------------------- |
| Implemented foundations        | Yes — domain services, migrations, models, config                           |
| Verified simulated behavior    | Yes — FakePaymentGateway + Pest against PostgreSQL                          |
| Production-authorized behavior | **No** — live payments, webhook HTTP, entitlement middleware remain blocked |

---

## 1. Initial / final HEAD and local commits

| Item           | Value                                      |
| -------------- | ------------------------------------------ |
| Baseline       | `163a191d17d0812b022cbb43b03d70f4374c1023` |
| Batch E commit | `c10fddaefa4bedf758b39ab29db57d271b69109e` |
| Remote push    | **None**                                   |
| PO acceptance  | **Not** auto-granted                       |

---

## 2. Subscription lifecycle capabilities (E1 / P3-WS-5)

| Capability                     | Implementation                                                                        | Notes                                     |
| ------------------------------ | ------------------------------------------------------------------------------------- | ----------------------------------------- |
| Status evaluation              | `SubscriptionStatusEvaluator` + `SubscriptionLifecycleStatus`                         | Separate from company admin status        |
| Clock injection                | `SubscriptionClock`                                                                   | Deterministic boundary tests              |
| First-company trial            | `SubscriptionTrialService` + unique `owner_user_id`                                   | 15 days; race-safe; no subsequent trial   |
| Trial on company create        | `CompanyWorkspaceService`                                                             | Nested audited transaction / savepoint    |
| Trial reminders                | Outbox `subscription.trial.activated` / `subscription.trial.reminder`                 | 7/3/1 day windows                         |
| Paid grace                     | Configurable 10-day `grace_ends_on`; evaluator → `PaidGrace`                          | View-only conceptually; no middleware yet |
| Restricted / expired           | Evaluator → `Restricted` after grace / trial expiry                                   | Action matrix Pending AUD-CON-006         |
| Cancellation                   | `requestCancellation` / `withdrawCancellation` at common renewal                      | Audit + immutable snapshot                |
| Activation after payment       | `activateAfterVerifiedPayment`                                                        | Requires verified payment evidence path   |
| Upgrade / downgrade scheduling | `SubscriptionChangeProposal` + `scheduleChange` / `applyScheduledDowngradeIfEligible` | Unpaid = proposals, not entitlements      |
| Common renewal                 | Preserved on contracts; cancellation effective on that date                           | Pricing at renewal boundaries deferred UI |

**Not invented:** AUD-CON-006 route/action entitlement matrix. Evaluator notes Pending cells explicitly.

---

## 3. Capacity, quota, credit, storage (E2)

| Service                        | Behavior verified                                                         |
| ------------------------------ | ------------------------------------------------------------------------- |
| `CompanyCapacityService`       | Defaults 2 branches / 3 seats each; Owner not billable seat               |
| `QuotaAllocationService`       | Tenure proration (floor); three-year yearly refresh windows               |
| `CreditReservationService`     | Idempotent reserve; commit consumes once; release restores availability   |
| Ledger isolation               | Broker vs Partload independent balances                                   |
| Over-reservation               | Concurrent-style second hold rejected when held+requested exceeds balance |
| `StorageReconciliationService` | Wraps document storage usage; 80%/100% thresholds; mismatch reporting     |

LR/Bilty creation gates are **not** wired into non-existent operational modules.

---

## 4. Payment infrastructure and simulation evidence (E3 / P3-WS-6)

| Component         | Detail                                                                                 |
| ----------------- | -------------------------------------------------------------------------------------- |
| Models            | `PaymentIntent`, `PaymentGatewayEvent`, `PaymentReconciliation`                        |
| Gateway interface | `PaymentGateway` — create order, checkout vs webhook signature verify                  |
| Fake adapter      | `FakePaymentGateway` HMAC; bound by default in `AppServiceProvider`                    |
| Live gate         | `config('subscription.payments.live_enabled') = false`; webhook endpoint disabled      |
| Processor         | `PaymentEventProcessor` — signature reject, dedupe, mismatch quarantine, activate once |
| Activation path   | Simulated verified event may activate pending subscription **in tests only**           |
| SDK               | **Not** installed (PO-P1-DEPS)                                                         |

Evidence (Pest):

1. Invalid signature → rejected (does not consume real `gateway_event_id`)
2. Valid capture → paid + subscription active once
3. Duplicate event → `duplicate`
4. Amount mismatch → `quarantined`
5. Failed status → intent failed; subscription stays `pending_payment`
6. No production HTTP webhook route added

---

## 5. Billing foundations (E4)

| Item                    | Implementation                                                    |
| ----------------------- | ----------------------------------------------------------------- |
| Service                 | `SubscriptionInvoiceService`                                      |
| States                  | Draft → Awaiting number approval → Approved → Issued → Emailed    |
| Number edit             | Proposed number editable before approval                          |
| Immutability            | Approved number cannot change (model guard; Super Admin included) |
| Email failure isolation | Failed email leaves approval / payment state intact               |
| Activation coupling     | Invoice approval independent of payment activation                |
| Accounting postings     | **Not** implemented (ADR-010 Pending)                             |

---

## 6. Authorization and isolation

| Check                                         | Result                                      |
| --------------------------------------------- | ------------------------------------------- |
| Owner-only cancellation / lifecycle mutations | Enforced in `SubscriptionLifecycleService`  |
| Company-scoped payment intents / invoices     | FK + tenant context on mutations            |
| Phase 2 / Batch D regressions                 | Related Pest filter green                   |
| No client-trusted payment success flags       | Server verifies signature + amount/currency |

---

## 7. Idempotency / concurrency

| Scenario                             | Result                                            |
| ------------------------------------ | ------------------------------------------------- |
| Credit reserve same idempotency key  | Returns same reservation row                      |
| Credit over-hold after prior reserve | Domain exception; balances unchanged until commit |
| Payment event duplicate              | Marked duplicate; no second activation            |
| Trial unique owner                   | Unique constraint + lockForUpdate                 |
| Payment intent idempotency key       | Unique; create returns existing                   |

---

## 8. Full validation results

| Gate                         | Result                                                                |
| ---------------------------- | --------------------------------------------------------------------- |
| PostgreSQL migrations        | Batch E migration applied on disposable / test DB                     |
| Wayfinder generate           | Pass (CI-equivalent)                                                  |
| `npm run check`              | Pass                                                                  |
| `npm run types:check`        | Pass                                                                  |
| `npm run build`              | Pass                                                                  |
| `vendor/bin/pint --test`     | Pass                                                                  |
| Larastan                     | Pass (0 errors)                                                       |
| `php artisan test --compact` | **358 tests · 356 passed · 2 skipped · 1807 assertions**              |
| Fresh / repeated seeding     | Exercised via Pest + `DatabaseSeederTest`; **not** against working DB |

---

## 9. Remaining blockers (exact decisions)

| ID / Gate     | Requirement                                                         | Blocks                                  |
| ------------- | ------------------------------------------------------------------- | --------------------------------------- |
| AUD-CON-006   | Exact entitlement / state-action matrix                             | Middleware / Branch action restrictions |
| PO-P3-PAY     | Live Razorpay + payment UI authorization                            | Real checkout / webhook HTTP            |
| G-PAY         | Verified gateway credentials and integration approach               | Production settlement                   |
| PO-P1-DEPS    | Package approval if Razorpay SDK required                           | Official SDK binding                    |
| ADR-010       | Accounting posting architecture                                     | Invoice → ledger postings               |
| Production FY | Validated issuer / tax configuration for legally operative invoices | Production invoice issuance             |

Confirmed commercial defaults remain in `config/subscription.php`. Pending matrix cells were **not** converted to Confirmed.

---

## 10. Phase 3 exit criteria readiness

| Exit theme                     | Batch E contribution        | Still open                    |
| ------------------------------ | --------------------------- | ----------------------------- |
| Masters / documents / draft UI | Prior batches (A–D)         | Batch D PO acceptance pending |
| Lifecycle Confirmed rules      | Foundations + tests         | AUD-CON-006 enforcement       |
| Manual Razorpay                | Simulated foundations only  | PO-P3-PAY / G-PAY             |
| Certification evidence         | This report (P3-WS-7 slice) | Full Phase 3 acceptance       |

**Phase 3 is not accepted.** Batch D remains ready for separate PO acceptance.

---

## 11. Recommended next batch

**Batch F (proposed):** After PO direction —

1. Resolve or partially accept AUD-CON-006 for grace/restricted Owner vs Branch surfaces
2. G-PAY / PO-P3-PAY non-production Razorpay sandbox wiring (still no prod charges without explicit PO)
3. Owner billing UI within approved design (orders, invoice list) once payment path authorized
4. Formal Batch D / Batch E PO acceptance records

---

## Final verdict

**COMPLETE — AUTHORIZED BATCH E SCOPE VERIFIED**

Implemented and tested: lifecycle evaluator/trial/grace/cancel/proposals; capacity/quota/credit/storage foundations; fake gateway + webhook processor simulation; invoice state machine. Live payments, entitlement middleware, and Phase 3 acceptance remain out of scope.
