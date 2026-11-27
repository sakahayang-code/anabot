<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Database;
use AnaBot\Core\Logger;
use AnaBot\Models\ScheduledTask;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * AnaBot System Health Monitor & Maintenance Service
 * 
 * Provides automated health checks, storage monitoring, log rotation,
 * and rolling backup retention for shared hosting environments.
 */
class SystemMonitor
{
    private Database $db;
    private Logger $logger;
    private string $storagePath;
    private string $logPath;
    private string $backupPath;

    public function __construct(
        ?Database $db = null,
        ?Logger $logger = null,
        ?string $storagePath = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->logger = $logger ?? new Logger();
        $this->storagePath = $storagePath ?? config('app.paths.storage', storage_path());
        $this->logPath = config('app.paths.logs', $this->storagePath . '/logs');
        $this->backupPath = config('app.paths.backups', $this->storagePath . '/backups');
    }

    /**
     * Comprehensive system health check.
     */
    public function checkSystemHealth(): array
    {
        $status = 'HEALTHY';
        $issues = [];

        // 1. Database Health Check
        $dbHealth = $this->checkDatabaseHealth();
        if (!$dbHealth['integrity_ok']) {
            $status = 'CRITICAL';
            $issues[] = 'Integritas basis data SQLite gagal atau korup: ' . ($dbHealth['integrity_result'] ?? 'Gagal');
        }

        // 2. Storage & Disk Space Check
        $storageMetrics = $this->getStorageMetrics();
        if ($storageMetrics['disk_free_percent'] !== null && $storageMetrics['disk_free_percent'] < 5.0) {
            $status = 'CRITICAL';
            $issues[] = 'Sisa ruang penyimpanan kritis: Kurang dari 5% ruang disk tersedia.';
        } elseif ($storageMetrics['disk_free_percent'] !== null && $storageMetrics['disk_free_percent'] < 15.0) {
            if ($status !== 'CRITICAL') {
                $status = 'WARNING';
            }
            $issues[] = 'Peringatan ruang penyimpanan: Tersisa kurang dari 15% ruang disk.';
        }

        // 3. Queue & Worker Health Check
        $queueHealth = $this->checkQueueHealth();
        if ($queueHealth['stalled_jobs_count'] > 0) {
            if ($status !== 'CRITICAL') {
                $status = 'WARNING';
            }
            $issues[] = "Terdapat {$queueHealth['stalled_jobs_count']} pekerjaan sinkronisasi yang terhenti (stalled).";
        }
        if ($queueHealth['recent_failures_count'] > 5) {
            if ($status !== 'CRITICAL') {
                $status = 'WARNING';
            }
            $issues[] = "Terdeteksi {$queueHealth['recent_failures_count']} kegagalan sinkronisasi dalam 24 jam terakhir.";
        }

        // 4. Cron Heartbeat Status
        $cronHealth = $this->getCronStatus();
        if ($cronHealth['status'] === 'INACTIVE') {
            if ($status !== 'CRITICAL') {
                $status = 'WARNING';
            }
            $issues[] = 'CLI Cron runner belum pernah dieksekusi atau tidak terdeteksi aktif.';
        } elseif ($cronHealth['status'] === 'DELAYED') {
            if ($status !== 'CRITICAL') {
                $status = 'WARNING';
            }
            $issues[] = 'Eksekusi Cron terlambat: Terakhir aktif ' . ($cronHealth['last_heartbeat_ago'] ?? 'beberapa waktu lalu');
        }

        return [
            'overall_status' => $status,
            'timestamp'      => date('c'),
            'issues'         => $issues,
            'database'       => $dbHealth,
            'storage'        => $storageMetrics,
            'queue'          => $queueHealth,
            'cron'           => $cronHealth,
            'php_version'    => PHP_VERSION,
            'server_os'      => PHP_OS_FAMILY,
        ];
    }

