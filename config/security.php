<?php
declare(strict_types=1);

/**
 * AnaBot Security & Authentication Configuration
 */

return [
    // AES-256-GCM Encryption Key
    'app_key' => env('APP_KEY', ''),
    'key_path' => env('ENCRYPTION_KEY_PATH', ''),

    // Session Configuration
    'session' => [
        'name' => 'ANABOT_SESSION',
        'lifetime' => (int) env('SESSION_LIFETIME', 7200), // 2 hours
        'path' => '/',
        'domain' => null,
        'secure' => (bool) env('SESSION_SECURE_COOKIE', false),
        'httponly' => (bool) env('SESSION_HTTPONLY', true),
        'samesite' => env('SESSION_SAMESITE', 'Lax'),
    ],

    // Brute Force Throttling
    'throttle' => [
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'decay_minutes' => (int) env('LOGIN_DECAY_MINUTES', 15),
    ],

    // CSRF Protection
    'csrf' => [
        'token_length' => 32,
        'cookie_name' => 'ANABOT_CSRF',
    ],

    // Logging Masking Keywords
    'sensitive_keys' => [
        'password',
        'password_hash',
        'api_key',
        'token',
        'access_token',
        'refresh_token',
        'client_secret',
        'app_key',
        'credentials',
        'two_factor_secret',
    ],
];
