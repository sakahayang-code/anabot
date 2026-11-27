<?php
declare(strict_types=1);

/**
 * AnaBot CLI Migration Runner
 * 
 * Usage: php database/migrate.php [--status]
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env
if (file_exists(dirname(__DIR__) . '/.env')) {
    load_env(dirname(__DIR__) . '/.env');
}

use AnaBot\Core\App;
use AnaBot\Core\Database;
use AnaBot\Core\MigrationRunner;

$app = App::getInstance()->boot();
$db = Database::getInstance();
$runner = new MigrationRunner($db);

$isStatus = in_array('--status', $argv ?? [], true);

echo "==================================================" . PHP_EOL;
echo "  AnaBot Database Migration Runner" . PHP_EOL;
echo "  Database: " . $db->getDatabasePath() . PHP_EOL;
echo "==================================================" . PHP_EOL;

if ($isStatus) {
    $executed = $runner->getExecutedMigrations();
    $pending = $runner->getPendingMigrations();

    echo "Executed Migrations (" . count($executed) . "):" . PHP_EOL;
    foreach ($executed as $m) {
        echo "  [BATCH {$m['batch']}] {$m['migration_name']} (Executed at {$m['executed_at']} in {$m['execution_time_ms']}ms)" . PHP_EOL;
    }

    echo PHP_EOL . "Pending Migrations (" . count($pending) . "):" . PHP_EOL;
    foreach ($pending as $p) {
        echo "  [PENDING] {$p['name']}" . PHP_EOL;
    }
    exit(0);
}

try {
    $result = $runner->run();
    echo $result['message'] . PHP_EOL;
    if ($result['executed_count'] > 0) {
        echo "Applied " . $result['executed_count'] . " migration(s):" . PHP_EOL;
        foreach ($result['migrations'] as $m) {
            echo "  + [BATCH {$m['batch']}] {$m['name']} ({$m['time_ms']}ms)" . PHP_EOL;
        }
    }
    echo "Database integrity check: " . ($db->integrityCheck() ? 'OK' : 'FAILED') . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: Migration failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
