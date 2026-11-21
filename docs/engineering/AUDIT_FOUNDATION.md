# Audit Foundation (WS-4 / ADR-003)

**Status:** Implemented in Phase 1  
**Authority:** ADR-003 Accepted (PO-P1-ADR-003); ADR-004 Accepted  
**Related requirements:** TB-AUD-001, TB-AUD-002, TB-AUD-003

## Schema (`audit_entries`)

| Column                                   | Purpose                                                         |
| ---------------------------------------- | --------------------------------------------------------------- |
| `uuid`                                   | Stable unique identifier                                        |
| `actor_type` / `actor_id`                | Trusted actor (`user` + auth id, or `system` / `phase1-system`) |
| `company_id`, `branch_id`, `owner_id`    | Tenant context from `TenantContextHolder`                       |
| `is_internal`, `is_owner_scope`          | Distinct Internal Team / Owner-scope flags                      |
| `subject_type`, `subject_id`             | Audited entity                                                  |
| `action`                                 | `created` \| `updated` \| `deleted` \| `cancelled`              |
| `before`, `after`                        | JSONB change payloads (sensitive keys redacted)                 |
| `remark`                                 | Required for update/delete/cancel                               |
| `cancellation_reason`, `cancellation_at` | Required for `cancelled`                                        |
| `correlation_id`                         | Optional request/job correlation                                |
| `created_at`                             | Append-only timestamp (no `updated_at`)                         |

Indexes: `company_id`, `(company_id, created_at)`, `(subject_type, subject_id)`, `correlation_id`, unique `uuid`.

## Writer API

```php
$writer->write(
    action: AuditAction::Updated,
    subjectType: $model::class,
    subjectId: $model->getKey(),
    remark: 'Corrected freight',
    before: $before,
    after: $after,
);
```

Tenant/actor are never accepted from request payloads. Use `assertNoClientTenantFields()` when validating caller input maps.

## Transaction pattern

```php
(new AuditedTransaction)->run(function () use ($writer) {
    $model = /* mutate */;
    $writer->write(/* ... */);
    // WS-6 extension point: insert outbox row(s) in this same transaction.
    return $model;
});
```

Uses Laravel `DB::transaction` (savepoints for nesting). Exceptions propagate; no external side effects before commit.

## Remark rules

| Action            | Remark               | Extra                                              |
| ----------------- | -------------------- | -------------------------------------------------- |
| created           | Optional             | —                                                  |
| updated / deleted | Required (non-empty) | —                                                  |
| cancelled         | Required             | `cancellation_reason` + `cancellation_at` required |

## Immutability

- Eloquent `updating` / `deleting` hooks throw `AuditImmutabilityException`.
- Model is fully guarded; only `AuditEntry::insertAppendOnly()` writes.
- No audit management UI/API in Phase 1.
- Database triggers were not added: application-level control is sufficient for Phase 1; triggers remain a later hardening option with ops trade-offs.

## Sensitive fields

`SensitiveFieldFilter` redacts keys such as `password`, `*_token`, `secret*`, 2FA secrets, etc. to `[redacted]`.

## Harness

`audit_harness_records` + `AuditHarnessService` exist only to exercise WS-4 rules. They are not a product module.

## Actor limitation (Phase 1)

Without Phase 2 identity, unauthenticated/system paths record `actor_type=system`, `actor_id=phase1-system`. Authenticated Fortify users record `actor_type=user` with the auth id.

## WS-5 / WS-6

Not implemented here. Outbox insert belongs inside `AuditedTransaction::run` after the audit write (WS-6).

## Tests

`tests/Feature/AuditAtomicityTest.php` — Pest suite covering success, remark rejection, immutability, rollback, tenant fail-closed, sensitive redaction, and ordering.
