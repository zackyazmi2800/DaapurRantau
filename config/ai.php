<?php

return [
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 3000),
        'thinking_level' => env('GEMINI_THINKING_LEVEL', 'minimal'),
        'timeout' => 30,
        'connect_timeout' => 5,
    ],
    'cache' => [
        'enabled' => (bool) env('RECIPE_CACHE_ENABLED', true),
        'ttl' => (int) env('RECIPE_CACHE_TTL', 86400),
    ],
    'prompt_version' => '7',
    'schema_version' => '5',
    'normal_recommendation_version' => 'alternative-fallback-4',
];
