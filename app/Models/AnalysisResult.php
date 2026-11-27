<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

/**
 * AnaBot Analysis Result Model
 * 
 * Manages persisted deterministic calculation results in the analysis_results table.
 */
class AnalysisResult
{
    /**
     * Store or replace an analysis result record.
     */
    public static function store(
        int $datasetId,
        string $metricName,
        string $analysisType,
        array $calculatedValue,
        ?int $sessionId = null,
        ?string $aiInterpretation = null
    ): int {
        $db = Database::getInstance();
        $now = date('c');
        $json = json_encode($calculatedValue, JSON_UNESCAPED_SLASHES);

        // Delete existing result for the same dataset, metric, and analysis type to maintain single canonical result
        $db->execute(
            "DELETE FROM analysis_results 
             WHERE dataset_id = :did AND metric_name = :metric AND analysis_type = :type",
            [
                ':did' => $datasetId,
                ':metric' => $metricName,
                ':type' => $analysisType,
            ]
        );

        $db->execute(
            "INSERT INTO analysis_results 
             (session_id, dataset_id, metric_name, analysis_type, calculated_value_json, ai_interpretation, calculated_at)
             VALUES (:sid, :did, :metric, :type, :json, :ai, :now)",
            [
                ':sid' => $sessionId,
                ':did' => $datasetId,
                ':metric' => $metricName,
                ':type' => $analysisType,
                ':json' => $json,
                ':ai' => $aiInterpretation,
                ':now' => $now,
            ]
        );

        return (int) $db->lastInsertId();
    }

    /**
     * Find a specific result by dataset, metric, and type.
     */
    public static function find(int $datasetId, string $metricName, string $analysisType): ?array
    {
        $db = Database::getInstance();
        $row = $db->queryOne(
            "SELECT * FROM analysis_results 
             WHERE dataset_id = :did AND metric_name = :metric AND analysis_type = :type 
             ORDER BY id DESC LIMIT 1",
            [
                ':did' => $datasetId,
                ':metric' => $metricName,
                ':type' => $analysisType,
            ]
        );

        if ($row && !empty($row['calculated_value_json'])) {
            $row['calculated_value'] = json_decode($row['calculated_value_json'], true) ?: [];
        }

        return $row;
    }

    /**
     * Get all results for a dataset.
     */
    public static function getByDataset(int $datasetId): array
    {
        $db = Database::getInstance();
        $rows = $db->queryAll(
            "SELECT * FROM analysis_results 
             WHERE dataset_id = :did 
             ORDER BY analysis_type ASC, metric_name ASC",
            [':did' => $datasetId]
        );

        foreach ($rows as &$row) {
            $row['calculated_value'] = json_decode((string) ($row['calculated_value_json'] ?? '{}'), true) ?: [];
        }

        return $rows;
    }

    /**
     * Delete all results for a dataset.
     */
    public static function deleteByDataset(int $datasetId): int
    {
        $db = Database::getInstance();
        return $db->execute(
            "DELETE FROM analysis_results WHERE dataset_id = :did",
            [':did' => $datasetId]
        );
    }
}
