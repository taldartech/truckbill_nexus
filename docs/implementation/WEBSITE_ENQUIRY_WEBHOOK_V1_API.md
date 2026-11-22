# Website Enquiry Webhook V1 API Contract

**Status:** Implemented (Phase 4 Batch B)  
**Decision:** PO-P4-WEBHOOK — APPROVED — V1  
**Authorization:** PO-P4-BATCH-B-AUTH — APPROVED (23 November 2026)

This document is the integration contract for the sibling `website/` project.  
**Do not place real bearer tokens in this file or in tickets.**

---

## 1. Endpoint

| Item         | Value                                                    |
| ------------ | -------------------------------------------------------- |
| Method       | `POST`                                                   |
| Path         | `/webhooks/enquiries`                                    |
| Route name   | `webhooks.enquiries`                                     |
| Auth session | Not required (no TruckBill login)                        |
| CSRF         | Exempt **only** for this exact path (alongside Razorpay) |

Use HTTPS in every non-local environment.

---

## 2. Authentication

Send the company-scoped webhook token via either:

1. `Authorization: Bearer <token>`
2. `X-TruckBill-Webhook-Token: <token>`

Rules:

- Tokens are hashed at rest (`CompanyEnquiryWebhookCredential`).
- Company is resolved **only** from the verified credential.
- Payload `company_id` / `branch_id` / `owner_id` are rejected (403).
- Query-string tokens are rejected.
- Missing / invalid / revoked / expired credentials → **401**.
- Plaintext tokens must never appear in responses or logs.

Credential create/rotate/revoke remains an Owner/ops concern (Batch A service; no public management endpoint in Batch B).

---

## 3. Required headers

| Header                                         | Required | Notes                                                 |
| ---------------------------------------------- | -------- | ----------------------------------------------------- |
| `Authorization` or `X-TruckBill-Webhook-Token` | Yes      | Bearer token                                          |
| `Content-Type`                                 | Yes      | `application/json` only                               |
| `Idempotency-Key`                              | Yes      | Stable UUID (or equivalent opaque key)                |
| `X-TruckBill-Timestamp`                        | Yes      | Unix seconds (UTC). Freshness window default **300s** |
| `X-TruckBill-Nonce`                            | Yes      | Opaque ≤128 chars; unique per company for nonce TTL   |

Technical defaults (engineering choices — **not** Product Owner policy):

| Parameter             | Config key                               | Default |
| --------------------- | ---------------------------------------- | ------- |
| Timestamp skew        | `enquiry_webhook.timestamp_skew_seconds` | 300     |
| Nonce TTL             | `enquiry_webhook.nonce_ttl_seconds`      | 600     |
| Rate limit            | `enquiry_webhook.rate_limit_per_minute`  | 60      |
| Max goods rows        | `enquiry_webhook.max_goods_rows`         | 50      |
| Max body bytes        | `enquiry_webhook.max_content_length`     | 65536   |
| Idempotency TTL hours | `enquiry_webhook.idempotency_ttl_hours`  | 72      |

---

## 4. Request schema (JSON)

```json
{
    "external_reference": "site-lead-2026-0001",
    "customer_name": "Acme Traders",
    "customer_phone": "9876543210",
    "customer_email": "ops@example.com",
    "pickup_location": "Pune",
    "delivery_location": "Mumbai",
    "vehicle_type_code": "TATA407",
    "vehicle_quantity": 1,
    "expected_loading_date": "2026-12-15",
    "instructions": "Call on arrival",
    "goods": [
        {
            "goods_type_code": "GEN",
            "package_type_code": "BAG",
            "goods_description": "Tiles",
            "package_count": 20,
            "actual_weight": 200,
            "charged_weight": 220
        }
    ]
}
```

### Field rules

