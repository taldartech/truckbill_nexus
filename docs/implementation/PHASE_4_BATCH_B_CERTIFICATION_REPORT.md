# Phase 4 Batch B Certification Report

**Decision:** PO-P4-BATCH-B-AUTH — **APPROVED** (23 November 2026)  
**Scope:** Secure website enquiry webhook + manual Branch enquiry backend  
**Baseline HEAD (pre-Batch B):** `71150767612ae67aeb276e9d3279248468e5d463`  
**Implementation commit:** `44322ed9248f336d19fee4b2a2953954cb2c85eb`  
**Certification docs commit:** `64d4afac7a6c2c48f620fba950a3e53745dd5b30`  
**Status:** **BATCH B COMPLETE — READY FOR BATCH C REVIEW**

---

## 1. Authorization

| Decision              | Status                          |
| --------------------- | ------------------------------- |
| PO-P4-AUTH            | APPROVED (unchanged)            |
| PO-P4-BATCH-B-AUTH    | **APPROVED**                    |
| PO-P4-WEBHOOK V1      | APPROVED — endpoint implemented |
| PO-P4-DESIGN-FORMAL   | **Pending** (no product UI)     |
| PO-P4-CNV-CARDINALITY | **Pending**                     |
| Phase 3 EX-01…EX-07   | Unchanged                       |
| PO-P3-PAY-PROD        | Pending (no live Razorpay)      |

---

## 2. Implementation summary

| Area                | Implementation                                                                                        |
| ------------------- | ----------------------------------------------------------------------------------------------------- |
| Application service | `App\Domains\Enquiry\EnquiryService`                                                                  |
| Manual HTTP         | `Branch\EnquiryController` JSON create/update/index/show                                              |
| Webhook HTTP        | `Webhooks\EnquiryWebhookController` → `EnquiryWebhookIntakeService`                                   |
| Auth                | `EnquiryWebhookAuthenticator` + Batch A `EnquiryWebhookCredentialService`                             |
| Replay              | `EnquiryWebhookReplayGuard` (timestamp + nonce table)                                                 |
| Idempotency         | Platform `IdempotencyGuard` scope `webhook.enquiries.create`                                          |
| Durable dedup       | `enquiry_external_references.request_fingerprint` + unique `(company_id, external_reference)`         |
| Numbering           | `DocumentNumberAllocator` company-wide Enquiry sequence                                               |
| Audit / outbox      | `AuditedTransaction` + `AuditWriter::usingSystemActor('enquiry-website-webhook')` + `enquiry.created` |
| Migration           | `2026_11_23_020000_add_enquiry_webhook_dedup_and_replay_foundations.php`                              |
| Config              | `config/enquiry_webhook.php`                                                                          |
| CSRF                | Exact path `webhooks/enquiries` exempted in `bootstrap/app.php`                                       |

### Routes

- `POST /webhooks/enquiries` (`webhooks.enquiries`)
- `GET|POST /branch/companies/{company}/branches/{branch}/enquiries`
- `GET|PATCH .../enquiries/{enquiry}`

### Permissions

- Create: `enquiry.create` (no Owner blanket; no `platform.*` implication)
- Edit: `enquiry.edit` + required remark
- View: `enquiry.view`
- Unassigned queue visibility on list: requires `enquiry.assign` (documented Batch C visibility gate)

---

## 3. Manual enquiry behaviour

- Tenant from trusted route company/branch only
- Customer Party must belong to company with active Customer role
- Catalogue Truck/Goods/Package types must belong to company owner and be active
- ≥1 goods row; vehicle quantity ≥1; vehicle type arrays prohibited
- Initial status `new` + status history
- Atomic audit + `enquiry.created` outbox
- Narrow edit: header + goods replace; terminal statuses blocked; no assignment/quotation/conversion

---

## 4. Website webhook behaviour

- Bearer / `X-TruckBill-Webhook-Token` authentication
- Company from credential only; unassigned queue (`branch_id = null`)
- JSON only; multipart rejected
- Rate limit, timestamp skew, nonce replay, request size / goods-row bounds
- Durable external-reference fingerprint conflicts
- Idempotent retries return original enquiry without duplicate numbers/events

---

## 5. Tests

| Suite                                              | Result        |
| -------------------------------------------------- | ------------- |
| `tests/Feature/Phase4/Phase4BatchBEnquiryTest.php` | Pass          |
| `tests/Feature/Phase4/Phase4BatchBWebhookTest.php` | Pass          |
| Combined Batch B                                   | **22 passed** |

Coverage includes authorization negatives, catalogue/tenant isolation, subscription mutation block, webhook auth failures, idempotency/external-ref conflicts, rate limit, CSRF exact exemption assertion, rollback, and sequential durable-dedup concurrency safety on PostgreSQL.

---

## 6. Clean Code checklist (Batch B)

| #   | Check                                                               | Result                           |
| --- | ------------------------------------------------------------------- | -------------------------------- |
| 1   | Thin controllers                                                    | Pass                             |
| 2   | Shared EnquiryService                                               | Pass                             |
| 3   | Reuse of access/RBAC/audit/outbox/idempotency/numbering/credentials | Pass                             |
| 4   | No broad Throwable→business mapping                                 | Pass                             |
| 5   | PG uniqueness for external refs / nonces                            | Pass                             |
| 6   | Tenant never from client payload                                    | Pass                             |
| 7   | No product UI / Batch C workflows                                   | Pass                             |
| 8   | Explicit types / focused methods                                    | Pass                             |
| 9   | Configured technical security params documented as non-PO           | Pass                             |
| 10  | Soft-delete goods replace uses forceDelete for position uniqueness  | Pass (fix during review)         |
| 11  | System actor for website audit                                      | Pass (`enquiry-website-webhook`) |
| 12  | Tests assert observable behaviour                                   | Pass                             |

---

## 7. Explicit non-claims

- No Inertia/React Enquiry UI
- No Batch C assignment/follow-up
- No quotations / conversion / LR ops
- No live Razorpay; no production deploy; no push
- Unassigned queue list gated by `enquiry.assign` pending Batch C visibility decision
- Webhook technical limits are engineering defaults, not PO-approved policy numbers

---

## 8. Remaining gates

| Gate                      | State                                                 |
| ------------------------- | ----------------------------------------------------- |
| Batch B backend           | **Complete**                                          |
| Batch C backend readiness | Technically unblocked for assignment/follow-up design |
| UI formal approval        | Pending                                               |
| Conversion cardinality    | Pending                                               |
| Phase 3 exceptions        | Unchanged                                             |

## 9. Verdict

**BATCH B COMPLETE — READY FOR BATCH C REVIEW**
