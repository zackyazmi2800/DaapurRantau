<?php

$environment = env('MIDTRANS_ENVIRONMENT');

return [
    // Must be set explicitly to "sandbox" or "production"; anything else disables Midtrans.
    'environment' => is_string($environment) && trim($environment) !== '' ? strtolower(trim($environment)) : null,
    'webhook_enabled' => (bool) env('MIDTRANS_WEBHOOK_ENABLED', false),
    // Hard upper bound for a Signature Secret Key rotation window; a later *_PREVIOUS_UNTIL disables the old key.
    'signature_rotation_max_hours' => 168,
    'webhook_rate_limit' => max(10, min(1000, (int) env('MIDTRANS_WEBHOOK_RATE_LIMIT', 120))),

    // Hosts are fixed per environment so a misconfigured URL cannot redirect money flows.
    'sandbox' => [
        'server_key' => env('MIDTRANS_SERVER_KEY', env('MIDTRANS_SANDBOX_SERVER_KEY')),
        'client_key' => env('MIDTRANS_CLIENT_KEY', env('MIDTRANS_SANDBOX_CLIENT_KEY')),
        'signature_secret_key' => env('MIDTRANS_SIGNATURE_SECRET_KEY'),
        // Rotation (N5): the replaced key keeps verifying notifications until the given moment only.
        'signature_secret_key_previous' => env('MIDTRANS_SIGNATURE_SECRET_KEY_PREVIOUS'),
        'signature_previous_until' => env('MIDTRANS_SIGNATURE_PREVIOUS_UNTIL'),
        'api_url' => 'https://api.sandbox.midtrans.com',
        'snap_url' => 'https://app.sandbox.midtrans.com',
    ],
    'production' => [
        'server_key' => env('MIDTRANS_PRODUCTION_SERVER_KEY'),
        'client_key' => env('MIDTRANS_PRODUCTION_CLIENT_KEY'),
        'signature_secret_key' => env('MIDTRANS_PRODUCTION_SIGNATURE_SECRET_KEY'),
        'signature_secret_key_previous' => env('MIDTRANS_PRODUCTION_SIGNATURE_SECRET_KEY_PREVIOUS'),
        'signature_previous_until' => env('MIDTRANS_PRODUCTION_SIGNATURE_PREVIOUS_UNTIL'),
        'api_url' => 'https://api.midtrans.com',
        'snap_url' => 'https://app.midtrans.com',
    ],

    // Payment anomalies (refund excess, closed refunds listed by Midtrans) are always logged at
    // error level; set PAYMENT_ALERT_EMAIL to also mail them through the application mailer.
    'alerts' => [
        'mail_to' => env('PAYMENT_ALERT_EMAIL'),
    ],

    'refund' => [
        // Payment types supported by the Midtrans Refund API.
        'payment_types' => ['credit_card', 'gopay', 'shopeepay', 'dana', 'ovo', 'qris', 'kredivo', 'akulaku'],
        // Payment types that only accept a full refund.
        'full_only' => ['shopeepay', 'ovo'],
        // Midtrans accepts a reattempt with the same refund_key for at most 7 days after the first
        // attempt; the window is counted from intent creation and keeps a 24 hour safety margin.
        'reattempt_window_hours' => 144,
        'reattempt_cooldown_minutes' => 10,
        // A "pending" attempt whose outcome was never recorded becomes "unknown" after this long.
        'pending_stale_minutes' => 30,
        // A closure request must be approved by a second OS account within this window.
        'closure_request_ttl_hours' => 72,
    ],
];
