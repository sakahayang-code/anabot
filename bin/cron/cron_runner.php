<?php
declare(strict_types=1);

/**
 * AnaBot Master CLI Cron Runner
 * 
 * Central scheduler entrypoint. Add this line to cPanel / Linux Crontab:
 *   * * * * * php /path/to/anabot/bin/cron/cron_runner.php > /dev/null 2>&1
 * 
 * Evaluates all due scheduled tasks and executes them with concurrency locking.
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
use AnaBot\Services\ReportEngine;
use AnaBot\Services\SystemMonitor;

$app = App::getInstance()->boot();
$db = Database::getInstance();
$logger = new Logger();
$monitor = new SystemMonitor($db, $logger);
$alertService = new AlertService($db, null, $logger);
$connectorManager = new ConnectorManager($db, null, null, $logger);
$reportEngine = new ReportEngine(null, $db);

$startTime = microtime(true);
$lockFile = storage_path('cache/cron_master.lock');
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}

$lockFp = fopen($lockFile, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('c') . "] [CRON] Master scheduler lain sedang berjalan (Lock active). Melewati putaran ini.\n";
    exit(0);
}

echo "========================================================\n";
echo "  ANABOT MASTER CRON RUNNER (" . date('c') . ")\n";
echo "========================================================\n";

try {
    // 1. Ensure default scheduled tasks exist
    ScheduledTask::seedDefaultTasksIfEmpty();

    // 2. Fetch tasks due for current minute
    $now = new DateTimeImmutable('now', new DateTimeZone(config('app.timezone', 'Asia/Jakarta')));
    $dueTasks = ScheduledTask::getDueTasks($now);

    echo "Tugas Terjadwal Aktif: " . count(ScheduledTask::getActive()) . " | Tugas Due Menit Ini: " . count($dueTasks) . "\n\n";

    $executedCount = 0;
    $failedCount = 0;

    foreach ($dueTasks as $task) {
        $taskId = (int) $task['id'];
        $type = $task['task_type'];
        $name = $task['task_name'];

        echo "-> [EXEC] Menjalankan: [{$name}] ({$type})...\n";
        $taskStart = microtime(true);

        try {
            switch ($type) {
                case 'sync_connectors':
                    // Run connector auto sync
                    $connList = $db->queryAll(
                        "SELECT id FROM platform_connections WHERE status = 'connected' AND auto_sync_enabled = 1"
                    );
                    $syncOk = 0;
                    foreach ($connList as $c) {
                        try {
                            $connectorManager->syncConnection((int) $c['id'], null, 'scheduled');
                            $syncOk++;
                        } catch (Throwable $e) {
                            $logger->error("Auto-sync error on connection #{$c['id']}: " . $e->getMessage());
                        }
                    }
                    echo "   [DONE] Sinkronisasi {$syncOk} koneksi selesai.\n";
                    break;

                case 'process_queue':
                    // Process up to 5 pending jobs
                    $pending = $db->queryAll("SELECT id, connection_id FROM sync_jobs WHERE status = 'pending' LIMIT 5");
                    $qOk = 0;
                    foreach ($pending as $pj) {
                        try {
                            $connectorManager->syncConnection((int) $pj['connection_id'], null, 'queue');
                            $qOk++;
                        } catch (Throwable $e) {
                            $logger->error("Queue processing error on job #{$pj['id']}: " . $e->getMessage());
                        }
                    }
                    echo "   [DONE] Memproses {$qOk} pekerjaan antrean.\n";
                    break;

                case 'database_backup':
                    $backupDir = config('app.paths.backups', storage_path('backups'));
                    $backupPath = $backupDir . '/anabot_backup_' . date('Ymd_His') . '.sqlite';
                    $db->createBackup($backupPath);
                    $pruned = $monitor->pruneBackups(7);
                    echo "   [DONE] Backup berhasil (" . format_bytes(filesize($backupPath)) . "). {$pruned} backup lama dihapus.\n";
                    break;

                case 'log_rotation':
                    $logRes = $monitor->rotateLogs(14, 10485760);
                    echo "   [DONE] Rotasi log selesai: {$logRes['rotated_count']} dirotasi, {$logRes['purged_count']} dihapus.\n";
                    break;

                case 'report_cleanup':
                    $cleaned = $reportEngine->cleanupOldReports(30);
                    echo "   [DONE] Pembersihan laporan kedaluwarsa: {$cleaned} berkas dibersihkan.\n";
                    break;

                case 'anomaly_monitoring':
                    $anomRes = $alertService->evaluateAllDatasetsAnomalies();
                    echo "   [DONE] Evaluasi anomali: {$anomRes['total_datasets_evaluated']} dataset dicek, {$anomRes['total_alerts_triggered']} peringatan dikirim.\n";
                    break;

                default:
                    echo "   [WARN] Tipe tugas '{$type}' tidak dikenali.\n";
                    break;
            }

            ScheduledTask::updateRunTimes($taskId, $now);
            $taskElapsed = round((microtime(true) - $taskStart) * 1000, 2);
            echo "   [SUCCESS] Selesai dalam {$taskElapsed} ms.\n\n";
            $executedCount++;
        } catch (Throwable $e) {
            $failedCount++;
            echo "   [FAILED] Gagal mengeksekusi tugas: " . $e->getMessage() . "\n\n";
            $logger->error("Scheduled task #{$taskId} ({$name}) failed: " . $e->getMessage());
        }
    }

    $totalElapsed = round(microtime(true) - $startTime, 2);
    echo "Master Cron Selesai: {$executedCount} berhasil, {$failedCount} gagal ({$totalElapsed} detik).\n";

    // Record cron heartbeat
    $monitor->recordCronHeartbeat('cli:cron_runner', [
        'tasks_executed' => $executedCount,
        'tasks_failed'   => $failedCount,
        'duration_s'     => $totalElapsed,
    ]);

} catch (Throwable $e) {
    echo "[CRITICAL ERROR] " . $e->getMessage() . "\n";
    $logger->critical("Master cron runner error: " . $e->getMessage());
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

exit(0);
