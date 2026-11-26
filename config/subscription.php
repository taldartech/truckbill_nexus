<?php

/**
 * Confirmed subscription commercial defaults (Rules §15).
 * Entitlement action matrices remain Pending (AUD-CON-006).
 * Payments: PO-P3-PAY-TEST approved (official Razorpay PHP SDK); PO-P3-PAY-PROD Pending.
 *
 * Razorpay env (exactly three):
 * - RAZORPAY_KEY_ID
 * - RAZORPAY_KEY_SECRET
 * - RAZORPAY_WEBHOOK_SECRET
 *
 * Gateway selection is automatic: non-production + rzp_test_* credentials → Test Mode SDK;
 * testing / missing credentials → FakePaymentGateway; production Razorpay remains blocked.
 */
return [
    'timezone' => env('SUBSCRIPTION_TIMEZONE', 'UTC'),

    'trial' => [
        'days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 15),
        'include_accounting' => (bool) env('SUBSCRIPTION_TRIAL_INCLUDE_ACCOUNTING', false),
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
        // Display / threshold allocation until catalogue-driven storage entitlements are finalized.
        'default_allocation_bytes' => (int) env('SUBSCRIPTION_STORAGE_BYTES', 5 * 1024 * 1024 * 1024),
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
        // Legacy flag — production live charges remain false (PO-P3-PAY-PROD Pending).
        'live_enabled' => false,
        'razorpay' => [
            'key_id' => env('RAZORPAY_KEY_ID', ''),
            'key_secret' => env('RAZORPAY_KEY_SECRET', ''),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET', ''),
        ],
    ],
];