    /**
     * Check SQLite integrity and PRAGMA settings.
     */
    public function checkDatabaseHealth(): array
    {
        try {
            $pdo = $this->db->getPdo();
            $integrity = $pdo->query('PRAGMA integrity_check;')->fetchColumn();
            $integrityOk = strtolower((string) $integrity) === 'ok';

            $journalMode = (string) $pdo->query('PRAGMA journal_mode;')->fetchColumn();
            $busyTimeout = (int) $pdo->query('PRAGMA busy_timeout;')->fetchColumn();
            $foreignKeys = (int) $pdo->query('PRAGMA foreign_keys;')->fetchColumn();

            $dbPath = $this->db->getDatabasePath();
            $dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;
            $walPath = $dbPath . '-wal';
            $walSize = file_exists($walPath) ? filesize($walPath) : 0;

            return [
                'status'           => $integrityOk ? 'HEALTHY' : 'CORRUPT',
                'integrity_ok'     => $integrityOk,
                'integrity_result' => (string) $integrity,
                'journal_mode'     => strtoupper($journalMode),
                'busy_timeout_ms'  => $busyTimeout,
                'foreign_keys'     => $foreignKeys === 1,
                'database_file'    => basename($dbPath),
                'database_bytes'   => $dbSize,
                'database_formatted' => format_bytes($dbSize),
                'wal_bytes'        => $walSize,
                'wal_formatted'    => format_bytes($walSize),
            ];
        } catch (Throwable $e) {
            return [
                'status'           => 'CRITICAL',
                'integrity_ok'     => false,
                'integrity_result' => $e->getMessage(),
                'journal_mode'     => 'UNKNOWN',
                'busy_timeout_ms'  => 0,
                'foreign_keys'     => false,
                'database_file'    => 'unknown',
                'database_bytes'   => 0,
                'database_formatted' => '0 B',
                'wal_bytes'        => 0,
                'wal_formatted'    => '0 B',
            ];
        }
    }

    /**
     * Calculate disk storage utilization and folder sizes.
     */
    public function getStorageMetrics(): array
    {
        $dbDir = $this->storagePath . '/database';
        $reportsDir = config('app.paths.reports', $this->storagePath . '/reports');
        $backupsDir = $this->backupPath;
        $logsDir = $this->logPath;
        $cacheDir = $this->storagePath . '/cache';

        $dbBytes = $this->getDirectorySize($dbDir);
        $reportsBytes = $this->getDirectorySize($reportsDir);
        $backupsBytes = $this->getDirectorySize($backupsDir);
        $logsBytes = $this->getDirectorySize($logsDir);
        $cacheBytes = $this->getDirectorySize($cacheDir);
        $totalAppStorage = $dbBytes + $reportsBytes + $backupsBytes + $logsBytes + $cacheBytes;

        $freeBytes = @disk_free_space($this->storagePath);
        $totalBytes = @disk_total_space($this->storagePath);
        $freePercent = null;

        if ($freeBytes !== false && $totalBytes !== false && $totalBytes > 0) {
            $freePercent = round(($freeBytes / $totalBytes) * 100, 1);
        }

        return [
            'app_storage_bytes'      => $totalAppStorage,
            'app_storage_formatted'  => format_bytes($totalAppStorage),
            'database_storage_bytes' => $dbBytes,
            'database_formatted'     => format_bytes($dbBytes),
            'reports_storage_bytes'  => $reportsBytes,
            'reports_formatted'      => format_bytes($reportsBytes),
            'backups_storage_bytes'  => $backupsBytes,
            'backups_formatted'      => format_bytes($backupsBytes),
            'logs_storage_bytes'     => $logsBytes,
            'logs_formatted'         => format_bytes($logsBytes),
            'cache_storage_bytes'    => $cacheBytes,
            'cache_formatted'        => format_bytes($cacheBytes),
            'disk_free_bytes'        => $freeBytes !== false ? $freeBytes : 0,
            'disk_free_formatted'    => $freeBytes !== false ? format_bytes((int) $freeBytes) : 'N/A',
            'disk_total_bytes'       => $totalBytes !== false ? $totalBytes : 0,
            'disk_total_formatted'   => $totalBytes !== false ? format_bytes((int) $totalBytes) : 'N/A',
            'disk_free_percent'      => $freePercent,
        ];
    }

    /**
     * Check queue execution health.
     */
    public function checkQueueHealth(): array
    {
        $now = date('c');
        $yesterday = date('c', strtotime('-24 hours'));
        $stalledThreshold = date('c', strtotime('-30 minutes'));

        $pending = (int) ($this->db->queryOne("SELECT COUNT(*) as cnt FROM sync_jobs WHERE status = 'pending'")['cnt'] ?? 0);
        $running = (int) ($this->db->queryOne("SELECT COUNT(*) as cnt FROM sync_jobs WHERE status = 'running'")['cnt'] ?? 0);
        $completed24h = (int) ($this->db->queryOne(
            "SELECT COUNT(*) as cnt FROM sync_jobs WHERE status = 'completed' AND created_at >= :since",
            [':since' => $yesterday]
        )['cnt'] ?? 0);
        $failed24h = (int) ($this->db->queryOne(
            "SELECT COUNT(*) as cnt FROM sync_jobs WHERE status = 'failed' AND created_at >= :since",
            [':since' => $yesterday]
        )['cnt'] ?? 0);

        // Check for jobs running longer than 30 minutes
        $stalled = (int) ($this->db->queryOne(
            "SELECT COUNT(*) as cnt FROM sync_jobs WHERE status = 'running' AND started_at <= :thresh",
            [':thresh' => $stalledThreshold]
        )['cnt'] ?? 0);

        return [
            'pending_jobs_count'    => $pending,
            'running_jobs_count'    => $running,
            'completed_24h_count'   => $completed24h,
            'recent_failures_count' => $failed24h,
            'stalled_jobs_count'    => $stalled,
        ];
    }

