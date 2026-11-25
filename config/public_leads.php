<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Website → Nexus CRM Lead capture (server-to-server)
    |--------------------------------------------------------------------------
    |
    | The public website posts via submit.php using X-Api-Key. The key never
    | appears in browser JavaScript. Leave empty in environments that must
    | reject all public lead posts.
    |
    */

    'api_key' => (string) env('WEBSITE_LEAD_API_KEY', ''),

    'rate_limit_per_minute' => (int) env('WEBSITE_LEAD_RATE_LIMIT_PER_MINUTE', 30),

    /*
    | Idempotent replay window for the same Idempotency-Key / fingerprint.
    */
    'idempotency_ttl_seconds' => (int) env('WEBSITE_LEAD_IDEMPOTENCY_TTL', 600),

    /*
    | When an open lead already exists for the same email + product within
    | LeadService::DUPLICATE_WINDOW_DAYS, append an enquiry activity instead
    | of creating another lead row.
    */
    'append_enquiry_on_open_duplicate' => (bool) env('WEBSITE_LEAD_APPEND_ON_DUPLICATE', true),

];
