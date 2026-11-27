<?php
declare(strict_types=1);

/**
 * AnaBot CLI Cron: Platform Connectors Synchronization Runner
 * 
 * Usage:
 *   CLI: php bin/cron/sync.php [--force]
 * 
 * Concurrency protected via flock() to prevent overlapping runs on shared hosting.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Direct web access forbidden. Run via CLI or web cron endpoint.\n";
    exit(1);
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    load_env($envFile);
}

use AnaBot\Core\App;
use AnaBot\Core\Database;
use AnaBot\Core\Logger;
use AnaBot\Models\ScheduledTask;
use AnaBot\Services\AlertService;
use AnaBot\Services\ConnectorManager;
use AnaBot\Services\SystemMonitor;

$app = App::getInstance()->boot();
$db = Database::getInstance();
$logger = new Logger();
$monitor = new SystemMonitor($db, $logger);
$alertService = new AlertService($db, null, $logger);
$connectorManager = new ConnectorManager($db, null, null, $logger);

$startTime = microtime(true);
$lockFile = storage_path('cache/cron_sync.lock');
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}

$lockFp = fopen($lockFile, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('c') . "] [SYNC] Proses sinkronisasi lain sedang berjalan (Lock active). Melewati putaran ini.\n";
    exit(0);
}

echo "========================================================\n";
echo "  ANABOT CONNECTOR SYNC RUNNER (" . date('c') . ")\n";
echo "========================================================\n";

try {
    $force = in_array('--force', $argv ?? [], true);
    $now = date('c');

    // Query active connections configured for auto sync
    $connections = $db->queryAll(
        "SELECT c.*, conn.name as connector_name, conn.connector_code
         FROM platform_connections c
         JOIN connectors conn ON c.connector_id = conn.id
         WHERE c.status = 'connected' AND (c.auto_sync_enabled = 1 OR :force = 1)
         ORDER BY c.last_sync_at ASC NULLS FIRST",
        [':force' => $force ? 1 : 0]
    );

    $syncedCount = 0;
    $skippedCount = 0;
    $errorCount = 0;

    foreach ($connections as $conn) {
        $cid = (int) $conn['id'];
        $name = $conn['name'];
        $intervalMinutes = max(5, (int) ($conn['sync_interval_minutes'] ?? 60));
        $lastSync = $conn['last_sync_at'];

        // Check if connection is due for sync
        $isDue = false;
        if ($force || empty($lastSync)) {
            $isDue = true;
        } else {
            $lastSyncTs = strtotime($lastSync);
            if ((time() - $lastSyncTs) >= ($intervalMinutes * 60)) {
                $isDue = true;
            }
        }

        if (!$isDue) {
            $skippedCount++;
            continue;
        }

        echo "-> [RUNNING] Menyinkronkan koneksi #{$cid} '{$name}' ({$conn['connector_name']})...\n";

        try {
            $result = $connectorManager->syncConnection($cid, null, 'scheduled');
            $added = $result['records_added'] ?? 0;
            $updated = $result['records_updated'] ?? 0;
            echo "   [SUCCESS] Selesai: +{$added} baru, ~{$updated} diperbarui (" . round(($result['duration_ms'] ?? 0), 2) . " ms)\n";
            $syncedCount++;

            // Evaluate post-sync anomalies if dataset exists
            if (!empty($result['dataset_id'])) {
                $anomalyEval = $alertService->evaluateDatasetAnomalies((int) $result['dataset_id']);
                if (!empty($anomalyEval['alert_triggered'])) {
                    echo "   [ALERT] Anomali terdeteksi: Tingkat risiko {$anomalyEval['risk_level']} dengan {$anomalyEval['anomaly_count']} anomali!\n";
                }
            }
        } catch (Throwable $e) {
            echo "   [ERROR] Gagal: " . $e->getMessage() . "\n";
            $errorCount++;
            $logger->error("Scheduled sync error on connection #{$cid}: " . $e->getMessage());

            // Check repeated failures
            $alertService->checkRepeatedSyncFailures($cid);
        }
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    echo "\nRingkasan Sinkronisasi: {$syncedCount} sukses, {$errorCount} gagal, {$skippedCount} dilewati ({$elapsed} detik).\n";

    // Update cron heartbeat
    $monitor->recordCronHeartbeat('cron:sync', [
        'synced_count' => $syncedCount,
        'error_count'  => $errorCount,
        'duration_s'   => $elapsed,
    ]);

} catch (Throwable $e) {
    echo "[FATAL ERROR] " . $e->getMessage() . "\n";
    $logger->critical("Fatal sync cron error: " . $e->getMessage());
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

exit(0);
