<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

/**
 * AnaBot DataRecord Model
 */
class DataRecord
{
    public static function countForDataset(int $datasetId, string $search = '', string $filterCol = '', string $filterVal = ''): int
    {
        $db = Database::getInstance();
        $sql = "SELECT COUNT(*) as cnt FROM data_records WHERE dataset_id = :dataset_id";
        $params = [':dataset_id' => $datasetId];

        if (!empty($search)) {
            $sql .= " AND (data_json LIKE :search OR external_id LIKE :search OR record_date LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if (!empty($filterCol) && $filterVal !== '') {
            $sql .= " AND json_extract(data_json, '$.' || :fcol) = :fval";
            $params[':fcol'] = $filterCol;
            $params[':fval'] = $filterVal;
        }

        return (int) $db->queryOne($sql, $params)['cnt'];
    }

    public static function paginate(
        int $datasetId,
        int $page = 1,
        int $perPage = 20,
        string $search = '',
        string $sortCol = 'id',
        string $sortDir = 'ASC',
        string $filterCol = '',
        string $filterVal = ''
    ): array {
        $db = Database::getInstance();
        $offset = max(0, ($page - 1) * $perPage);

        // Sanitize sort direction
        $sortDir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';

        $sql = "SELECT id, dataset_id, external_id, record_date, data_json, created_at 
                FROM data_records 
                WHERE dataset_id = :dataset_id";
        $params = [
            ':dataset_id' => $datasetId,
            ':limit' => $perPage,
            ':offset' => $offset,
        ];

        if (!empty($search)) {
            $sql .= " AND (data_json LIKE :search OR external_id LIKE :search OR record_date LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if (!empty($filterCol) && $filterVal !== '') {
            $sql .= " AND json_extract(data_json, '$.' || :fcol) = :fval";
            $params[':fcol'] = $filterCol;
            $params[':fval'] = $filterVal;
        }

        // Sorting by native column or JSON property safely
        if (in_array($sortCol, ['id', 'record_date', 'external_id', 'created_at'], true)) {
            $sql .= " ORDER BY {$sortCol} {$sortDir}";
        } else {
            // Sort by JSON key using sqlite json_extract with sanitized key name
            $cleanSortCol = preg_replace('/[^a-zA-Z0-9_]/', '', $sortCol);
            $sql .= " ORDER BY json_extract(data_json, '$.{$cleanSortCol}') {$sortDir}";
        }

        $sql .= " LIMIT :limit OFFSET :offset";

        $rows = $db->query($sql, $params);

        // Decode JSON payload for each record
        foreach ($rows as &$row) {
            $row['data'] = json_decode((string) $row['data_json'], true) ?: [];
        }

        return $rows;
    }

    public static function cursorForExport(int $datasetId): \PDOStatement
    {
        $db = Database::getInstance();
        $stmt = $db->getPdo()->prepare(
            "SELECT id, external_id, record_date, data_json 
             FROM data_records 
             WHERE dataset_id = :dataset_id 
             ORDER BY id ASC"
        );
        $stmt->execute([':dataset_id' => $datasetId]);
        return $stmt;
    }

    /**
     * Get records for a dataset with limit and offset.
     */
    public static function getByDatasetId(int $datasetId, int $limit = 50, int $offset = 0): array
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT id, dataset_id, external_id, record_date, data_json, created_at 
             FROM data_records 
             WHERE dataset_id = :dataset_id 
             ORDER BY id ASC 
             LIMIT :limit OFFSET :offset",
            [
                ':dataset_id' => $datasetId,
                ':limit' => $limit,
                ':offset' => $offset,
            ]
        );

        foreach ($rows as &$row) {
            $row['data'] = json_decode((string) $row['data_json'], true) ?: [];
        }

        return $rows;
    }
}
