<?php
declare(strict_types=1);

/**
 * AnaBot AI Provider Defaults Configuration
 */

return [
    'default_provider' => env('DEFAULT_AI_PROVIDER', 'gemini'),
    'timeout' => (int) env('AI_REQUEST_TIMEOUT', 60),

    'providers' => [
        'gemini' => [
            'name' => 'Google Gemini',
            'api_endpoint' => 'https://generativelanguage.googleapis.com/v1beta',
            'default_model' => 'gemini-2.5-flash',
        ],
        'openai' => [
            'name' => 'OpenAI',
            'api_endpoint' => 'https://api.openai.com/v1',
            'default_model' => 'gpt-4o',
        ],
        'claude' => [
            'name' => 'Anthropic Claude',
            'api_endpoint' => 'https://api.anthropic.com/v1',
            'default_model' => 'claude-3-7-sonnet-20250219',
        ],
        'compatible' => [
            'name' => 'OpenAI Compatible',
            'api_endpoint' => env('AI_COMPATIBLE_ENDPOINT', ''),
            'default_model' => env('AI_COMPATIBLE_MODEL', 'custom-model'),
        ],
    ],
];
