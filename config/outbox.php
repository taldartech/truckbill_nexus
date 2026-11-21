<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outbox dispatcher
    |--------------------------------------------------------------------------
    */

    'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 50),

    'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Retry backoff (seconds) — attempt N uses min(base * 2^(N-1), max)
    |--------------------------------------------------------------------------
    */

    'retry_base_seconds' => (int) env('OUTBOX_RETRY_BASE_SECONDS', 5),

    'retry_max_seconds' => (int) env('OUTBOX_RETRY_MAX_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Idempotency retention
    |--------------------------------------------------------------------------
    */

    'idempotency_ttl_hours' => (int) env('OUTBOX_IDEMPOTENCY_TTL_HOURS', 72),

];
