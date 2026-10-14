<?php

declare(strict_types=1);

return [
    'provider_url' => env('DEMO_PROVIDER_URL', 'http://127.0.0.1:8099'),
    'provider_key' => env('DEMO_PROVIDER_KEY', ''),
    'webhook_secret' => env('DEMO_WEBHOOK_SECRET', ''),
    'delay_ack' => false,
    'crash_after_publish' => false,
    'principals' => [
        'merchant-a' => ['token' => env('DEMO_MERCHANT_A_TOKEN', ''), 'tenant' => 1, 'role' => 'merchant'],
        'merchant-b' => ['token' => env('DEMO_MERCHANT_B_TOKEN', ''), 'tenant' => 2, 'role' => 'merchant'],
        'reviewer-a' => ['token' => env('DEMO_REVIEWER_A_TOKEN', ''), 'tenant' => 1, 'role' => 'reviewer'],
        'reviewer-b' => ['token' => env('DEMO_REVIEWER_B_TOKEN', ''), 'tenant' => 1, 'role' => 'reviewer'],
    ],
];
