<?php

$environment = env('MIDTRANS_ENVIRONMENT');

return [
    // Must be set explicitly to "sandbox" or "production"; anything else disables Midtrans.
    'environment' => is_string($environment) && trim($environment) !== '' ? strtolower(trim($environment)) : null,
    'webhook_enabled' => (bool) env('MIDTRANS_WEBHOOK_ENABLED', false),

    // Hosts are fixed per environment so a misconfigured URL cannot redirect money flows.
    'sandbox' => [
        'server_key' => env('MIDTRANS_SERVER_KEY', env('MIDTRANS_SANDBOX_SERVER_KEY')),
        'client_key' => env('MIDTRANS_CLIENT_KEY', env('MIDTRANS_SANDBOX_CLIENT_KEY')),
        'signature_secret_key' => env('MIDTRANS_SIGNATURE_SECRET_KEY'),
        'api_url' => 'https://api.sandbox.midtrans.com',
        'snap_url' => 'https://app.sandbox.midtrans.com',
    ],
    'production' => [
        'server_key' => env('MIDTRANS_PRODUCTION_SERVER_KEY'),
        'client_key' => env('MIDTRANS_PRODUCTION_CLIENT_KEY'),
        'signature_secret_key' => env('MIDTRANS_PRODUCTION_SIGNATURE_SECRET_KEY'),
        'api_url' => 'https://api.midtrans.com',
        'snap_url' => 'https://app.midtrans.com',
    ],

    'refund' => [
        // Payment types supported by the Midtrans Refund API.
        'payment_types' => ['credit_card', 'gopay', 'shopeepay', 'dana', 'ovo', 'qris', 'kredivo', 'akulaku'],
        // Payment types that only accept a full refund.
        'full_only' => ['shopeepay', 'ovo'],
    ],
];
