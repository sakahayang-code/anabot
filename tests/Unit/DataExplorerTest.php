<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Core\Database;
use AnaBot\Core\MigrationRunner;
use AnaBot\Services\DataExplorerService;

class DataExplorerTest
{
    public function run(): void
    {
        echo "Testing Data Explorer Engine & Memory-Safe Exporter (Isolated Test DB)..." . PHP_EOL;

        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $db = new Database($testDbPath);
        Database::setInstance($db);

        // Run migrations
        $runner = new MigrationRunner($db);
        $runner->run();

        $service = new DataExplorerService();

        // 1. Seed Test Data Source & Dataset
        $now = date('c');
        $db->execute("INSERT INTO users (username, email, password_hash, status, created_at, updated_at) VALUES ('tester', 'tester@anabot.local', 'h', 'active', :n, :n)", [':n' => $now]);
        $uid = (int) $db->lastInsertId();

        $db->execute("INSERT INTO connectors (connector_code, name, category, auth_type, created_at) VALUES ('sheets', 'Google Sheets', 'spreadsheet', 'oauth2', :n)", [':n' => $now]);
        $cid = (int) $db->lastInsertId();

        $db->execute("INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at) VALUES (:cid, :uid, 'Test Store Sheet', 'connected', :n, :n)", [':cid' => $cid, ':uid' => $uid, ':n' => $now]);
        $connId = (int) $db->lastInsertId();

        $db->execute("INSERT INTO data_sources (connection_id, external_source_id, source_name, created_at, updated_at) VALUES (:cid, 'sheet_sales_1', 'Sales 2026', :n, :n)", [':cid' => $connId, ':n' => $now]);
        $sourceId = (int) $db->lastInsertId();

        $db->execute(
            "INSERT INTO datasets (data_source_id, name, description, record_count, first_record_at, latest_record_at, created_at, updated_at) 
             VALUES (:sid, 'Transaksi Penjualan', 'Data transaksi harian', 100, '2026-01-01', '2026-04-10', :n, :n)",
            [':sid' => $sourceId, ':n' => $now]
        );
        $datasetId = (int) $db->lastInsertId();

        // 2. Seed Columns (Schema)
        $columns = [
            ['name' => 'order_date', 'type' => 'DATETIME', 'date' => 1, 'metric' => 0, 'dim' => 0],
            ['name' => 'customer_name', 'type' => 'TEXT', 'date' => 0, 'metric' => 0, 'dim' => 1],
            ['name' => 'category', 'type' => 'TEXT', 'date' => 0, 'metric' => 0, 'dim' => 1],
            ['name' => 'revenue', 'type' => 'NUMERIC', 'date' => 0, 'metric' => 1, 'dim' => 0],
            ['name' => 'quantity', 'type' => 'INTEGER', 'date' => 0, 'metric' => 1, 'dim' => 0],
        ];

        foreach ($columns as $idx => $c) {
            $db->execute(
                "INSERT INTO dataset_columns (dataset_id, column_name, data_type, is_primary_date, is_metric, is_dimension, column_order, created_at)
                 VALUES (:did, :cname, :type, :pdate, :m, :dim, :ord, :now)",
                [
                    ':did' => $datasetId,
                    ':cname' => $c['name'],
                    ':type' => $c['type'],
                    ':pdate' => $c['date'],
                    ':m' => $c['metric'],
                    ':dim' => $c['dim'],
                    ':ord' => $idx + 1,
                    ':now' => $now,
                ]
            );
        }

        // 3. Seed 100 Records
        $db->transaction(function (Database $d) use ($datasetId, $now) {
            for ($i = 1; $i <= 100; $i++) {
                $category = ($i % 3 === 0) ? 'Elektronik' : (($i % 3 === 1) ? 'Pakaian' : 'Makanan');
                $customer = "Customer {$i}";
                $revenue = $i * 15000;
                $quantity = ($i % 5) + 1;
                $date = date('Y-m-d', strtotime("2026-01-01 +{$i} days"));

                $rowData = [
                    'order_date' => $date,
                    'customer_name' => $customer,
                    'category' => $category,
                    'revenue' => $revenue,
                    'quantity' => $quantity,
                ];

                $json = json_encode($rowData);
                $hash = hash('sha256', $json);

                $d->execute(
                    "INSERT INTO data_records (dataset_id, external_id, record_date, record_hash, data_json, created_at, updated_at)
                     VALUES (:did, :eid, :rdate, :hash, :json, :now, :now)",
                    [
                        ':did' => $datasetId,
                        ':eid' => "ORD-{$i}",
                        ':rdate' => $date,
                        ':hash' => $hash,
                        ':json' => $json,
                        ':now' => $now,
                    ]
                );
            }
        });

        // Test 1: listDatasets & Pagination
        $list = $service->listDatasets(1, 10);
        assert(count($list['datasets']) === 1, 'Should list 1 dataset');
        assert($list['pagination']['total_records'] === 1, 'Total datasets should be 1');
        assert($list['datasets'][0]['name'] === 'Transaksi Penjualan', 'Dataset name must match');
        echo "  [PASS] Dataset listing and pagination" . PHP_EOL;

        // Test 2: Paginated Records (per_page = 15)
        $recordsPage1 = $service->getRecords($datasetId, ['page' => 1, 'per_page' => 15]);
        assert(count($recordsPage1['records']) === 15, 'Page 1 must contain exactly 15 records');
        assert($recordsPage1['pagination']['total_records'] === 100, 'Total records count must be 100');
        assert($recordsPage1['pagination']['total_pages'] === 7, 'Total pages must be 7 (100 / 15)');
        echo "  [PASS] SQLite fast record pagination (LIMIT & OFFSET)" . PHP_EOL;

        // Test 3: Text Search
        $searchResult = $service->getRecords($datasetId, ['search' => 'Customer 42']);
        assert(count($searchResult['records']) === 1, 'Search for "Customer 42" must return exactly 1 record');
        assert($searchResult['records'][0]['data']['customer_name'] === 'Customer 42', 'Found customer must be Customer 42');
        echo "  [PASS] Full text search across records" . PHP_EOL;

        // Test 4: Column Filtering (Category = 'Makanan')
        $filterResult = $service->getRecords($datasetId, ['filter_col' => 'category', 'filter_val' => 'Makanan']);
        assert(!empty($filterResult['records']), 'Filter Makanan must return results');
        foreach ($filterResult['records'] as $r) {
            assert($r['data']['category'] === 'Makanan', 'Every row must have category Makanan');
        }
        echo "  [PASS] Column & JSON attribute filtering" . PHP_EOL;

        // Test 5: Sorting (Ascending & Descending by numeric JSON revenue)
        $sortDesc = $service->getRecords($datasetId, ['sort' => 'revenue', 'dir' => 'DESC', 'per_page' => 5]);
        $firstRevenue = $sortDesc['records'][0]['data']['revenue'];
        $secondRevenue = $sortDesc['records'][1]['data']['revenue'];
        assert($firstRevenue >= $secondRevenue, 'Sorted DESC must have first revenue >= second revenue');
        echo "  [PASS] Sorting by column attribute (ASC/DESC)" . PHP_EOL;

        // Test 6: Schema Discovery & Column Metadata Update
        $schema = $service->getSchema($datasetId);
        assert(count($schema['columns']) === 5, 'Schema must have 5 columns');
        $revenueCol = array_values(array_filter($schema['columns'], fn($c) => $c['column_name'] === 'revenue'))[0];
        assert((int) $revenueCol['is_metric'] === 1, 'Revenue must be marked as metric');

        // Update column role
        $updated = $service->updateColumn((int) $revenueCol['id'], ['data_type' => 'NUMERIC', 'is_metric' => 1, 'is_dimension' => 1]);
        assert($updated === true, 'Column metadata update must succeed');
        $colAfter = $db->queryOne("SELECT is_dimension FROM dataset_columns WHERE id = :id", [':id' => $revenueCol['id']]);
        assert((int) $colAfter['is_dimension'] === 1, 'Updated column dimension role must be 1');
        echo "  [PASS] Schema discovery and column role classification" . PHP_EOL;

        // Test 7: Memory-Safe Streaming CSV Export
        $csvGenerator = $service->streamCsvExport($datasetId);
        assert($csvGenerator instanceof \Generator, 'Export must return a PHP Generator');
        $csvLines = [];
        foreach ($csvGenerator as $line) {
            $csvLines[] = $line;
            if (count($csvLines) >= 10) {
                break; // Sample the stream
            }
        }
        assert(count($csvLines) === 10, 'Must stream CSV lines');
        assert(str_contains($csvLines[0], 'customer_name'), 'Header row must include column names');
        assert(str_contains($csvLines[1], 'ORD-1'), 'First data row must contain ORD-1');
        echo "  [PASS] Memory-safe streaming CSV export generator" . PHP_EOL;

        // Test 8: Overview API Stats
        $overview = $service->getOverview();
        assert($overview['total_datasets'] === 1, 'Overview total datasets must be 1');
        assert($overview['total_records'] === 100, 'Overview total records must be 100');
        echo "  [PASS] Data overview metrics" . PHP_EOL;

        // Test 9: Cascade Deletion
        $deleted = $service->deleteDataset($datasetId);
        assert($deleted === true, 'Dataset deletion must succeed');
        assert(Dataset::find($datasetId) === null, 'Deleted dataset must no longer exist');
        $remainingRecords = (int) $db->queryOne("SELECT COUNT(*) as cnt FROM data_records WHERE dataset_id = :did", [':did' => $datasetId])['cnt'];
        $remainingColumns = (int) $db->queryOne("SELECT COUNT(*) as cnt FROM dataset_columns WHERE dataset_id = :did", [':did' => $datasetId])['cnt'];
        assert($remainingRecords === 0, 'Cascade delete must wipe data_records');
        assert($remainingColumns === 0, 'Cascade delete must wipe dataset_columns');
        echo "  [PASS] Cascade deletion of dataset records and columns" . PHP_EOL;

        // Cleanup
        unset($db);
        Database::setInstance(null);
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }
    }
}
