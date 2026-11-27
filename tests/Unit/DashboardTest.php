<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Controllers\DashboardController;
use AnaBot\Core\Database;
use AnaBot\Core\MigrationRunner;

class DashboardTest
{
    public function run(): void
    {
        echo "Testing Dashboard Engine & UI Framework (Isolated Test DB)..." . PHP_EOL;

        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $db = new Database($testDbPath);
        Database::setInstance($db);

        // Run migrations
        $runner = new MigrationRunner($db);
        $runner->run();

        // Test 1: Verify Assets exist locally
        $basePublic = dirname(__DIR__, 2) . '/public/assets';
        assert(file_exists($basePublic . '/css/anabot.css'), 'anabot.css must exist');
        assert(file_exists($basePublic . '/js/anabot.js'), 'anabot.js must exist');
        assert(file_exists($basePublic . '/js/chart.min.js'), 'chart.min.js must exist');
        assert(filesize($basePublic . '/js/chart.min.js') > 100000, 'chart.min.js must be a valid bundle');
        echo "  [PASS] Standalone CSS and JavaScript assets verified" . PHP_EOL;

        // Test 2: Initial Metrics with Fresh Database (0 records -> labeled DEMO preview)
        $controller = new DashboardController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('getDashboardMetrics');
        $method->setAccessible(true);

        $metrics = $method->invoke($controller);
        assert($metrics['connectedPlatformsCount'] === 0, 'Initial platforms count must be 0');
        assert($metrics['datasetsCount'] === 0, 'Initial datasets count must be 0');
        assert($metrics['recordsCount'] === 0, 'Initial records count must be 0');
        assert($metrics['isGrowthDataDemo'] === true, 'Must flag demo preview when 0 records');
        assert($metrics['isAiDataDemo'] === true, 'Must flag demo preview when 0 tokens');
        echo "  [PASS] Empty database metrics & transparent DEMO preview flags verified" . PHP_EOL;

        // Test 3: Insert Actual Data and Verify Metrics Update
        $now = date('c');
        $db->execute("INSERT INTO users (username, email, password_hash, status, created_at, updated_at) VALUES ('usr1', 'u@a.com', 'h', 'active', :n, :n)", [':n' => $now]);
        $uid = (int) $db->lastInsertId();

        $db->execute("INSERT INTO connectors (connector_code, name, category, auth_type, created_at) VALUES ('sheets', 'Google Sheets', 'spreadsheet', 'oauth2', :n)", [':n' => $now]);
        $cid = (int) $db->lastInsertId();

        $db->execute("INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at) VALUES (:cid, :uid, 'My Sheet', 'connected', :n, :n)", [':cid' => $cid, ':uid' => $uid, ':n' => $now]);
        $connId = (int) $db->lastInsertId();

        $db->execute("INSERT INTO data_sources (connection_id, external_source_id, source_name, created_at, updated_at) VALUES (:cid, 'ext1', 'Sales Sheet', :n, :n)", [':cid' => $connId, ':n' => $now]);
        $sourceId = (int) $db->lastInsertId();

        $db->execute("INSERT INTO datasets (data_source_id, name, record_count, created_at, updated_at) VALUES (:sid, 'Daily Sales', 250, :n, :n)", [':sid' => $sourceId, ':n' => $now]);

        $db->execute("INSERT INTO ai_providers (provider_code, name, created_at, updated_at) VALUES ('gemini', 'Google Gemini', :n, :n)", [':n' => $now]);
        $providerId = (int) $db->lastInsertId();

        $db->execute("INSERT INTO ai_usage_logs (provider_id, prompt_tokens, completion_tokens, total_tokens, estimated_cost_cents, created_at) VALUES (:pid, 100, 50, 150, 25, :n)", [':pid' => $providerId, ':n' => $now]);

        $updatedMetrics = $method->invoke($controller);
        assert($updatedMetrics['connectedPlatformsCount'] === 1, 'Platforms count must update to 1');
        assert($updatedMetrics['datasetsCount'] === 1, 'Datasets count must update to 1');
        assert($updatedMetrics['recordsCount'] === 250, 'Records count must update to 250');
        assert($updatedMetrics['aiTokens'] === 150, 'AI tokens must update to 150');
        assert($updatedMetrics['isGrowthDataDemo'] === false, 'Growth demo flag must be false when records exist');
        assert($updatedMetrics['isAiDataDemo'] === false, 'AI demo flag must be false when usage exists');
        echo "  [PASS] Live database metric aggregation & ACTUAL data flags verified" . PHP_EOL;

        // Cleanup
        unset($db);
        Database::setInstance(null);
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }
    }
}
