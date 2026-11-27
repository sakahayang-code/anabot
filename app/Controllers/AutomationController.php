<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\Core\Auth;
use AnaBot\Core\Database;
use AnaBot\Core\Logger;
use AnaBot\Core\Response;
use AnaBot\Models\Notification;
use AnaBot\Models\ScheduledTask;
use AnaBot\Services\AlertService;
use AnaBot\Services\ConnectorManager;
use AnaBot\Services\ReportEngine;
use AnaBot\Services\SyncQueueManager;
use AnaBot\Services\SystemMonitor;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * AnaBot Automation & Monitoring Controller
 * 
 * Handles schedule configuration, queue monitoring, system health diagnostics,
 * log maintenance, automated backup triggers, and in-app notifications.
 */
class AutomationController
{
    private Database $db;
    private Logger $logger;
    private SystemMonitor $monitor;
    private AlertService $alertService;
    private ConnectorManager $connectorManager;
    private SyncQueueManager $queueManager;

    public function __construct(
        ?Database $db = null,
        ?SystemMonitor $monitor = null,
        ?AlertService $alertService = null,
        ?ConnectorManager $connectorManager = null,
        ?SyncQueueManager $queueManager = null,
        ?Logger $logger = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->logger = $logger ?? new Logger();
        $this->monitor = $monitor ?? new SystemMonitor($this->db, $this->logger);
        $this->alertService = $alertService ?? new AlertService($this->db, null, $this->logger);
        $this->connectorManager = $connectorManager ?? new ConnectorManager($this->db, null, null, $this->logger);
        $this->queueManager = $queueManager ?? new SyncQueueManager($this->db, $this->logger);
    }

    /**
     * Web View: Schedules Management (/automation/schedules)
     */
    public function schedules(): void
    {
        ScheduledTask::seedDefaultTasksIfEmpty();
        $tasks = ScheduledTask::getAll();
        $cronStatus = $this->monitor->getCronStatus();

        $projectPath = dirname(__DIR__, 2);
        $cliScript = $projectPath . '/bin/cron/cron_runner.php';
        $phpBinary = PHP_BINARY;
        $crontabCommand = "* * * * * {$phpBinary} {$cliScript} > /dev/null 2>&1";

        $cronToken = (string) (env('CRON_SECRET_TOKEN') ?: env('APP_KEY', ''));
        $webCronUrl = url('/cron/run?token=' . urlencode($cronToken));

        view('automation/schedules', [
            'pageTitle'      => 'Jadwal Otomasi & Cron - AnaBot',
            'tasks'          => $tasks,
            'cronStatus'     => $cronStatus,
            'crontabCommand' => $crontabCommand,
            'webCronUrl'     => $webCronUrl,
        ]);
    }

    /**
     * Web Action: Create Scheduled Task (POST /automation/schedules/create)
     */
    public function createSchedule(): void
    {
        $type = trim((string) ($_POST['task_type'] ?? ''));
        $name = trim((string) ($_POST['task_name'] ?? ''));
        $cron = trim((string) ($_POST['cron_expression'] ?? ''));

        if (empty($type) || empty($name) || empty($cron)) {
            $_SESSION['flash_error'] = 'Semua kolom tugas terjadwal wajib diisi.';
            Response::redirect('/automation/schedules');
        }

        try {
            ScheduledTask::create([
                'task_type'       => $type,
                'task_name'       => $name,
                'cron_expression' => $cron,
                'is_active'       => 1,
            ]);

            $_SESSION['flash_success'] = "Tugas terjadwal '{$name}' berhasil dibuat.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal membuat tugas: ' . $e->getMessage();
        }

        Response::redirect('/automation/schedules');
    }

    /**
     * Web Action: Toggle Task Active Status (POST /automation/schedules/{id}/toggle)
     */
    public function toggleSchedule(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $success = ScheduledTask::toggleActive($id);

        if ($success) {
            $_SESSION['flash_success'] = "Status tugas #{$id} berhasil diperbarui.";
        } else {
            $_SESSION['flash_error'] = "Tugas #{$id} tidak ditemukan.";
        }

        Response::redirect('/automation/schedules');
    }