| Field                       | Required    | Notes                                                         |
| --------------------------- | ----------- | ------------------------------------------------------------- |
| `external_reference`        | Yes         | Durable company-scoped dedup key (≤128)                       |
| `customer_name`             | Yes         | External contact only — **no** Party Master auto-create/merge |
| `customer_phone`            | No          |                                                               |
| `customer_email`            | No          | Must be valid if present                                      |
| `pickup_location`           | Yes         |                                                               |
| `delivery_location`         | Yes         |                                                               |
| `vehicle_type_code`         | Yes         | Active Owner catalogue Truck Type code for the company owner  |
| `vehicle_quantity`          | Yes         | Integer ≥ 1; exactly one vehicle type (no arrays)             |
| `expected_loading_date`     | No          | Date                                                          |
| `instructions`              | No          |                                                               |
| `goods`                     | Yes         | ≥1 row, ≤ max_goods_rows                                      |
| `goods[].goods_type_code`   | Conditional | Required unless `goods_description` present                   |
| `goods[].package_type_code` | No          |                                                               |
| `goods[].goods_description` | Conditional | Required unless goods type code present                       |
| weights / package_count     | No          | Nonnegative decimals                                          |

Forbidden: multipart uploads, file fields, tenant identifiers, `created_by_user_id`, `assigned_user_id`, `status`, `source`.

---

## 5. Success response

**201 Created** — first successful persistence for the idempotency key  
**200 OK** — idempotent replay or durable external-reference hit with same semantic fingerprint

```json
{
    "data": {
        "id": "uuid",
        "enquiry_number": "1",
        "source": "website",
        "status": "new",
        "branch_id": null,
        "external_reference": "site-lead-2026-0001",
        "pickup_location": "Pune",
        "delivery_location": "Mumbai",
        "vehicle_quantity": 1,
        "goods": []
    },
    "meta": {
        "created": true,
        "idempotent_replay": false
    }
}
```

Website enquiries are always **unassigned** (`branch_id = null`, no assigned user). Assignment is Batch C.

---

## 6. Error responses

| Status | Meaning                                                                                                                     |
| ------ | --------------------------------------------------------------------------------------------------------------------------- |
| 401    | Missing/invalid/revoked/expired token; inactive company                                                                     |
| 403    | Client-supplied tenant identifiers                                                                                          |
| 409    | Replay (stale timestamp / reused nonce), idempotency conflict, external-reference content conflict, in-progress idempotency |
| 422    | Validation / unsupported content type / oversized body                                                                      |
| 429    | Rate limited (`Retry-After` header)                                                                                         |
| 500    | Unexpected server failure (no token leakage)                                                                                |

---

## 7. Idempotency and durable deduplication

1. **Idempotency-Key** (platform `IdempotencyGuard`, scope `webhook.enquiries.create`): same key + same semantic fingerprint → stored result; same key + different fingerprint → 409.
2. **external_reference** (PostgreSQL unique `(company_id, external_reference)` + `request_fingerprint`): durable beyond idempotency TTL; same ref + same fingerprint → existing enquiry; same ref + different fingerprint → 409; other companies may reuse the same reference string independently.
3. Different idempotency keys cannot bypass external-reference uniqueness.
4. Retries must not allocate a second enquiry number or emit a second `enquiry.created` outbox event.

---

## 8. Retry guidance for the website team

1. Generate a stable `Idempotency-Key` per user submit.
2. On network uncertainty, retry with the **same** key, payload, timestamp freshness, and a **new** nonce only if the prior attempt never consumed a nonce (prefer same key short-circuit).
3. Do not change payload fields for the same `external_reference` after a successful create.
4. Treat 409 external-reference conflicts as permanent business conflicts requiring a new reference or human review.
5. Back off on 429.

---

## 9. Token rotation and revocation

- Rotate via `EnquiryWebhookCredentialService::rotate` (optional immediate revoke of prior).
- Revoked or expired credentials fail closed with 401.
- Overlap/grace duration is a technical parameter, not a PO-prescribed SLA.

---

## 10. Sanitized example (curl)

```bash
curl -X POST "https://app.example/webhooks/enquiries" \
  -H "Authorization: Bearer tb_enq_REDACTED" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 9f1c0a2e-1111-2222-3333-444455556666" \
  -H "X-TruckBill-Timestamp: 1730000000" \
  -H "X-TruckBill-Nonce: 7b2e9c1a-aaaa-bbbb-cccc-ddddeeeeffff" \
  -d @enquiry.json
```
