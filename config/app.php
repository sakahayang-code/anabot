<?php
declare(strict_types=1);

/**
 * AnaBot Application Configuration
 */

return [
    'name' => env('APP_NAME', 'AnaBot'),
    'env' => env('APP_ENV', 'development'), // development | testing | production
    'debug' => (bool) env('APP_DEBUG', true),
    'url' => env('APP_URL', 'http://localhost:8000'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),
    'locale' => env('APP_LOCALE', 'id'),
    'version' => '1.0.0-dev',

    // Physical Paths
    'paths' => [
        'base' => dirname(__DIR__),
        'storage' => env('STORAGE_PATH', dirname(__DIR__) . '/storage'),
        'logs' => env('LOG_PATH', dirname(__DIR__) . '/storage/logs'),
        'backups' => env('BACKUP_PATH', dirname(__DIR__) . '/storage/backups'),
        'reports' => env('REPORT_PATH', dirname(__DIR__) . '/storage/reports'),
        'cache' => dirname(__DIR__) . '/storage/cache',
        'views' => dirname(__DIR__) . '/resources/views',
    ],
];
