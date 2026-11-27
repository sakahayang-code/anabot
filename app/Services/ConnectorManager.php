<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Connectors\ConnectorInterface;
use AnaBot\Connectors\CustomAPI\CustomAPIConnector;
use AnaBot\Connectors\GoogleSheets\GoogleSheetsConnector;
use AnaBot\Core\Database;
use AnaBot\Core\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * AnaBot Central Connector Orchestrator
 * 
 * Manages connector lifecycle, driver resolution, test execution,
 * cursor-based incremental sync, and credential protection.
 */
class ConnectorManager
{
    private Database $db;
    private CredentialManager $credentialManager;
    private SyncQueueManager $syncQueue;
    private Logger $logger;
    private array $registeredDrivers = [];

    public function __construct(
        ?Database $db = null,
        ?CredentialManager $credentialManager = null,
        ?SyncQueueManager $syncQueue = null,
        ?Logger $logger = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->credentialManager = $credentialManager ?? new CredentialManager($this->db);
        $this->syncQueue = $syncQueue ?? new SyncQueueManager($this->db);
        $this->logger = $logger ?? new Logger();

        // Register default concrete drivers
        $this->registerDriver('google_sheets', GoogleSheetsConnector::class);
        $this->registerDriver('custom_api', CustomAPIConnector::class);
    }

    /**
     * Register a connector driver class.
     */
    public function registerDriver(string $code, string $class): void
    {
        if (!is_subclass_of($class, ConnectorInterface::class)) {
            throw new InvalidArgumentException("Class {$class} must implement " . ConnectorInterface::class);
        }
        $this->registeredDrivers[$code] = $class;
    }

    /**
     * Ensure all supported connectors from config exist in the database table.
     */
    public function syncCatalogToDatabase(): void
    {
        $catalog = (array) config('connectors.supported', []);
        $now = date('c');

        foreach ($catalog as $code => $meta) {
            $existing = $this->db->queryOne("SELECT id FROM connectors WHERE connector_code = :c LIMIT 1", [':c' => $code]);
            if (!$existing) {
                $this->db->execute(
                    "INSERT INTO connectors (connector_code, name, category, auth_type, version, is_available, created_at)
                     VALUES (:c, :n, :cat, :a, :v, 1, :now)",
                    [
                        ':c' => $code,
                        ':n' => $meta['name'],
                        ':cat' => $meta['category'],
                        ':a' => $meta['auth_type'],
                        ':v' => $meta['version'] ?? '1.0.0',
                        ':now' => $now,
                    ]
                );
            }
        }
    }

    /**
     * Return catalog of supported connectors with database metadata.
     */
    public function listCatalog(): array
    {
        $this->syncCatalogToDatabase();
        $configCatalog = (array) config('connectors.supported', []);
        $dbConnectors = $this->db->queryAll("SELECT * FROM connectors ORDER BY id ASC");

        $catalog = [];
        foreach ($dbConnectors as $row) {
            $code = $row['connector_code'];
            $meta = $configCatalog[$code] ?? [];
            $catalog[$code] = array_merge($row, [
                'icon' => $meta['icon'] ?? 'link',
                'color' => $meta['color'] ?? '#3b82f6',
                'badge' => $meta['badge'] ?? 'Connector',
                'description' => $meta['description'] ?? 'Platform integration',
                'has_driver' => isset($this->registeredDrivers[$code]),
                'scopes' => $meta['scopes'] ?? [],
            ]);
        }

        return $catalog;
    }

    /**
     * Instantiate and configure driver for a connection.
     */
    public function getDriver(string $connectorCode, ?int $connectionId = null, array $customConfig = []): ConnectorInterface
    {
        $class = $this->registeredDrivers[$connectorCode] ?? null;
        if (!$class) {
            // Fallback to CustomAPIConnector for generic REST integrations
            $class = CustomAPIConnector::class;
        }

        $credentials = [];
        $config = $customConfig;

        if ($connectionId !== null) {
            $credentials = $this->credentialManager->get($connectionId) ?? [];
        }

        /** @var ConnectorInterface $driver */
        $driver = new $class($config, $credentials, $connectionId, null, $this->db, $this->logger);
        return $driver;
    }