    /**
     * Web Action: Run Scheduled Task Immediately (POST /automation/schedules/{id}/run)
     */
    public function runSchedule(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $task = ScheduledTask::find($id);

        if (!$task) {
            $_SESSION['flash_error'] = "Tugas #{$id} tidak ditemukan.";
            Response::redirect('/automation/schedules');
        }

        try {
            $resultMsg = $this->executeTaskByType($task['task_type']);
            ScheduledTask::updateRunTimes($id);
            $_SESSION['flash_success'] = "Tugas '{$task['task_name']}' berhasil dieksekusi: {$resultMsg}";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Gagal mengeksekusi tugas: " . $e->getMessage();
        }

        Response::redirect('/automation/schedules');
    }

    /**
     * Web Action: Delete Custom Task (POST /automation/schedules/{id}/delete)
     */
    public function deleteSchedule(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        ScheduledTask::delete($id);

        $_SESSION['flash_success'] = "Tugas #{$id} berhasil dihapus.";
        Response::redirect('/automation/schedules');
    }

    /**
     * Web View: Queue Tasks Monitor (/automation/tasks)
     */
    public function tasks(): void
    {
        $queueHealth = $this->monitor->checkQueueHealth();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $jobs = $this->db->queryAll(
            "SELECT j.*, c.name as connection_name, conn.name as connector_name, conn.connector_code
             FROM sync_jobs j
             JOIN platform_connections c ON j.connection_id = c.id
             JOIN connectors conn ON c.connector_id = conn.id
             ORDER BY j.id DESC 
             LIMIT :limit OFFSET :offset",
            [':limit' => $limit, ':offset' => $offset]
        );

        $totalJobs = (int) ($this->db->queryOne("SELECT COUNT(*) as cnt FROM sync_jobs")['cnt'] ?? 0);

        view('automation/tasks', [
            'pageTitle'   => 'Monitoring Antrean Pekerjaan - AnaBot',
            'queueHealth' => $queueHealth,
            'jobs'        => $jobs,
            'pagination'  => [
                'current_page' => $page,
                'per_page'     => $limit,
                'total_items'  => $totalJobs,
                'total_pages'  => (int) ceil($totalJobs / $limit),
            ],
        ]);
    }

    /**
     * Web Action: Retry Failed Sync Job (POST /automation/tasks/{id}/retry)
     */
    public function retryJob(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $job = $this->queueManager->getJob($id);

        if (!$job) {
            $_SESSION['flash_error'] = "Pekerjaan #{$id} tidak ditemukan.";
            Response::redirect('/automation/tasks');
        }

        // Re-queue as pending
        $this->db->execute(
            "UPDATE sync_jobs SET status = 'pending', error_message = NULL WHERE id = :id",
            [':id' => $id]
        );

        $_SESSION['flash_success'] = "Pekerjaan #{$id} berhasil dimasukkan kembali ke antrean.";
        Response::redirect('/automation/tasks');
    }

    /**
     * Web View: System Health & Maintenance (/automation/health)
     */
    public function health(): void
    {
        $health = $this->monitor->checkSystemHealth();
        $backups = glob(config('app.paths.backups', storage_path('backups')) . '/anabot_backup_*.sqlite');
        usort($backups, fn($a, $b) => filemtime($b) <=> filemtime($a));

        view('automation/health', [
            'pageTitle' => 'Kesehatan Sistem & Pemeliharaan - AnaBot',
            'health'    => $health,
            'backups'   => array_slice($backups, 0, 10),
        ]);
    }