    /**
     * Record cron heartbeat timestamp into cache file and system settings.
     */
    public function recordCronHeartbeat(string $source = 'cli', array $meta = []): void
    {
        $cacheDir = $this->storagePath . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $payload = array_merge([
            'last_run_at' => date('c'),
            'timestamp'   => time(),
            'source'      => $source,
        ], $meta);

        @file_put_contents(
            $cacheDir . '/cron_heartbeat.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Get cron heartbeat status.
     */
    public function getCronStatus(): array
    {
        $heartbeatFile = $this->storagePath . '/cache/cron_heartbeat.json';
        if (!file_exists($heartbeatFile)) {
            return [
                'status'             => 'INACTIVE',
                'last_heartbeat'     => null,
                'last_heartbeat_ago' => 'Belum pernah',
                'source'             => null,
            ];
        }

        $data = json_decode((string) file_get_contents($heartbeatFile), true);
        if (!$data || empty($data['timestamp'])) {
            return [
                'status'             => 'INACTIVE',
                'last_heartbeat'     => null,
                'last_heartbeat_ago' => 'Tidak valid',
                'source'             => null,
            ];
        }

        $diffSeconds = time() - (int) $data['timestamp'];
        $ago = $this->formatDiffHuman($diffSeconds);

        // Consider ACTIVE if run within last 30 minutes, DELAYED if within 3 hours, otherwise INACTIVE
        $status = 'ACTIVE';
        if ($diffSeconds > 10800) {
            $status = 'INACTIVE';
        } elseif ($diffSeconds > 1800) {
            $status = 'DELAYED';
        }

        return [
            'status'             => $status,
            'last_heartbeat'     => $data['last_run_at'] ?? date('c', (int) $data['timestamp']),
            'last_heartbeat_ago' => $ago,
            'source'             => $data['source'] ?? 'cli',
        ];
    }

    /**
     * Rotate large or old log files and purge logs older than retention days.
     */
    public function rotateLogs(int $keepDays = 14, int $maxSizeBytes = 10485760): array
    {
        if (!is_dir($this->logPath)) {
            return ['rotated_count' => 0, 'purged_count' => 0];
        }

        $today = date('Y-m-d');
        $rotatedCount = 0;
        $purgedCount = 0;
        $cutoffTime = strtotime("-{$keepDays} days");

        $files = glob($this->logPath . '/*.log*');
        if (!$files) {
            return ['rotated_count' => 0, 'purged_count' => 0];
        }

        foreach ($files as $file) {
            $basename = basename($file);
            $fileTime = filemtime($file);

            // 1. Purge files older than retention days
            if ($fileTime < $cutoffTime) {
                @unlink($file);
                $purgedCount++;
                continue;
            }

            // 2. Rotate oversized active log files
            if (str_ends_with($file, '.log') && filesize($file) >= $maxSizeBytes) {
                $counter = 1;
                do {
                    $rotatedName = preg_replace('/\.log$/', ".{$counter}.log", $file);
                    $counter++;
                } while (file_exists($rotatedName));

                @rename($file, $rotatedName);
                $rotatedCount++;
            }
        }

        return [
            'rotated_count' => $rotatedCount,
            'purged_count'  => $purgedCount,
        ];
    }

    /**
     * Prune older SQLite database backups maintaining rolling retention.
     */
    public function pruneBackups(int $keepCount = 7, string $pattern = '*.sqlite'): int
    {
        if (!is_dir($this->backupPath)) {
            return 0;
        }

        $backups = glob($this->backupPath . '/' . $pattern);
        if (!$backups || count($backups) <= $keepCount) {
            return 0;
        }

        // Sort files by modified time descending (newest first)
        usort($backups, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $prunedCount = 0;
        $excessBackups = array_slice($backups, $keepCount);

        foreach ($excessBackups as $backupFile) {
            if (@unlink($backupFile)) {
                $prunedCount++;
            }
        }

        return $prunedCount;
    }

    /**
     * Calculate total directory size recursively.
     */
    private function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return file_exists($path) ? (int) filesize($path) : 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Human-readable relative time formatter in Indonesian.
     */
    private function formatDiffHuman(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} detik yang lalu";
        }
        $minutes = (int) floor($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes} menit yang lalu";
        }
        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return "{$hours} jam yang lalu";
        }
        $days = (int) floor($hours / 24);
        return "{$days} hari yang lalu";
    }
}
