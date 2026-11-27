<?php
declare(strict_types=1);

/**
 * AnaBot Database Configuration (SQLite 3 via PDO)
 */

$appEnv = env('APP_ENV', 'development');

// Determine isolated database path based on environment
if ($appEnv === 'testing') {
    $defaultDb = dirname(__DIR__) . '/storage/database/anabot_testing.sqlite';
} elseif ($appEnv === 'development') {
    $defaultDb = dirname(__DIR__) . '/storage/database/anabot_development.sqlite';
} else {
    // In production, prioritize external path if configured
    $externalDataPath = env('ANABOT_DATA_PATH', '');
    if (!empty($externalDataPath)) {
        $defaultDb = rtrim($externalDataPath, '/') . '/anabot.sqlite';
    } else {
        $defaultDb = dirname(__DIR__) . '/storage/database/anabot.sqlite';
    }
}

return [
    'default' => 'sqlite',

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', $defaultDb),
            'foreign_keys' => (bool) env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => (int) env('DB_BUSY_TIMEOUT', 5000),
            'journal_mode' => env('DB_JOURNAL_MODE', 'WAL'), // WAL with fallback to TRUNCATE/DELETE
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ],
    ],

    // Safety checks
    'allow_create_if_missing' => $appEnv !== 'production', // Never silently create blank DB in production!
];
