<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitation expiry
    |--------------------------------------------------------------------------
    |
    | Legacy Admin default was 7 days. Nexus keeps the same customer experience.
    |
    */

    'invitation_ttl_days' => (int) env('LEAD_ONBOARDING_INVITATION_TTL_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Email OTP (approved Nexus security rules)
    |--------------------------------------------------------------------------
    |
    | Six-digit OTP, three-minute validity, rate-limited send/verify.
    |
    */

    'otp_length' => 6,
    'otp_ttl_minutes' => (int) env('LEAD_ONBOARDING_OTP_TTL_MINUTES', 3),
    'otp_max_attempts' => (int) env('LEAD_ONBOARDING_OTP_MAX_ATTEMPTS', 5),
    'otp_send_throttle_seconds' => (int) env('LEAD_ONBOARDING_OTP_SEND_THROTTLE_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Public website handoff
    |--------------------------------------------------------------------------
    */

    'frontend_url' => rtrim((string) env('LEAD_ONBOARDING_FRONTEND_URL', env('WEBSITE_URL', 'http://127.0.0.1:8080')), '/'),
    'login_url' => rtrim((string) env('NEXUS_LOGIN_URL', env('APP_URL', 'http://localhost:8000')), '/').'/login',

    /*
    |--------------------------------------------------------------------------
    | Required policy keys (timestamp-only acceptance)
    |--------------------------------------------------------------------------
    */

    'policies' => [
        'terms' => env('LEAD_ONBOARDING_POLICY_TERMS_URL', 'https://truckbill.in/policies/terms'),
        'privacy' => env('LEAD_ONBOARDING_POLICY_PRIVACY_URL', 'https://truckbill.in/policies/privacy'),
        'refund' => env('LEAD_ONBOARDING_POLICY_REFUND_URL', 'https://truckbill.in/policies/refund'),
        'acceptable_use' => env('LEAD_ONBOARDING_POLICY_AUP_URL', 'https://truckbill.in/policies/acceptable-use'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CORS origins permitted to call public onboarding API
    |--------------------------------------------------------------------------
    */

    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env(
            'LEAD_ONBOARDING_ALLOWED_ORIGINS',
            'http://127.0.0.1:8080,http://localhost:8080,https://truckbill.in,https://www.truckbill.in'
        ))
    ))),

];