    /**
     * Test live connection credentials for a platform connection.
     */
    public function testConnection(int $connectionId): array
    {
        $conn = $this->db->queryOne(
            "SELECT c.*, conn.connector_code, conn.name as connector_name 
             FROM platform_connections c
             JOIN connectors conn ON c.connector_id = conn.id
             WHERE c.id = :id LIMIT 1",
            [':id' => $connectionId]
        );

        if (!$conn) {
            return ['success' => false, 'message' => "Connection #{$connectionId} not found."];
        }

        $code = $conn['connector_code'];
        $driver = $this->getDriver($code, $connectionId);

        $result = $driver->testConnection();
        $now = date('c');

        if ($result['success']) {
            $this->db->execute(
                "UPDATE platform_connections SET status = 'connected', updated_at = :now WHERE id = :id",
                [':now' => $now, ':id' => $connectionId]
            );
        } else {
            $this->db->execute(
                "UPDATE platform_connections SET status = 'error', updated_at = :now WHERE id = :id",
                [':now' => $now, ':id' => $connectionId]
            );
        }

        return $result;
    }

    /**
     * Test arbitrary credentials before creating connection.
     */
    public function testCredentials(string $connectorCode, array $config, array $credentials): array
    {
        $class = $this->registeredDrivers[$connectorCode] ?? CustomAPIConnector::class;
        /** @var ConnectorInterface $driver */
        $driver = new $class($config, $credentials, null, null, $this->db, $this->logger);
        return $driver->testConnection();
    }

