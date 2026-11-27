<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

/**
 * AnaBot Analysis Session Model
 * 
 * Manages conversation sessions between the user and the AI Analyst.
 */
class AnalysisSession
{
    /**
     * Find a session by ID.
     */
    public static function find(int $id, ?int $userId = null): ?array
    {
        $db = Database::getInstance();
        $sql = "SELECT s.*, p.name AS provider_name, p.provider_code, m.model_name
                FROM analysis_sessions s
                LEFT JOIN ai_providers p ON s.provider_id = p.id
                LEFT JOIN ai_models m ON s.model_id = m.id
                WHERE s.id = :id";
        $params = [':id' => $id];

        if ($userId !== null) {
            $sql .= " AND s.user_id = :uid";
            $params[':uid'] = $userId;
        }

        $session = $db->queryOne($sql, $params);
        if (!$session) {
            return null;
        }

        $session['attached_datasets'] = self::getAttachedDatasets($id);
        return $session;
    }

    /**
     * Get all sessions for a user with metadata.
     */
    public static function allForUser(int $userId): array
    {
        $db = Database::getInstance();
        $sql = "SELECT s.*, 
                       p.name AS provider_name, 
                       p.provider_code, 
                       m.model_name,
                       (SELECT COUNT(*) FROM analysis_messages WHERE session_id = s.id) AS message_count,
                       (SELECT MAX(created_at) FROM analysis_messages WHERE session_id = s.id) AS last_message_at,
                       (SELECT COUNT(*) FROM analysis_sources WHERE session_id = s.id) AS dataset_count
                FROM analysis_sessions s
                LEFT JOIN ai_providers p ON s.provider_id = p.id
                LEFT JOIN ai_models m ON s.model_id = m.id
                WHERE s.user_id = :uid
                ORDER BY s.updated_at DESC, s.id DESC";

        $sessions = $db->query($sql, [':uid' => $userId]);

        foreach ($sessions as &$sess) {
            $sess['attached_datasets'] = self::getAttachedDatasets((int) $sess['id']);
        }
        unset($sess);

        return $sessions;
    }

    /**
     * Create a new analysis session.
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $now = date('c');

        $db->execute(
            "INSERT INTO analysis_sessions 
             (user_id, title, description, provider_id, model_id, created_at, updated_at)
             VALUES (:uid, :title, :desc, :pid, :mid, :created, :updated)",
            [
                ':uid' => $data['user_id'],
                ':title' => $data['title'] ?? 'Sesi Analisis ' . date('d/m/Y H:i'),
                ':desc' => $data['description'] ?? null,
                ':pid' => $data['provider_id'] ?? null,
                ':mid' => $data['model_id'] ?? null,
                ':created' => $now,
                ':updated' => $now,
            ]
        );

        $sessionId = (int) $db->lastInsertId();

        if (!empty($data['dataset_ids']) && is_array($data['dataset_ids'])) {
            self::syncDatasets($sessionId, $data['dataset_ids']);
        }

        return $sessionId;
    }

    /**
     * Update session details.
     */
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();
        $fields = [];
        $params = [':id' => $id];

        if (array_key_exists('title', $data)) {
            $fields[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $fields[] = "description = :desc";
            $params[':desc'] = $data['description'];
        }
        if (array_key_exists('provider_id', $data)) {
            $fields[] = "provider_id = :pid";
            $params[':pid'] = $data['provider_id'];
        }
        if (array_key_exists('model_id', $data)) {
            $fields[] = "model_id = :mid";
            $params[':mid'] = $data['model_id'];
        }

        $fields[] = "updated_at = :updated";
        $params[':updated'] = date('c');

        $sql = "UPDATE analysis_sessions SET " . implode(', ', $fields) . " WHERE id = :id";
        $affected = $db->execute($sql, $params);

        if (isset($data['dataset_ids']) && is_array($data['dataset_ids'])) {
            self::syncDatasets($id, $data['dataset_ids']);
        }

        return $affected > 0;
    }

    /**
     * Delete session and cascades.
     */
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $affected = $db->execute("DELETE FROM analysis_sessions WHERE id = :id", [':id' => $id]);
        return $affected > 0;
    }

    /**
     * Attach a dataset to the session.
     */
    public static function attachDataset(int $sessionId, int $datasetId): bool
    {
        $db = Database::getInstance();
        $now = date('c');

        try {
            $db->execute(
                "INSERT OR IGNORE INTO analysis_sources (session_id, dataset_id, created_at)
                 VALUES (:sid, :did, :now)",
                [':sid' => $sessionId, ':did' => $datasetId, ':now' => $now]
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Detach a dataset from the session.
     */
    public static function detachDataset(int $sessionId, int $datasetId): bool
    {
        $db = Database::getInstance();
        $affected = $db->execute(
            "DELETE FROM analysis_sources WHERE session_id = :sid AND dataset_id = :did",
            [':sid' => $sessionId, ':did' => $datasetId]
        );
        return $affected > 0;
    }

    /**
     * Get datasets attached to this session.
     */
    public static function getAttachedDatasets(int $sessionId): array
    {
        $db = Database::getInstance();
        $sql = "SELECT d.id, d.name, d.record_count, d.first_record_at, d.latest_record_at,
                       p.name AS platform_name, p.connector_code AS platform_code
                FROM analysis_sources s
                JOIN datasets d ON s.dataset_id = d.id
                LEFT JOIN data_sources ds ON d.data_source_id = ds.id
                LEFT JOIN platform_connections pc ON ds.connection_id = pc.id
                LEFT JOIN connectors p ON pc.connector_id = p.id
                WHERE s.session_id = :sid
                ORDER BY d.name ASC";

        return $db->query($sql, [':sid' => $sessionId]);
    }

    /**
     * Synchronize attached datasets.
     */
    public static function syncDatasets(int $sessionId, array $datasetIds): void
    {
        $db = Database::getInstance();
        $db->execute("DELETE FROM analysis_sources WHERE session_id = :sid", [':sid' => $sessionId]);

        foreach ($datasetIds as $did) {
            $did = (int) $did;
            if ($did > 0) {
                self::attachDataset($sessionId, $did);
            }
        }
    }

    /**
     * Touch session updated_at timestamp.
     */
    public static function touch(int $id): void
    {
        $db = Database::getInstance();
        $db->execute(
            "UPDATE analysis_sessions SET updated_at = :now WHERE id = :id",
            [':now' => date('c'), ':id' => $id]
        );
    }
}
