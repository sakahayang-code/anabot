<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Database;
use AnaBot\Core\Logger;

/**
 * Manages data synchronization jobs, cursor-based pagination state,
 * granular audit logs, and partial recovery.
 */
class SyncQueueManager
{
    private Database $db;
    private Logger $logger;

    public function __construct(?Database $db = null, ?Logger $logger = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Create a new sync job record in pending status.
     */
    public function createJob(int $connectionId, ?int $datasetId = null, string $jobType = 'manual'): int
    {
        $now = date('c');
        $this->db->execute(
            "INSERT INTO sync_jobs (connection_id, dataset_id, job_type, status, records_processed, records_added, records_updated, created_at)
             VALUES (:cid, :did, :type, 'pending', 0, 0, 0, :now)",
            [
                ':cid' => $connectionId,
                ':did' => $datasetId,
                ':type' => $jobType,
                ':now' => $now,
            ]
        );

        $jobId = (int) $this->db->lastInsertId();
        $this->logStep($jobId, $connectionId, 'info', "Sync job #{$jobId} queued (type: {$jobType})");
        return $jobId;
    }

    /**
     * Mark a job as running and record start time.
     */
    public function startJob(int $jobId): bool
    {
        $now = date('c');
        $this->db->execute(
            "UPDATE sync_jobs SET status = 'running', started_at = :now WHERE id = :id",
            [':now' => $now, ':id' => $jobId]
        );

        $job = $this->getJob($jobId);
        if ($job) {
            $this->logStep($jobId, (int) $job['connection_id'], 'info', "Sync job #{$jobId} execution started.");
        }

        return true;
    }

    /**
     * Incrementally update progress counts of a running job.
     */
    public function updateProgress(int $jobId, int $processed, int $added = 0, int $updated = 0): void
    {
        $this->db->execute(
            "UPDATE sync_jobs 
             SET records_processed = records_processed + :p,
                 records_added = records_added + :a,
                 records_updated = records_updated + :u
             WHERE id = :id",
            [
                ':p' => $processed,
                ':a' => $added,
                ':u' => $updated,
                ':id' => $jobId,
            ]
        );
    }

    /**
     * Mark job successfully completed and update connection metadata.
     */
    public function completeJob(int $jobId, int $added, int $updated): void
    {
        $now = date('c');
        $this->db->execute(
            "UPDATE sync_jobs 
             SET status = 'completed',
                 records_added = :a,
                 records_updated = :u,
                 records_processed = :a + :u,
                 finished_at = :now
             WHERE id = :id",
            [
                ':a' => $added,
                ':u' => $updated,
                ':now' => $now,
                ':id' => $jobId,
            ]
        );

        $job = $this->getJob($jobId);
        if ($job) {
            $cid = (int) $job['connection_id'];
            $this->db->execute(
                "UPDATE platform_connections 
                 SET last_sync_at = :now, last_sync_status = 'success', status = 'connected', updated_at = :now
                 WHERE id = :cid",
                [':now' => $now, ':cid' => $cid]
            );

            $this->logStep($jobId, $cid, 'info', "Sync job #{$jobId} completed successfully.", [
                'records_added' => $added,
                'records_updated' => $updated,
            ]);
        }
    }

    /**
     * Mark job failed with error message and update connection status.
     */
    public function failJob(int $jobId, string $errorMessage): void
    {
        $now = date('c');
        $this->db->execute(
            "UPDATE sync_jobs 
             SET status = 'failed',
                 error_message = :err,
                 finished_at = :now
             WHERE id = :id",
            [
                ':err' => $errorMessage,
                ':now' => $now,
                ':id' => $jobId,
            ]
        );

        $job = $this->getJob($jobId);
        if ($job) {
            $cid = (int) $job['connection_id'];
            $this->db->execute(
                "UPDATE platform_connections 
                 SET last_sync_at = :now, last_sync_status = 'failed', updated_at = :now
                 WHERE id = :cid",
                [':now' => $now, ':cid' => $cid]
            );

            $this->logStep($jobId, $cid, 'error', "Sync job #{$jobId} failed: {$errorMessage}", [
                'error' => $errorMessage,
            ]);
        }
    }

    /**
     * Mark job as partially completed (useful when hitting rate limit or timeout mid-sync).
     */
    public function markPartial(int $jobId, int $added, int $updated, string $reason): void
    {
        $now = date('c');
        $this->db->execute(
            "UPDATE sync_jobs 
             SET status = 'partial',
                 records_added = :a,
                 records_updated = :u,
                 records_processed = :a + :u,
                 error_message = :reason,
                 finished_at = :now
             WHERE id = :id",
            [
                ':a' => $added,
                ':u' => $updated,
                ':reason' => $reason,
                ':now' => $now,
                ':id' => $jobId,
            ]
        );

        $job = $this->getJob($jobId);
        if ($job) {
            $cid = (int) $job['connection_id'];
            $this->db->execute(
                "UPDATE platform_connections 
                 SET last_sync_at = :now, last_sync_status = 'partial', updated_at = :now
                 WHERE id = :cid",
                [':now' => $now, ':cid' => $cid]
            );

            $this->logStep($jobId, $cid, 'warning', "Sync job #{$jobId} partially completed: {$reason}");
        }
    }

    /**
     * Append a detailed step to sync_logs with sensitive token masking.
     */
    public function logStep(int $jobId, int $connectionId, string $level, string $message, ?array $context = null): void
    {
        $now = date('c');
        $cleanContext = $context !== null ? json_encode($this->maskContext($context), JSON_UNESCAPED_SLASHES) : null;

        $this->db->execute(
            "INSERT INTO sync_logs (job_id, connection_id, log_level, message, context_json, created_at)
             VALUES (:jid, :cid, :lvl, :msg, :ctx, :now)",
            [
                ':jid' => $jobId,
                ':cid' => $connectionId,
                ':lvl' => strtolower($level),
                ':msg' => $message,
                ':ctx' => $cleanContext,
                ':now' => $now,
            ]
        );
    }

    /**
     * Mask sensitive fields from context array before writing to database logs.
     */
    private function maskContext(array $data): array
    {
        $sensitiveKeys = ['token', 'password', 'key', 'secret', 'auth', 'bearer', 'credential'];
        $clean = [];

        foreach ($data as $k => $v) {
            $lower = strtolower((string) $k);
            $sensitive = false;
            foreach ($sensitiveKeys as $pattern) {
                if (str_contains($lower, $pattern)) {
                    $sensitive = true;
                    break;
                }
            }

            if ($sensitive) {
                $clean[$k] = '***REDACTED***';
            } elseif (is_array($v)) {
                $clean[$k] = $this->maskContext($v);
            } else {
                $clean[$k] = $v;
            }
        }

        return $clean;
    }

    /**
     * Get active pagination cursor for a connection and resource.
     */
    public function getCursor(int $connectionId, string $resourceIdentifier): ?array
    {
        return $this->db->queryOne(
            "SELECT cursor_value, cursor_type, updated_at 
             FROM sync_cursors 
             WHERE connection_id = :cid AND resource_identifier = :res LIMIT 1",
            [':cid' => $connectionId, ':res' => $resourceIdentifier]
        );
    }

    /**
     * Upsert pagination cursor value.
     */
    public function saveCursor(int $connectionId, string $resourceIdentifier, string $cursorValue, string $cursorType = 'token'): void
    {
        $now = date('c');
        $this->db->execute(
            "INSERT INTO sync_cursors (connection_id, resource_identifier, cursor_value, cursor_type, updated_at)
             VALUES (:cid, :res, :val, :type, :now)
             ON CONFLICT(connection_id, resource_identifier) DO UPDATE SET
                 cursor_value = excluded.cursor_value,
                 cursor_type = excluded.cursor_type,
                 updated_at = excluded.updated_at",
            [
                ':cid' => $connectionId,
                ':res' => $resourceIdentifier,
                ':val' => $cursorValue,
                ':type' => $cursorType,
                ':now' => $now,
            ]
        );
    }

    /**
     * Reset pagination cursor to restart sync from beginning.
     */
    public function resetCursor(int $connectionId, string $resourceIdentifier): void
    {
        $this->db->execute(
            "DELETE FROM sync_cursors WHERE connection_id = :cid AND resource_identifier = :res",
            [':cid' => $connectionId, ':res' => $resourceIdentifier]
        );
    }

    /**
     * Fetch single sync job record.
     */
    public function getJob(int $jobId): ?array
    {
        return $this->db->queryOne("SELECT * FROM sync_jobs WHERE id = :id LIMIT 1", [':id' => $jobId]);
    }

    /**
     * Fetch latest job for a connection.
     */
    public function getLatestJob(int $connectionId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM sync_jobs WHERE connection_id = :cid ORDER BY id DESC LIMIT 1",
            [':cid' => $connectionId]
        );
    }

    /**
     * Fetch job execution history for a connection.
     */
    public function getHistory(int $connectionId, int $limit = 20): array
    {
        return $this->db->queryAll(
            "SELECT j.*, d.name as dataset_name
             FROM sync_jobs j
             LEFT JOIN datasets d ON j.dataset_id = d.id
             WHERE j.connection_id = :cid
             ORDER BY j.id DESC
             LIMIT :lim",
            [':cid' => $connectionId, ':lim' => $limit]
        );
    }

    /**
     * Fetch logs for a specific sync job.
     */
    public function getJobLogs(int $jobId): array
    {
        return $this->db->queryAll(
            "SELECT * FROM sync_logs WHERE job_id = :jid ORDER BY id ASC",
            [':jid' => $jobId]
        );
    }
}