    /**
     * Web Action: Manual Database Backup (POST /automation/backup/now)
     */
    public function manualBackup(): void
    {
        $backupDir = config('app.paths.backups', storage_path('backups'));
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0750, true);
        }

        $filename = 'anabot_backup_' . date('Ymd_His') . '.sqlite';
        $dest = $backupDir . '/' . $filename;

        try {
            $this->db->createBackup($dest);
            $pruned = $this->monitor->pruneBackups(7);
            $_SESSION['flash_success'] = "Cadangan basis data berhasil dibuat: {$filename} (" . format_bytes(filesize($dest)) . ").";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Gagal membuat cadangan: " . $e->getMessage();
        }

        Response::redirect('/automation/health');
    }

    /**
     * Web Action: Manual Log Rotation (POST /automation/logs/rotate)
     */
    public function manualRotateLogs(): void
    {
        $result = $this->monitor->rotateLogs(14, 10485760);
        $_SESSION['flash_success'] = "Rotasi log selesai: {$result['rotated_count']} berkas dirotasi, {$result['purged_count']} berkas lama dihapus.";
        Response::redirect('/automation/health');
    }

    /**
     * Web View / API: User In-App Notifications (/notifications)
     */
    public function notifications(): void
    {
        $userId = (int) Auth::id();
        $notifications = Notification::getByUser($userId, 50);
        $unreadCount = Notification::countUnread($userId);

        if (is_api_request()) {
            Response::success([
                'unread_count'  => $unreadCount,
                'notifications' => $notifications,
            ]);
        }

        view('automation/notifications', [
            'pageTitle'     => 'Pusat Notifikasi & Peringatan - AnaBot',
            'notifications' => $notifications,
            'unreadCount'   => $unreadCount,
        ]);
    }

    /**
     * Web / API Action: Mark Notification Read (POST /notifications/{id}/read)
     */
    public function readNotification(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $userId = (int) Auth::id();

        Notification::markAsRead($id, $userId);

        if (is_api_request()) {
            Response::success(null, 'Notifikasi ditandai telah dibaca.');
        }

        Response::redirect('/notifications');
    }

    /**
     * Web / API Action: Mark All Notifications Read (POST /notifications/read-all)
     */
    public function readAllNotifications(): void
    {
        $userId = (int) Auth::id();
        $count = Notification::markAllAsRead($userId);

        if (is_api_request()) {
            Response::success(['marked_read_count' => $count], 'Semua notifikasi ditandai telah dibaca.');
        }

        Response::redirect('/notifications');
    }

    /**
     * Public Web Cron Endpoint (GET /cron/run?token=XYZ)
     * 
     * For shared hosting setups without SSH/CLI cron capability.
     */
    public function webCronRun(): void
    {
        $providedToken = $_GET['token'] ?? '';
        $expectedToken = (string) (env('CRON_SECRET_TOKEN') ?: env('APP_KEY', ''));

        if (empty($expectedToken) || !hash_equals((string) $expectedToken, (string) $providedToken)) {
            http_response_code(403);
            Response::error('Akses cron web ditolak: Token tidak valid.', 'UNAUTHORIZED', null, 403);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone(config('app.timezone', 'Asia/Jakarta')));
        ScheduledTask::seedDefaultTasksIfEmpty();
        $dueTasks = ScheduledTask::getDueTasks($now);
        $results = [];

        foreach ($dueTasks as $task) {
            $taskId = (int) $task['id'];
            try {
                $msg = $this->executeTaskByType($task['task_type']);
                ScheduledTask::updateRunTimes($taskId, $now);
                $results[] = [
                    'id'     => $taskId,
                    'name'   => $task['task_name'],
                    'status' => 'success',
                    'detail' => $msg,
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'id'     => $taskId,
                    'name'   => $task['task_name'],
                    'status' => 'failed',
                    'error'  => $e->getMessage(),
                ];
            }
        }

        $this->monitor->recordCronHeartbeat('web_cron', [
            'tasks_executed' => count($results),
            'ip'             => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        Response::success([
            'timestamp'      => $now->format('c'),
            'tasks_executed' => count($results),
            'details'        => $results,
        ], 'Web cron runner berhasil dieksekusi.');
    }

    /**
     * REST API: System Health Check (GET /api/v1/automation/health)
     */
    public function apiHealth(): void
    {
        Response::success($this->monitor->checkSystemHealth());
    }

    /**
     * REST API: List Scheduled Tasks (GET /api/v1/automation/schedules)
     */
    public function apiSchedules(): void
    {
        ScheduledTask::seedDefaultTasksIfEmpty();
        Response::success([
            'cron_status' => $this->monitor->getCronStatus(),
            'tasks'       => ScheduledTask::getAll(),
        ]);
    }

    /**
     * REST API: Create Scheduled Task (POST /api/v1/automation/schedules)
     */
    public function apiCreateSchedule(): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
        $type = trim((string) ($input['task_type'] ?? ''));
        $name = trim((string) ($input['task_name'] ?? ''));
        $cron = trim((string) ($input['cron_expression'] ?? ''));

        if (empty($type) || empty($name) || empty($cron)) {
            Response::error('task_type, task_name, dan cron_expression wajib diisi.', 'VALIDATION_FAILED', null, 422);
        }

        try {
            $id = ScheduledTask::create([
                'task_type'       => $type,
                'task_name'       => $name,
                'cron_expression' => $cron,
                'is_active'       => 1,
            ]);

            Response::success(ScheduledTask::find($id), 'Tugas terjadwal berhasil dibuat.', 201);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 'CRON_INVALID', null, 400);
        }
    }

    /**
     * REST API: Trigger Schedule Execution (POST /api/v1/automation/schedules/{id}/run)
     */
    public function apiRunSchedule(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $task = ScheduledTask::find($id);

        if (!$task) {
            Response::error("Tugas #{$id} tidak ditemukan.", 'NOT_FOUND', null, 404);
        }

        try {
            $msg = $this->executeTaskByType($task['task_type']);
            ScheduledTask::updateRunTimes($id);
            Response::success(['result' => $msg], "Tugas '{$task['task_name']}' berhasil dieksekusi.");
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 'EXECUTION_FAILED', null, 500);
        }
    }

    /**
     * REST API: List Sync Queue Jobs (GET /api/v1/automation/jobs)
     */
    public function apiJobs(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $jobs = $this->db->queryAll(
            "SELECT j.*, c.name as connection_name, conn.name as connector_name
             FROM sync_jobs j
             JOIN platform_connections c ON j.connection_id = c.id
             JOIN connectors conn ON c.connector_id = conn.id
             ORDER BY j.id DESC 
             LIMIT :limit OFFSET :offset",
            [':limit' => $limit, ':offset' => $offset]
        );

        $total = (int) ($this->db->queryOne("SELECT COUNT(*) as cnt FROM sync_jobs")['cnt'] ?? 0);

        Response::success([
            'jobs' => $jobs,
            'pagination' => [
                'current_page' => $page,
                'per_page'     => $limit,
                'total_items'  => $total,
                'total_pages'  => (int) ceil($total / $limit),
            ],
            'queue_health' => $this->monitor->checkQueueHealth(),
        ]);
    }

    /**
     * Execute internal task routine by type.
     */
    private function executeTaskByType(string $type): string
    {
        return match ($type) {
            'sync_connectors' => $this->executeSyncConnectors(),
            'process_queue'   => $this->executeProcessQueue(),
            'database_backup' => $this->executeDatabaseBackup(),
            'log_rotation'    => $this->executeLogRotation(),
            'report_cleanup'  => $this->executeReportCleanup(),
            'anomaly_monitoring' => $this->executeAnomalyMonitoring(),
            default => throw new RuntimeException("Tipe tugas [{$type}] tidak didukung."),
        };
    }

    private function executeSyncConnectors(): string
    {
        $conns = $this->db->queryAll("SELECT id FROM platform_connections WHERE status = 'connected' AND auto_sync_enabled = 1");
        $count = 0;
        foreach ($conns as $c) {
            try {
                $this->connectorManager->syncConnection((int) $c['id'], null, 'scheduled');
                $count++;
            } catch (Throwable $e) {
                $this->logger->error("Auto sync failed for connection #{$c['id']}: " . $e->getMessage());
            }
        }
        return "Sinkronisasi selesai untuk {$count} koneksi aktif.";
    }

    private function executeProcessQueue(): string
    {
        $pending = $this->db->queryAll("SELECT id, connection_id FROM sync_jobs WHERE status = 'pending' LIMIT 5");
        $count = 0;
        foreach ($pending as $job) {
            try {
                $this->connectorManager->syncConnection((int) $job['connection_id'], null, 'queue');
                $count++;
            } catch (Throwable $e) {
                $this->logger->error("Queue processing failed for job #{$job['id']}: " . $e->getMessage());
            }
        }
        return "Pemrosesan selesai untuk {$count} pekerjaan antrean.";
    }

    private function executeDatabaseBackup(): string
    {
        $backupDir = config('app.paths.backups', storage_path('backups'));
        $dest = $backupDir . '/anabot_backup_' . date('Ymd_His') . '.sqlite';
        $this->db->createBackup($dest);
        $pruned = $this->monitor->pruneBackups(7);
        return "Backup berhasil dibuat (" . format_bytes(filesize($dest)) . "). {$pruned} backup lama dihapus.";
    }

    private function executeLogRotation(): string
    {
        $res = $this->monitor->rotateLogs(14, 10485760);
        return "{$res['rotated_count']} berkas dirotasi, {$res['purged_count']} berkas lama dihapus.";
    }

    private function executeReportCleanup(): string
    {
        $engine = new ReportEngine(null, $this->db);
        $cleaned = $engine->cleanupOldReports(30);
        return "{$cleaned} berkas laporan kedaluwarsa dibersihkan.";
    }

    private function executeAnomalyMonitoring(): string
    {
        $eval = $this->alertService->evaluateAllDatasetsAnomalies();
        return "{$eval['total_datasets_evaluated']} dataset dicek, {$eval['total_alerts_triggered']} peringatan anomali dikirim.";
    }
}
