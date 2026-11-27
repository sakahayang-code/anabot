<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

class PlatformConnection
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findById(int $id): ?array
    {
        return $this->db->queryOne(
            "SELECT c.*, conn.connector_code, conn.name as connector_name, conn.category, conn.auth_type
             FROM platform_connections c
             JOIN connectors conn ON c.connector_id = conn.id
             WHERE c.id = :id LIMIT 1",
            [':id' => $id]
        );
    }

    public function getAllWithConnectors(): array
    {
        return $this->db->queryAll(
            "SELECT c.*, conn.connector_code, conn.name as connector_name, conn.category, conn.auth_type,
                    (SELECT COUNT(*) FROM datasets d JOIN data_sources ds ON d.data_source_id = ds.id WHERE ds.connection_id = c.id) as dataset_count,
                    (SELECT COALESCE(SUM(d.record_count), 0) FROM datasets d JOIN data_sources ds ON d.data_source_id = ds.id WHERE ds.connection_id = c.id) as total_records
             FROM platform_connections c
             JOIN connectors conn ON c.connector_id = conn.id
             ORDER BY c.created_at DESC"
        );
    }

    public function create(int $connectorId, int $userId, string $name, int $syncIntervalMinutes = 60, bool $autoSync = true): int
    {
        $now = date('c');
        $this->db->execute(
            "INSERT INTO platform_connections (connector_id, user_id, name, status, sync_interval_minutes, auto_sync_enabled, created_at, updated_at)
             VALUES (:cid, :uid, :name, 'connected', :sim, :ase, :now, :now)",
            [
                ':cid' => $connectorId,
                ':uid' => $userId,
                ':name' => $name,
                ':sim' => $syncIntervalMinutes,
                ':ase' => $autoSync ? 1 : 0,
                ':now' => $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    public function updateStatus(int $id, string $status): bool
    {
        $now = date('c');
        return $this->db->execute(
            "UPDATE platform_connections SET status = :s, updated_at = :now WHERE id = :id",
            [':s' => $status, ':now' => $now, ':id' => $id]
        ) > 0;
    }

    public function updateSettings(int $id, string $name, int $intervalMinutes, bool $autoSync): bool
    {
        $now = date('c');
        return $this->db->execute(
            "UPDATE platform_connections 
             SET name = :name, sync_interval_minutes = :sim, auto_sync_enabled = :ase, updated_at = :now 
             WHERE id = :id",
            [
                ':name' => $name,
                ':sim' => $intervalMinutes,
                ':ase' => $autoSync ? 1 : 0,
                ':now' => $now,
                ':id' => $id,
            ]
        ) > 0;
    }

    public function delete(int $id): bool
    {
        return $this->db->execute("DELETE FROM platform_connections WHERE id = :id", [':id' => $id]) > 0;
    }
}
