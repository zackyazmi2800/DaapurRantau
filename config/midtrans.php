<?php

return [
    'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
    'server_key' => env('MIDTRANS_SERVER_KEY', env('MIDTRANS_SANDBOX_SERVER_KEY')),
    'client_key' => env('MIDTRANS_CLIENT_KEY', env('MIDTRANS_SANDBOX_CLIENT_KEY')),
    'signature_secret_key' => env('MIDTRANS_SIGNATURE_SECRET_KEY'),
];
