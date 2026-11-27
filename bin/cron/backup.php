<?php
declare(strict_types=1);

/**
 * AnaBot CLI Cron: Automated SQLite Database Backup Runner
 * 
 * Usage:
 *   CLI: php bin/cron/backup.php [--keep=7]
 * 
 * Concurrency protected via flock() to prevent simultaneous backup writes.
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
use AnaBot\Services\SystemMonitor;

$app = App::getInstance()->boot();
$db = Database::getInstance();
$logger = new Logger();
$monitor = new SystemMonitor($db, $logger);

$startTime = microtime(true);
$lockFile = storage_path('cache/cron_backup.lock');
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}

$lockFp = fopen($lockFile, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('c') . "] [BACKUP] Proses pencadangan lain sedang berjalan (Lock active). Melewati putaran ini.\n";
    exit(0);
}

echo "========================================================\n";
echo "  ANABOT AUTOMATED DATABASE BACKUP (" . date('c') . ")\n";
echo "========================================================\n";

try {
    $keepCount = 7;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--keep=')) {
            $keepCount = max(1, (int) substr($arg, 7));
        }
    }

    $backupDir = config('app.paths.backups', storage_path('backups'));
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0750, true);
    }

    $filename = 'anabot_backup_' . date('Ymd_His') . '.sqlite';
    $destinationPath = $backupDir . '/' . $filename;

    echo "-> Memulai pembuatan cadangan atomik SQLite ke:\n   {$destinationPath}\n";

    // 1. Create atomic verified backup via VACUUM INTO
    $db->createBackup($destinationPath);
    $fileSize = filesize($destinationPath);
    $formattedSize = format_bytes($fileSize);

    echo "   [SUCCESS] Cadangan berhasil dibuat ({$formattedSize}). Integritas data terverifikasi OK.\n";

    // 2. Rolling Backup Retention (Prune older than keepCount)
    $pruned = $monitor->pruneBackups($keepCount);
    if ($pruned > 0) {
        echo "   [PRUNED] {$pruned} berkas cadangan lama dihapus (retensi: {$keepCount} cadangan terbaru).\n";
    }

    // 3. Record Audit Log
    $now = date('c');
    $db->execute(
        "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, created_at)
         VALUES (NULL, 'DATABASE_BACKUP_CREATED', 'database', 'anabot', :vals, :now)",
        [
            ':vals' => json_encode([
                'filename'       => $filename,
                'file_size'      => $fileSize,
                'file_formatted' => $formattedSize,
                'pruned_count'   => $pruned,
                'retention_keep' => $keepCount,
            ], JSON_UNESCAPED_SLASHES),
            ':now'  => $now,
        ]
    );

    $elapsed = round(microtime(true) - $startTime, 2);
    echo "\nProses pencadangan selesai dalam {$elapsed} detik.\n";

    // 4. Update cron heartbeat
    $monitor->recordCronHeartbeat('cron:backup', [
        'backup_file' => $filename,
        'backup_size' => $formattedSize,
        'duration_s'  => $elapsed,
    ]);

} catch (Throwable $e) {
    echo "[FATAL ERROR] Gagal membuat cadangan database: " . $e->getMessage() . "\n";
    $logger->critical("Automated database backup failed: " . $e->getMessage());
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

exit(0);
