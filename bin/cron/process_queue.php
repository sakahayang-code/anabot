<?php
declare(strict_types=1);

/**
 * AnaBot CLI Cron: Background Queue Worker Runner
 * 
 * Usage:
 *   CLI: php bin/cron/process_queue.php [--limit=10] [--timeout=50]
 * 
 * Concurrency protected via flock() to prevent duplicate queue workers.
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
use AnaBot\Services\ConnectorManager;
use AnaBot\Services\SyncQueueManager;
use AnaBot\Services\SystemMonitor;

$app = App::getInstance()->boot();
$db = Database::getInstance();
$logger = new Logger();
$queueManager = new SyncQueueManager($db, $logger);
$connectorManager = new ConnectorManager($db, null, $queueManager, $logger);
$monitor = new SystemMonitor($db, $logger);

$startTime = microtime(true);
$lockFile = storage_path('cache/queue_worker.lock');
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}

$lockFp = fopen($lockFile, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('c') . "] [QUEUE] Worker antrean lain sedang aktif (Lock active). Melewati putaran ini.\n";
    exit(0);
}

echo "========================================================\n";
echo "  ANABOT BACKGROUND QUEUE WORKER (" . date('c') . ")\n";
echo "========================================================\n";

try {
    // Parse arguments
    $maxJobs = 10;
    $maxTimeoutSeconds = 50;

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            $maxJobs = max(1, (int) substr($arg, 8));
        } elseif (str_starts_with($arg, '--timeout=')) {
            $maxTimeoutSeconds = max(5, (int) substr($arg, 10));
        }
    }

    $processedCount = 0;
    $successCount = 0;
    $failCount = 0;

    // Fetch pending jobs sequentially
    $pendingJobs = $db->queryAll(
        "SELECT j.*, c.status as connection_status 
         FROM sync_jobs j
         JOIN platform_connections c ON j.connection_id = c.id
         WHERE j.status = 'pending'
         ORDER BY j.id ASC 
         LIMIT :limit",
        [':limit' => $maxJobs]
    );

    if (empty($pendingJobs)) {
        echo "Antrean kosong. Tidak ada pekerjaan sinkronisasi pending.\n";
    }

    foreach ($pendingJobs as $job) {
        // Check timeout
        if ((microtime(true) - $startTime) >= $maxTimeoutSeconds) {
            echo "[TIMEOUT] Batas waktu eksekusi ({$maxTimeoutSeconds}s) tercapai. Menghentikan worker.\n";
            break;
        }

        $jobId = (int) $job['id'];
        $connectionId = (int) $job['connection_id'];
        $datasetId = !empty($job['dataset_id']) ? (int) $job['dataset_id'] : null;

        echo "-> [PROCESSING] Menjalankan Job #{$jobId} (Connection #{$connectionId})...\n";
        $processedCount++;

        try {
            $queueManager->startJob($jobId);

            $driverCode = $db->queryOne(
                "SELECT conn.connector_code 
                 FROM platform_connections c
                 JOIN connectors conn ON c.connector_id = conn.id
                 WHERE c.id = :id LIMIT 1",
                [':id' => $connectionId]
            )['connector_code'] ?? null;

            if (!$driverCode) {
                throw new RuntimeException("Konektor tidak ditemukan untuk koneksi #{$connectionId}.");
            }

            $driver = $connectorManager->getDriver($driverCode, $connectionId);
            $batchResult = $driver->fetchIncremental(null, $batchLimit ?? 100);

            $added = count($batchResult['records'] ?? []);
            $queueManager->completeJob($jobId, $added, 0);

            echo "   [SUCCESS] Job #{$jobId} selesai. +{$added} data diproses.\n";
            $successCount++;
        } catch (Throwable $e) {
            echo "   [FAILED] Job #{$jobId} gagal: " . $e->getMessage() . "\n";
            $queueManager->failJob($jobId, $e->getMessage());
            $failCount++;
        }
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    echo "\nRingkasan Antrean: {$processedCount} diproses ({$successCount} sukses, {$failCount} gagal) dalam {$elapsed} detik.\n";

    // Update cron heartbeat
    $monitor->recordCronHeartbeat('cron:queue', [
        'jobs_processed' => $processedCount,
        'jobs_success'   => $successCount,
        'jobs_failed'    => $failCount,
        'duration_s'     => $elapsed,
    ]);

} catch (Throwable $e) {
    echo "[FATAL ERROR] " . $e->getMessage() . "\n";
    $logger->critical("Fatal queue worker error: " . $e->getMessage());
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

exit(0);
