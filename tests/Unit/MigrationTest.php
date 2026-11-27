<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Core\Database;
use AnaBot\Core\MigrationRunner;

class MigrationTest
{
    public function run(): void
    {
        echo "Testing Migration Engine & Schema Integrity (Isolated Test DB)..." . PHP_EOL;

        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $db = new Database($testDbPath);
        $runner = new MigrationRunner($db);

        // Test 1: Run migrations
        $result = $runner->run();
        assert($result['executed_count'] === 3, 'Must execute 3 baseline migrations');
        echo "  [PASS] Successfully executed {$result['executed_count']} migrations" . PHP_EOL;

        // Test 2: Check schema_migrations records
        $executed = $runner->getExecutedMigrations();
        assert(count($executed) === 3, 'schema_migrations must have 3 records');
        foreach ($executed as $m) {
            assert($m['batch'] === 1, 'First run batch must be 1');
            assert(!empty($m['checksum']), 'Checksum must not be empty');
            assert(!empty($m['executed_at']), 'executed_at must not be empty');
        }
        echo "  [PASS] schema_migrations table tracking and checksums verified" . PHP_EOL;

        // Test 3: Idempotency (second run must execute 0)
        $secondResult = $runner->run();
        assert($secondResult['executed_count'] === 0, 'Second migration run must execute 0 migrations');
        echo "  [PASS] Migration idempotency verified" . PHP_EOL;

        // Test 4: Verify core tables exist
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $tableNames = array_column($tables, 'name');

        $expectedTables = [
            'schema_migrations', 'users', 'user_settings', 'system_settings', 'audit_logs',
            'ai_providers', 'ai_models', 'connectors', 'platform_connections', 'connection_credentials',
            'connection_scopes', 'oauth_states', 'sync_jobs', 'sync_logs', 'sync_cursors',
            'data_sources', 'datasets', 'dataset_columns', 'data_records', 'analysis_sessions',
            'analysis_messages', 'analysis_results', 'analysis_sources', 'reports', 'scheduled_tasks',
            'notifications', 'ai_usage_logs', 'app_cache'
        ];

        foreach ($expectedTables as $table) {
            assert(in_array($table, $tableNames, true), "Expected table [{$table}] must exist in database");
        }
        echo "  [PASS] All " . count($expectedTables) . " core tables verified in SQLite schema" . PHP_EOL;

        // Test 5: Integrity check
        assert($db->integrityCheck() === true, 'Database integrity must be OK');
        echo "  [PASS] PRAGMA integrity_check passed" . PHP_EOL;

        unset($db);
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }
    }
}
