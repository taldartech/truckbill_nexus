<?php

/**
 * Confirmed subscription commercial defaults (Rules §15).
 * Entitlement action matrices remain Pending (AUD-CON-006).
 * Payments: PO-P3-PAY-TEST approved; PO-P3-PAY-PROD Pending.
 */
return [
    'timezone' => env('SUBSCRIPTION_TIMEZONE', 'UTC'),

    'trial' => [
        'days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 15),
        'reminder_days_before_expiry' => [7, 3, 1],
    ],

    'paid_grace' => [
        'days' => (int) env('SUBSCRIPTION_PAID_GRACE_DAYS', 10),
    ],

    'capacity' => [
        'included_branches' => 2, // includes Head Office
        'seats_per_included_branch' => 3,
        'owner_is_billable_seat' => false,
    ],

    'storage' => [
        'alert_percent' => 80,
        'block_percent' => 100,
    ],

    'quota' => [
        'alert_percent' => 80,
        'block_percent' => 100,
    ],

    'billing' => [
        'currency' => 'INR',
        'invoice_number_prefix' => env('SUBSCRIPTION_INVOICE_PREFIX', 'TB'),
        // Financial year starts April (India) — configurable.
        'financial_year_start_month' => (int) env('SUBSCRIPTION_FY_START_MONTH', 4),
    ],

    'payments' => [
        // fake | razorpay_test | razorpay_live (live unavailable)
        'gateway' => env('SUBSCRIPTION_PAYMENT_GATEWAY', 'fake'),
        // Legacy flag — production live charges remain false (PO-P3-PAY-PROD Pending).
        'live_enabled' => false,
        'webhook_endpoint_enabled' => (bool) env('RAZORPAY_WEBHOOK_ENDPOINT_ENABLED', false),
        'fake_webhook_secret' => env('SUBSCRIPTION_FAKE_WEBHOOK_SECRET', 'test-webhook-secret-not-for-production'),
        'razorpay' => [
            'mode' => env('RAZORPAY_MODE', 'test'),
            'test_enabled' => (bool) env('RAZORPAY_TEST_ENABLED', false),
            'key_id' => env('RAZORPAY_KEY_ID', ''),
            'key_secret' => env('RAZORPAY_KEY_SECRET', ''),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET', ''),
            'base_url' => env('RAZORPAY_API_BASE', 'https://api.razorpay.com/v1'),
        ],
    ],
];