    /**
     * Execute cursor-based incremental synchronization job.
     */
    public function syncConnection(int $connectionId, ?int $datasetId = null, string $jobType = 'manual', int $batchLimit = 100): array
    {
        $startTime = microtime(true);

        $conn = $this->db->queryOne(
            "SELECT c.*, conn.connector_code, conn.name as connector_name 
             FROM platform_connections c
             JOIN connectors conn ON c.connector_id = conn.id
             WHERE c.id = :id LIMIT 1",
            [':id' => $connectionId]
        );

        if (!$conn) {
            throw new RuntimeException("Connection #{$connectionId} not found.");
        }

        if ($conn['status'] === 'disconnected') {
            throw new RuntimeException("Connection #{$connectionId} is disconnected. Please re-authenticate.");
        }

        // 1. Create queue job
        $jobId = $this->syncQueue->createJob($connectionId, $datasetId, $jobType);
        $this->syncQueue->startJob($jobId);

        $code = $conn['connector_code'];
        $driver = $this->getDriver($code, $connectionId);

        try {
            // 2. Resolve dataset & resource
            $targetDataset = null;
            if ($datasetId !== null) {
                $targetDataset = $this->db->queryOne("SELECT * FROM datasets WHERE id = :id LIMIT 1", [':id' => $datasetId]);
            }

            if (!$targetDataset) {
                // Find first dataset associated with this connection or available resource
                $targetDataset = $this->db->queryOne(
                    "SELECT d.* FROM datasets d
                     JOIN data_sources s ON d.data_source_id = s.id
                     WHERE s.connection_id = :cid
                     ORDER BY d.id ASC LIMIT 1",
                    [':cid' => $connectionId]
                );
            }

            $resourceId = 'default';
            if ($targetDataset) {
                $resourceId = (string) $targetDataset['name'];
            } else {
                $available = $driver->getAvailableDatasets();
                if (!empty($available)) {
                    $resourceId = (string) $available[0]['id'];
                }
            }

            $this->syncQueue->logStep($jobId, $connectionId, 'info', "Fetching data for resource '{$resourceId}'", [
                'batch_limit' => $batchLimit,
            ]);

            // 3. Read current cursor
            $cursorRecord = $this->syncQueue->getCursor($connectionId, $resourceId);
            $currentCursor = $cursorRecord['cursor_value'] ?? null;

            if ($currentCursor !== null) {
                $this->syncQueue->logStep($jobId, $connectionId, 'info', "Resuming incremental sync with cursor '{$currentCursor}'");
            } else {
                $this->syncQueue->logStep($jobId, $connectionId, 'info', "Starting full sync from beginning (no previous cursor).");
            }

            // 4. Fetch data from external platform (READ-ONLY)
            $fetchResult = $driver->fetchData($resourceId, $currentCursor, $batchLimit);
            $records = $fetchResult['records'] ?? [];
            $nextCursor = $fetchResult['next_cursor'] ?? null;

            $recordsProcessed = count($records);
            $recordsAdded = 0;
            $recordsUpdated = 0;

            // 5. Store records if target dataset exists
            if ($targetDataset && !empty($records)) {
                $now = date('c');
                $targetDid = (int) $targetDataset['id'];

                foreach ($records as $index => $rec) {
                    $extId = (string) ($rec['id'] ?? $rec['external_id'] ?? $rec['_row_number'] ?? ($currentCursor ? $currentCursor . '_' . $index : (string) $index));
                    $recDate = $rec['date'] ?? $rec['created_at'] ?? $rec['timestamp'] ?? $now;
                    $jsonValues = json_encode($rec, JSON_UNESCAPED_SLASHES);

                    $existing = $this->db->queryOne(
                        "SELECT id FROM data_records WHERE dataset_id = :did AND external_id = :eid LIMIT 1",
                        [':did' => $targetDid, ':eid' => $extId]
                    );

                    if ($existing) {
                        $this->db->execute(
                            "UPDATE data_records SET record_date = :d, values_json = :v, updated_at = :u WHERE id = :id",
                            [':d' => $recDate, ':v' => $jsonValues, ':u' => $now, ':id' => $existing['id']]
                        );
                        $recordsUpdated++;
                    } else {
                        $this->db->execute(
                            "INSERT INTO data_records (dataset_id, external_id, record_date, values_json, created_at, updated_at)
                             VALUES (:did, :eid, :d, :v, :now, :now)",
                            [':did' => $targetDid, ':eid' => $extId, ':d' => $recDate, ':v' => $jsonValues, ':now' => $now]
                        );
                        $recordsAdded++;
                    }
                }

                // Update dataset metrics
                $this->db->execute(
                    "UPDATE datasets 
                     SET record_count = (SELECT COUNT(*) FROM data_records WHERE dataset_id = :did),
                         latest_record_at = :now,
                         updated_at = :now
                     WHERE id = :did",
                    [':now' => $now, ':did' => $targetDid]
                );
            }

            // 6. Save new cursor for incremental resumption
            if ($nextCursor !== null) {
                $this->syncQueue->saveCursor($connectionId, $resourceId, $nextCursor, 'cursor');
                $this->syncQueue->logStep($jobId, $connectionId, 'info', "Saved next cursor for resumption: '{$nextCursor}'");
            }

            // 7. Complete job
            $this->syncQueue->completeJob($jobId, $recordsAdded, $recordsUpdated);

            $duration = round(microtime(true) - $startTime, 2);

            return [
                'success' => true,
                'job_id' => $jobId,
                'records_processed' => $recordsProcessed,
                'records_added' => $recordsAdded,
                'records_updated' => $recordsUpdated,
                'next_cursor' => $nextCursor,
                'has_more' => $fetchResult['has_more'] ?? false,
                'duration_seconds' => $duration,
            ];
        } catch (\Throwable $e) {
            $this->syncQueue->failJob($jobId, $e->getMessage());
            $this->logger->error("Sync job #{$jobId} error: " . $e->getMessage(), ['exception' => get_class($e)]);

            return [
                'success' => false,
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'duration_seconds' => round(microtime(true) - $startTime, 2),
            ];
        }
    }

    /**
     * Safely disconnect a connection and revoke credentials.
     */
    public function disconnect(int $connectionId): bool
    {
        $conn = $this->db->queryOne(
            "SELECT c.*, conn.connector_code FROM platform_connections c
             JOIN connectors conn ON c.connector_id = conn.id
             WHERE c.id = :id LIMIT 1",
            [':id' => $connectionId]
        );

        if (!$conn) {
            return false;
        }

        try {
            $driver = $this->getDriver($conn['connector_code'], $connectionId);
            $driver->disconnect();
        } catch (\Throwable) {
            // Proceed with credential revocation regardless of driver error
        }

        $this->credentialManager->revoke($connectionId);
        $this->logger->info("Connection #{$connectionId} ({$conn['name']}) securely disconnected and credentials revoked.");

        return true;
    }
}
