# Operations Runbook (WS-7)

**Stack:** Laravel 13, PostgreSQL, Redis (phpredis), queue workers

## Application troubleshooting

1. Confirm `.env` has valid `APP_KEY`, `DB_*`, `REDIS_*`, `QUEUE_CONNECTION`.
2. Check logs: `storage/logs/laravel.log` (or configured channel).
3. Correlate requests/jobs with `X-Correlation-ID` / log field `correlation_id`.
4. Do not paste passwords, tokens, or full auth headers into tickets.

## Liveness vs readiness

| Endpoint            | Purpose                                                 |
| ------------------- | ------------------------------------------------------- |
| `GET /up`           | Framework liveness                                      |
| `GET /health/ready` | Readiness: database, redis, queue config, outbox counts |

Ready responses never include credentials. `503` means degraded.

## Database connectivity

```bash
php artisan db:show
php artisan migrate --force
```

If health reports `database unreachable`, verify PostgreSQL is listening and `DB_*` credentials.

## Redis connectivity

```bash
redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" ping
```

Queue/cache/session depend on Redis in non-test environments (`ADR-007`).

## Queue worker restart

```bash
php artisan queue:work redis --queue=outbox,default --sleep=1 --tries=1
# graceful restart after deploy:
php artisan queue:restart
```

## Failed job investigation

```bash
php artisan queue:failed
php artisan queue:retry {id}
```

Inspect payloads carefully; redact secrets before sharing. Prefer application `LogRedactor` patterns.

## Outbox recovery

```bash
php artisan outbox:dispatch
php artisan outbox:dispatch --limit=50
```

Stuck `processing` rows (worker crash): after TTL investigation, reset eligible rows to `pending` with a future `available_at` only with operator judgment. `dead` rows need root-cause fix before replay.

## Pending private-file cleanup

Retry with `PrivateFileService::cleanupReplaced()` for rows where `pending_cleanup = true`. Do not delete objects without matching metadata. See `FILE_STORAGE_FOUNDATION.md`.

## Log correlation

1. Capture `X-Correlation-ID` from the HTTP response or client request.
2. Search logs for `correlation_id`.
3. Follow the same id on outbox messages and `ProcessOutboxMessageJob` context.

## Safe diagnostics

Never log or share: passwords, access tokens, session secrets, API keys, encryption keys, full `Authorization` headers, uploaded document contents, or unnecessary personal data.
