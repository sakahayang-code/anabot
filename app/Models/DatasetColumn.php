<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

/**
 * AnaBot DatasetColumn Model
 */
class DatasetColumn
{
    public static function forDataset(int $datasetId): array
    {
        $db = Database::getInstance();
        return $db->query(
            "SELECT * FROM dataset_columns 
             WHERE dataset_id = :dataset_id 
             ORDER BY column_order ASC, id ASC",
            [':dataset_id' => $datasetId]
        );
    }

    /**
     * Alias for forDataset().
     */
    public static function getByDatasetId(int $datasetId): array
    {
        return self::forDataset($datasetId);
    }

    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->queryOne("SELECT * FROM dataset_columns WHERE id = :id LIMIT 1", [':id' => $id]);
    }

    public static function updateMetadata(int $id, array $data): bool
    {
        $db = Database::getInstance();
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['data_type'])) {
            $fields[] = 'data_type = :data_type';
            $params[':data_type'] = strtoupper((string) $data['data_type']);
        }
        if (isset($data['is_primary_date'])) {
            $fields[] = 'is_primary_date = :is_primary_date';
            $params[':is_primary_date'] = (int) $data['is_primary_date'];
        }
        if (isset($data['is_metric'])) {
            $fields[] = 'is_metric = :is_metric';
            $params[':is_metric'] = (int) $data['is_metric'];
        }
        if (isset($data['is_dimension'])) {
            $fields[] = 'is_dimension = :is_dimension';
            $params[':is_dimension'] = (int) $data['is_dimension'];
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE dataset_columns SET " . implode(', ', $fields) . " WHERE id = :id";
        return $db->execute($sql, $params) > 0;
    }
}
