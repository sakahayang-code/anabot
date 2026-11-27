<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

/**
 * AnaBot Dataset Model
 */
class Dataset
{
    public static function all(int $limit = 50, int $offset = 0): array
    {
        $db = Database::getInstance();
        return $db->query(
            "SELECT d.*, ds.source_name, pc.name as connection_name, c.name as platform_name, c.connector_code
             FROM datasets d
             LEFT JOIN data_sources ds ON d.data_source_id = ds.id
             LEFT JOIN platform_connections pc ON ds.connection_id = pc.id
             LEFT JOIN connectors c ON pc.connector_id = c.id
             ORDER BY d.updated_at DESC
             LIMIT :limit OFFSET :offset",
            [':limit' => $limit, ':offset' => $offset]
        );
    }

    public static function count(): int
    {
        $db = Database::getInstance();
        return (int) $db->queryOne("SELECT COUNT(*) as cnt FROM datasets")['cnt'];
    }

    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->queryOne(
            "SELECT d.*, ds.source_name, pc.name as connection_name, c.name as platform_name, c.connector_code
             FROM datasets d
             LEFT JOIN data_sources ds ON d.data_source_id = ds.id
             LEFT JOIN platform_connections pc ON ds.connection_id = pc.id
             LEFT JOIN connectors c ON pc.connector_id = c.id
             WHERE d.id = :id LIMIT 1",
            [':id' => $id]
        );
    }

    public static function findFirst(): ?array
    {
        $db = Database::getInstance();
        return $db->queryOne("SELECT * FROM datasets ORDER BY id ASC LIMIT 1");
    }

    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        // Foreign keys ON will automatically cascade delete dataset_columns and data_records
        $affected = $db->execute("DELETE FROM datasets WHERE id = :id", [':id' => $id]);
        return $affected > 0;
    }
}
