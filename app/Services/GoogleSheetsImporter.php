<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Connectors\GoogleSheets\GoogleSheetsConnector;
use AnaBot\Core\Database;
use AnaBot\Core\Logger;
use AnaBot\Services\HttpClient;
use RuntimeException;

/**
 * Service to orchestrate end-to-end import of Google Sheets data into SQLite.
 * Handles schema persistence, data normalization, batch insertion, and metric calculation.
 */
class GoogleSheetsImporter
{
    private Database $db;
    private CredentialManager $credentialManager;
    private SyncQueueManager $syncQueue;
    private Logger $logger;
    private ?HttpClient $httpClient;

    public function __construct(
        ?Database $db = null,
        ?CredentialManager $credentialManager = null,
        ?SyncQueueManager $syncQueue = null,
        ?Logger $logger = null,
        ?HttpClient $httpClient = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->credentialManager = $credentialManager ?? new CredentialManager($this->db);
        $this->syncQueue = $syncQueue ?? new SyncQueueManager($this->db);
        $this->logger = $logger ?? new Logger();
        $this->httpClient = $httpClient;
    }

    public function setHttpClient(?HttpClient $httpClient): self
    {
        $this->httpClient = $httpClient;
        return $this;
    }

    /**
     * Import a sheet tab into an isolated SQLite dataset.
     *
     * @param int $connectionId ID from platform_connections
     * @param string $sheetName Tab name in the spreadsheet
     * @param string|null $customDatasetName Display name for the dataset
     * @param array|null $customColumns Array of column definitions
     * @param int $batchSize Number of rows per batch
     * @return array Summary of import operation
     */
    public function importSheet(
        int $connectionId,
        string $sheetName,
        ?string $customDatasetName = null,
        ?array $customColumns = null,
        int $batchSize = 250
    ): array {
        $startTime = microtime(true);

        $conn = $this->db->queryOne(
            "SELECT c.*, conn.connector_code, conn.name as connector_name 
             FROM platform_connections c
             JOIN connectors conn ON c.connector_id = conn.id
             WHERE c.id = :id LIMIT 1",
            [':id' => $connectionId]
        );

        if (!$conn) {
            throw new RuntimeException("Platform connection #{$connectionId} not found.");
        }

        $credentials = $this->credentialManager->get($connectionId) ?? [];
        $connector = new GoogleSheetsConnector([], $credentials, $connectionId, $this->httpClient, $this->db, $this->logger);

        // 1. Resolve preview / columns
        $preview = $connector->previewSheet($sheetName, 10);
        $columnsToSave = $customColumns ?? $preview['columns'] ?? [];

        if (empty($columnsToSave)) {
            throw new RuntimeException("No valid column headers found in sheet tab '{$sheetName}'.");
        }

        // 2. Create or find Data Source
        $spreadsheetId = $connector->getSpreadsheetId();
        $sourceName = "Google Sheet [{$spreadsheetId}]";
        $now = date('c');

        $dataSource = $this->db->queryOne(
            "SELECT id FROM data_sources WHERE connection_id = :cid AND external_source_id = :eid LIMIT 1",
            [':cid' => $connectionId, ':eid' => $spreadsheetId]
        );

        $sourceId = $dataSource['id'] ?? null;
        if (!$sourceId) {
            $this->db->execute(
                "INSERT INTO data_sources (connection_id, external_source_id, source_name, created_at, updated_at)
                 VALUES (:cid, :eid, :name, :now, :now)",
                [
                    ':cid' => $connectionId,
                    ':eid' => $spreadsheetId,
                    ':name' => $sourceName,
                    ':now' => $now,
                ]
            );
            $sourceId = (int) $this->db->lastInsertId();
        }

        // 3. Create or find Dataset
        $datasetTitle = $customDatasetName ?? $sheetName;
        $dataset = $this->db->queryOne(
            "SELECT id FROM datasets WHERE data_source_id = :sid AND name = :name LIMIT 1",
            [':sid' => $sourceId, ':name' => $datasetTitle]
        );

        $datasetId = $dataset['id'] ?? null;
        if (!$datasetId) {
            $this->db->execute(
                "INSERT INTO datasets (data_source_id, name, description, record_count, created_at, updated_at)
                 VALUES (:sid, :name, :desc, 0, :now, :now)",
                [
                    ':sid' => $sourceId,
                    ':name' => $datasetTitle,
                    ':desc' => "Google Sheet tab: '{$sheetName}'",
                    ':now' => $now,
                ]
            );
            $datasetId = (int) $this->db->lastInsertId();
        }

        // 4. Save Column Schema definitions
        $primaryDateColumn = null;
        foreach ($columnsToSave as $order => $col) {
            $colName = $col['column_name'];
            $dataType = strtoupper((string) ($col['data_type'] ?? 'TEXT'));
            $isDate = !empty($col['is_primary_date']) ? 1 : 0;
            $isMetric = !empty($col['is_metric']) ? 1 : 0;
            $isDimension = !empty($col['is_dimension']) ? 1 : 0;

            if ($isDate && $primaryDateColumn === null) {
                $primaryDateColumn = $colName;
            }

            $this->db->execute(
                "INSERT INTO dataset_columns (dataset_id, column_name, data_type, is_primary_date, is_metric, is_dimension, column_order, created_at)
                 VALUES (:did, :col, :type, :date, :metric, :dim, :ord, :now)
                 ON CONFLICT(dataset_id, column_name) DO UPDATE SET
                     data_type = excluded.data_type,
                     is_primary_date = excluded.is_primary_date,
                     is_metric = excluded.is_metric,
                     is_dimension = excluded.is_dimension,
                     column_order = excluded.column_order",
                [
                    ':did' => $datasetId,
                    ':col' => $colName,
                    ':type' => $dataType,
                    ':date' => $isDate,
                    ':metric' => $isMetric,
                    ':dim' => $isDimension,
                    ':ord' => $order,
                    ':now' => $now,
                ]
            );
        }

        // 5. Create Sync Job for audit
        $jobId = $this->syncQueue->createJob($connectionId, $datasetId, 'import');
        $this->syncQueue->startJob($jobId);

        // 6. Fetch and insert records in batches
        $cursor = '2'; // Start at row 2 (row 1 is header)
        $totalProcessed = 0;
        $totalAdded = 0;
        $totalUpdated = 0;
        $hasMore = true;
        $datesCollected = [];

        while ($hasMore) {
            $batchResult = $connector->fetchData($sheetName, $cursor, $batchSize);
            $records = $batchResult['records'] ?? [];
            $hasMore = $batchResult['has_more'] && !empty($records);
            $cursor = $batchResult['next_cursor'];

            if (empty($records)) {
                break;
            }

            // Atomic batch transaction
            $this->db->beginTransaction();
            try {
                foreach ($records as $rec) {
                    $rowNum = $rec['_row_number'] ?? $totalProcessed + 1;
                    $extId = "{$sheetName}_{$rowNum}";

                    // Extract date
                    $recDate = null;
                    if ($primaryDateColumn && isset($rec[$primaryDateColumn]) && !empty($rec[$primaryDateColumn])) {
                        $recDate = DataNormalizer::normalizeDate((string) $rec[$primaryDateColumn]);
                        $datesCollected[] = $recDate;
                    } else {
                        $recDate = $now;
                    }

                    $valuesJson = json_encode($rec, JSON_UNESCAPED_SLASHES);
                    $recordHash = hash('sha256', (string) $extId);

                    $existing = $this->db->queryOne(
                        "SELECT id FROM data_records WHERE dataset_id = :did AND external_id = :eid LIMIT 1",
                        [':did' => $datasetId, ':eid' => $extId]
                    );

                    if ($existing) {
                        $this->db->execute(
                            "UPDATE data_records SET record_date = :d, record_hash = :hash, data_json = :v, updated_at = :u WHERE id = :id",
                            [':d' => $recDate, ':hash' => $recordHash, ':v' => $valuesJson, ':u' => $now, ':id' => $existing['id']]
                        );
                        $totalUpdated++;
                    } else {
                        $this->db->execute(
                            "INSERT INTO data_records (dataset_id, external_id, record_date, record_hash, data_json, created_at, updated_at)
                             VALUES (:did, :eid, :d, :hash, :v, :now, :now)",
                            [':did' => $datasetId, ':eid' => $extId, ':d' => $recDate, ':hash' => $recordHash, ':v' => $valuesJson, ':now' => $now]
                        );
                        $totalAdded++;
                    }

                    $totalProcessed++;
                }

                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                $this->syncQueue->failJob($jobId, "Batch import error: " . $e->getMessage());
                throw $e;
            }

            $this->syncQueue->updateProgress($jobId, count($records), $totalAdded, $totalUpdated);

            // Safety ceiling to prevent timeout on very large sheets (e.g. 5,000 rows max in single web request)
            if ($totalProcessed >= 5000) {
                break;
            }
        }

        // 7. Update Dataset Summary Statistics
        $dateCount = count($datesCollected);
        $firstDate = $dateCount > 0 ? min($datesCollected) : $now;
        $latestDate = $dateCount > 0 ? max($datesCollected) : $now;

        $this->db->execute(
            "UPDATE datasets 
             SET record_count = (SELECT COUNT(*) FROM data_records WHERE dataset_id = :did),
                 first_record_at = :first_d,
                 latest_record_at = :last_d,
                 updated_at = :now
             WHERE id = :did",
            [
                ':did' => $datasetId,
                ':first_d' => $firstDate,
                ':last_d' => $latestDate,
                ':now' => $now,
            ]
        );

        // 8. Complete Sync Job
        $this->syncQueue->completeJob($jobId, $totalAdded, $totalUpdated);

        // Save last cursor for incremental updates
        if ($cursor !== null) {
            $this->syncQueue->saveCursor($connectionId, $sheetName, $cursor, 'row_offset');
        }

        $duration = round(microtime(true) - $startTime, 2);

        return [
            'success' => true,
            'dataset_id' => $datasetId,
            'dataset_name' => $datasetTitle,
            'records_processed' => $totalProcessed,
            'records_added' => $totalAdded,
            'records_updated' => $totalUpdated,
            'columns_count' => count($columnsToSave),
            'first_record_at' => $firstDate,
            'latest_record_at' => $latestDate,
            'duration_seconds' => $duration,
        ];
    }
}
