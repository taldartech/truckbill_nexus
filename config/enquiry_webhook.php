<?php

/**
 * Technical security parameters for website enquiry webhook V1.
 * These defaults are engineering choices — not Product Owner-prescribed policy.
 */
return [
    'timestamp_skew_seconds' => (int) env('ENQUIRY_WEBHOOK_TIMESTAMP_SKEW', 300),
    'nonce_ttl_seconds' => (int) env('ENQUIRY_WEBHOOK_NONCE_TTL', 600),
    'rate_limit_per_minute' => (int) env('ENQUIRY_WEBHOOK_RATE_LIMIT', 60),
    'max_goods_rows' => (int) env('ENQUIRY_WEBHOOK_MAX_GOODS_ROWS', 50),
    'max_content_length' => (int) env('ENQUIRY_WEBHOOK_MAX_CONTENT_LENGTH', 65536),
    'idempotency_ttl_hours' => (int) env('ENQUIRY_WEBHOOK_IDEMPOTENCY_TTL_HOURS', 72),
];
