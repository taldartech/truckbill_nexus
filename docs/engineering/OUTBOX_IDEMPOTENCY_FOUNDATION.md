# Outbox, Idempotency and Queue Foundation (WS-6 / ADR-009)

**Status:** Implemented in Phase 1  
**Authority:** ADR-009 Accepted (PO-P1-ADR-009)  
**Related requirements:** TB-EVT-001, TB-SEC-003

## Atomic unit

Uses existing `AuditedTransaction`:

```text
BEGIN
  1. Business mutation
  2. AuditWriter::write(...)
  3. OutboxWriter::enqueue(...)
COMMIT
```

Publish happens **after** commit via `outbox:dispatch` / `OutboxDispatcher` (never inside the open business transaction).

## Outbox schema (`outbox_messages`)

Event identity (`event_id`), type, filtered JSON payload, tenant flags, correlation id, status (`pending` → `processing` → `published` | retry `pending` | `dead`), attempts, backoff `available_at`, lock metadata, masked `last_error`.

## Claiming

`FOR UPDATE SKIP LOCKED` batch claim. Concurrent workers cannot process the same row. Claimed rows dispatch `ProcessOutboxMessageJob` on queue `outbox` with serialized tenant context (`TenantAware` + `RequiresTenantContext`).

## Idempotency

`IdempotencyGuard` uniqueness: `(scope, company_id, idempotency_key)`.

| Case                        | Behavior                             |
| --------------------------- | ------------------------------------ |
| First delivery              | Run callback; store completed result |
| Replay same key + same hash | Return stored result; no re-run      |
| Same key + different hash   | `IdempotencyConflictException`       |
| Concurrent in-progress      | `IdempotencyInProgressException`     |
| Prior failure               | Reclaim and retry                    |
| Expired record              | Deleted and re-created               |

Outbox consumers use scope `outbox.consumer:{event_type}` and key = `event_id`.

**Limitation:** Idempotency is per-company scoped. Cross-company key reuse is allowed by design (tenant isolation). Branch/owner are stored for diagnostics but are not part of the uniqueness key in Phase 1.

## Retries and dead letters

Retryable consumer failures return the row to `pending` with exponential backoff until `max_attempts`, then `dead`. Job `tries = 1`; outbox owns retry state. Inspect `dead` rows / `failed_jobs` for ops recovery. Re-drive by resetting eligible `pending`/`dead` rows operationally after root-cause fix (manual; no auto-poison replay).

## Tenant rules

- Enqueue requires tenant context with company id.
- Jobs restore tenant from payload; mismatch with message company fails closed.
- Middleware always clears tenant context after success or failure.

## Harness

`OutboxHarnessService` + `outbox_harness_effects` prove atomicity and single side effect. Not a product module.

## Commands

```bash
php artisan outbox:dispatch
php artisan outbox:dispatch --limit=20
```

## Tests

`tests/Feature/OutboxIdempotencyTest.php`
