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
        'storage' => str_starts_with((string) env('STORAGE_PATH', ''), '/') ? env('STORAGE_PATH') : dirname(__DIR__) . '/' . ltrim((string) env('STORAGE_PATH', 'storage'), '/'),
        'logs' => str_starts_with((string) env('LOG_PATH', ''), '/') ? env('LOG_PATH') : dirname(__DIR__) . '/' . ltrim((string) env('LOG_PATH', 'storage/logs'), '/'),
        'backups' => str_starts_with((string) env('BACKUP_PATH', ''), '/') ? env('BACKUP_PATH') : dirname(__DIR__) . '/' . ltrim((string) env('BACKUP_PATH', 'storage/backups'), '/'),
        'reports' => str_starts_with((string) env('REPORT_PATH', ''), '/') ? env('REPORT_PATH') : dirname(__DIR__) . '/' . ltrim((string) env('REPORT_PATH', 'storage/reports'), '/'),
        'cache' => dirname(__DIR__) . '/storage/cache',
        'views' => dirname(__DIR__) . '/resources/views',
    ],
];
