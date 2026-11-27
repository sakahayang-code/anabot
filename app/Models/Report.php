<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

/**
 * AnaBot Report Model
 * 
 * Manages persisted reports records in the SQLite database.
 */
class Report
{
    /**
     * Create a new report record.
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $now = date('c');

        $db->execute(
            "INSERT INTO reports 
             (user_id, title, report_type, file_path, file_size_bytes, date_range_start, date_range_end, summary_content, status, created_at)
             VALUES (:uid, :title, :type, :path, :size, :start, :end, :summary, :status, :created)",
            [
                ':uid' => (int) $data['user_id'],
                ':title' => (string) $data['title'],
                ':type' => strtolower((string) ($data['report_type'] ?? 'html')),
                ':path' => (string) $data['file_path'],
                ':size' => (int) ($data['file_size_bytes'] ?? 0),
                ':start' => $data['date_range_start'] ?? null,
                ':end' => $data['date_range_end'] ?? null,
                ':summary' => $data['summary_content'] ?? null,
                ':status' => (string) ($data['status'] ?? 'generated'),
                ':created' => $data['created_at'] ?? $now,
            ]
        );

        return (int) $db->lastInsertId();
    }

    /**
     * Find a report by its ID.
     */
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->queryOne(
            "SELECT r.*, u.username, u.email 
             FROM reports r
             JOIN users u ON r.user_id = u.id
             WHERE r.id = :id LIMIT 1",
            [':id' => $id]
        );
    }

    /**
     * Get paginated reports for a user with optional type filtering.
     */
    public static function getByUser(int $userId, int $limit = 50, int $offset = 0, ?string $type = null): array
    {
        $db = Database::getInstance();
        $params = [':uid' => $userId, ':limit' => $limit, ':offset' => $offset];
        $whereType = '';

        if (!empty($type)) {
            $whereType = ' AND r.report_type = :type';
            $params[':type'] = strtolower($type);
        }

        return $db->queryAll(
            "SELECT r.* 
             FROM reports r
             WHERE r.user_id = :uid {$whereType}
             ORDER BY r.id DESC
             LIMIT :limit OFFSET :offset",
            $params
        );
    }

    /**
     * Count total reports for a user with optional type filtering.
     */
    public static function countByUser(int $userId, ?string $type = null): int
    {
        $db = Database::getInstance();
        $params = [':uid' => $userId];
        $whereType = '';

        if (!empty($type)) {
            $whereType = ' AND report_type = :type';
            $params[':type'] = strtolower($type);
        }

        $row = $db->queryOne(
            "SELECT COUNT(*) as cnt FROM reports WHERE user_id = :uid {$whereType}",
            $params
        );

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Get recent reports for dashboard or quick overview.
     */
    public static function getRecent(int $userId, int $limit = 5): array
    {
        return self::getByUser($userId, $limit, 0);
    }

    /**
     * Delete report record from database.
     */
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $affected = $db->execute("DELETE FROM reports WHERE id = :id", [':id' => $id]);
        return $affected > 0;
    }

    /**
     * Delete report record and remove its physical file from storage safely.
     */
    public static function deleteWithFile(int $id): bool
    {
        $report = self::find($id);
        if (!$report) {
            return false;
        }

        // Delete physical file if exists
        $filePath = $report['file_path'];
        if (!empty($filePath)) {
            $resolved = str_starts_with($filePath, '/') ? $filePath : dirname(__DIR__, 2) . '/' . ltrim($filePath, '/');
            if (file_exists($resolved)) {
                @unlink($resolved);
            }
        }

        return self::delete($id);
    }

    /**
     * Get aggregate storage statistics for a user.
     */
    public static function getStorageUsage(int $userId): array
    {
        $db = Database::getInstance();

        $totals = $db->queryOne(
            "SELECT COUNT(*) as total_count, COALESCE(SUM(file_size_bytes), 0) as total_bytes 
             FROM reports WHERE user_id = :uid",
            [':uid' => $userId]
        );

        $breakdown = $db->queryAll(
            "SELECT report_type, COUNT(*) as count, COALESCE(SUM(file_size_bytes), 0) as bytes 
             FROM reports 
             WHERE user_id = :uid 
             GROUP BY report_type 
             ORDER BY count DESC",
            [':uid' => $userId]
        );

        return [
            'total_reports' => (int) ($totals['total_count'] ?? 0),
            'total_bytes' => (int) ($totals['total_bytes'] ?? 0),
            'total_formatted' => format_bytes((int) ($totals['total_bytes'] ?? 0)),
            'by_type' => $breakdown,
        ];
    }
}
