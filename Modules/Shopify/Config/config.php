<?php

return [
    'name' => 'Shopify',
    'module_version' => '1.0.0',
    'api_key' => env('SHOPIFY_API_KEY', ''),
    'api_secret' => env('SHOPIFY_API_SECRET', ''),
    'redirect_uri' => env('SHOPIFY_REDIRECT_URI', ''),
    'api_version' => env('SHOPIFY_API_VERSION', '2024-01'),
    
    'sync' => [
        'default_frequency' => env('SHOPIFY_SYNC_FREQUENCY', 'daily'),
        'rate_limit' => 40, // requests per second
        'retry_attempts' => 3,
        'retry_delay' => 1, // seconds
    ],
    
    'webhook' => [
        'timeout' => 30, // seconds
    ],
];
