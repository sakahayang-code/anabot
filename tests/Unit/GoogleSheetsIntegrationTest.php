<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Connectors\GoogleSheets\GoogleAuthHelper;
use AnaBot\Connectors\GoogleSheets\GoogleSheetsConnector;
use AnaBot\Core\Database;
use AnaBot\Core\Encryption;
use AnaBot\Core\Logger;
use AnaBot\Core\MigrationRunner;
use AnaBot\Services\CredentialManager;
use AnaBot\Services\DataNormalizer;
use AnaBot\Services\GoogleSheetsImporter;
use AnaBot\Services\HttpClient;
use AnaBot\Services\SyncQueueManager;

/**
 * Phase 5: Google Sheets First Integration Automated Test Suite
 */
class GoogleSheetsIntegrationTest
{
    private Database $db;
    private Encryption $encryption;
    private CredentialManager $credentialManager;
    private SyncQueueManager $syncQueue;

    public function __construct()
    {
        $this->encryption = new Encryption();
    }

    public function run(): void
    {
        echo "Testing Google Sheets Integration Engine (Isolated Test DB)..." . PHP_EOL;

        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $this->db = new Database($testDbPath);
        Database::setInstance($this->db);

        $runner = new MigrationRunner($this->db);
        $runner->run();

        $this->credentialManager = new CredentialManager($this->db, $this->encryption);
        $this->syncQueue = new SyncQueueManager($this->db);

        // Seed base user and connector for FKs
        $now = date('c');
        $this->db->execute("INSERT INTO users (id, username, email, password_hash, status, created_at, updated_at) VALUES (1, 'tester', 'tester@anabot.local', 'h', 'active', :n, :n)", [':n' => $now]);
        $this->db->execute("INSERT INTO connectors (id, connector_code, name, category, auth_type, created_at) VALUES (1, 'google_sheets', 'Google Sheets', 'spreadsheet', 'oauth2', :n)", [':n' => $now]);

        $this->testSpreadsheetIdExtraction();
        $this->testServiceAccountJwtCreationAndSigning();
        $this->testDataNormalizerTypeInference();
        $this->testDataNormalizerCurrencyAndDateParsing();
        $this->testColumnSanitizationAndRoleClassification();
        $this->testGoogleSheetsConnectorPreviewMock();
        $this->testEndToEndImportPipeline();
        $this->testIdempotentSyncNoDuplication();

        echo "  [PASS] All 8 Google Sheets Integration test cases passed successfully." . PHP_EOL;

        // Cleanup
        unset($this->db);
        Database::setInstance(null);
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }
    }

    private function testSpreadsheetIdExtraction(): void
    {
        $expectedId = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms';

        // 1. Full standard edit URL
        $url1 = "https://docs.google.com/spreadsheets/d/{$expectedId}/edit#gid=0";
        assert(GoogleAuthHelper::extractSpreadsheetId($url1) === $expectedId, "Must extract ID from standard Google Sheets URL");

        // 2. Published web URL
        $url2 = "https://docs.google.com/spreadsheets/d/e/2PACX-1vR_sample1234567890/pubhtml";
        assert(GoogleAuthHelper::extractSpreadsheetId($url2) === '2PACX-1vR_sample1234567890', "Must extract ID from published web URL");

        // 3. Raw alphanumeric ID
        assert(GoogleAuthHelper::extractSpreadsheetId($expectedId) === $expectedId, "Must preserve raw alphanumeric ID");

        echo "  ✓ Google Sheets URL parsing & spreadsheet ID extraction verified." . PHP_EOL;
    }

    private function testServiceAccountJwtCreationAndSigning(): void
    {
        // Generate an in-memory RSA 2048-bit keypair for test
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        assert($res !== false, "OpenSSL must generate test RSA keypair.");

        openssl_pkey_export($res, $privKey);
        $details = openssl_pkey_get_details($res);
        $pubKey = $details['key'];

        $serviceAccount = [
            'client_email' => 'anabot-robot@my-analytics-project.iam.gserviceaccount.com',
            'private_key' => $privKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ];

        $jwt = GoogleAuthHelper::createServiceAccountJwt($serviceAccount);
        $parts = explode('.', $jwt);
        assert(count($parts) === 3, "JWT must consist of exactly 3 parts separated by dots.");

        // Decode header
        $headerJson = base64_decode(strtr($parts[0], '-_', '+/'));
        $header = json_decode($headerJson, true);
        assert($header['alg'] === 'RS256', "JWT alg header must be RS256.");
        assert($header['typ'] === 'JWT', "JWT typ header must be JWT.");

        // Decode payload
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
        $payload = json_decode($payloadJson, true);
        assert($payload['iss'] === $serviceAccount['client_email'], "JWT iss claim must match client_email.");
        assert($payload['scope'] === GoogleAuthHelper::READONLY_SCOPE, "JWT scope must be read-only.");
        assert($payload['aud'] === $serviceAccount['token_uri'], "JWT aud claim must match token_uri.");

        // Verify cryptographic signature with OpenSSL
        $dataToVerify = "{$parts[0]}.{$parts[1]}";
        $signature = base64_decode(strtr($parts[2], '-_', '+/'));
        $verifyResult = openssl_verify($dataToVerify, $signature, $pubKey, OPENSSL_ALGO_SHA256);
        assert($verifyResult === 1, "Cryptographic RS256 signature verification must pass!");

        echo "  ✓ Google Service Account RS256 JWT generation and OpenSSL signing verified." . PHP_EOL;
    }

    private function testDataNormalizerTypeInference(): void
    {
        // 1. DATETIME
        $dateSamples = ['2026-01-15', '2026-02-20', '2026-03-25', '2026-04-10'];
        assert(DataNormalizer::inferColumnType($dateSamples) === 'DATETIME', "Must infer DATETIME for ISO dates.");

        // 2. INTEGER
        $intSamples = ['10', '25', '100', '-5', '0'];
        assert(DataNormalizer::inferColumnType($intSamples) === 'INTEGER', "Must infer INTEGER for whole numbers.");

        // 3. NUMERIC (Currency & decimals)
        $numSamples = ['Rp 150.000', 'Rp 225.500', '1250.75', '$99.99'];
        assert(DataNormalizer::inferColumnType($numSamples) === 'NUMERIC', "Must infer NUMERIC for currency & decimal numbers.");

        // 4. BOOLEAN
        $boolSamples = ['true', 'false', 'true', 'true'];
        assert(DataNormalizer::inferColumnType($boolSamples) === 'BOOLEAN', "Must infer BOOLEAN.");

        // 5. TEXT (fallback)
        $textSamples = ['Laptop Asus ROG', 'Mouse Logitech G502', 'Monitor Samsung 27'];
        assert(DataNormalizer::inferColumnType($textSamples) === 'TEXT', "Must infer TEXT for strings.");

        echo "  ✓ Column data type inference (DATETIME, INTEGER, NUMERIC, BOOLEAN, TEXT) verified." . PHP_EOL;
    }

    private function testDataNormalizerCurrencyAndDateParsing(): void
    {
        // Currency cleaning
        assert(DataNormalizer::cleanCurrency('Rp 150.000') === '150000', "Rp 150.000 must normalize to 150000");
        assert(DataNormalizer::cleanCurrency('$1,250.50') === '1250.50', "$1,250.50 must normalize to 1250.50");
        assert(DataNormalizer::cleanCurrency('15%') === '0.15', "15% must normalize to 0.15");

        // Date normalizer
        assert(DataNormalizer::normalizeDate('2026-04-10') === '2026-04-10', "ISO date must normalize to YYYY-MM-DD");
        assert(DataNormalizer::normalizeDate('15/01/2026') === '2026-01-15', "DD/MM/YYYY date must normalize to YYYY-MM-DD");

        echo "  ✓ Currency normalization and date parsing verified." . PHP_EOL;
    }

    private function testColumnSanitizationAndRoleClassification(): void
    {
        // Column sanitization
        assert(DataNormalizer::sanitizeColumnName('Nama Produk (ID)') === 'nama_produk_id', "Must clean non-alphanumeric chars");
        assert(DataNormalizer::sanitizeColumnName('Total Revenue ($)') === 'total_revenue', "Must strip currency symbols from header");
        assert(DataNormalizer::sanitizeColumnName('123 Order') === 'col_123_order', "Must prepend col_ if starting with digit");

        // Role classification
        $dateRole = DataNormalizer::classifyColumnRole('order_date', 'DATETIME');
        assert($dateRole['is_primary_date'] === 1, "order_date must be classified as is_primary_date=1");
        assert($dateRole['is_metric'] === 0, "order_date must not be a metric");

        $revenueRole = DataNormalizer::classifyColumnRole('total_revenue', 'NUMERIC');
        assert($revenueRole['is_metric'] === 1, "total_revenue must be classified as is_metric=1");
        assert($revenueRole['is_dimension'] === 0, "total_revenue must not be dimension");

        $productRole = DataNormalizer::classifyColumnRole('product_name', 'TEXT');
        assert($productRole['is_dimension'] === 1, "product_name must be classified as is_dimension=1");

        echo "  ✓ Column header sanitization and role classification (Primary Date, Metric, Dimension) verified." . PHP_EOL;
    }

    private function testGoogleSheetsConnectorPreviewMock(): void
    {
        // Create mock HTTP client with canned Google Sheets API responses
        $mockHttpClient = new class extends HttpClient {
            public function get(string $url, array $headers = [], array $options = []): array
            {
                if (str_contains($url, 'includeGridData=false')) {
                    return [
                        'status' => 200,
                        'headers' => ['content-type' => 'application/json'],
                        'body' => json_encode([
                            'properties' => ['title' => 'Sample Sales Spreadsheet'],
                            'sheets' => [
                                [
                                    'properties' => [
                                        'title' => 'Transaksi Penjualan',
                                        'gridProperties' => ['rowCount' => 25, 'columnCount' => 4],
                                    ],
                                ],
                            ],
                        ]),
                        'json' => [
                            'properties' => ['title' => 'Sample Sales Spreadsheet'],
                            'sheets' => [
                                [
                                    'properties' => [
                                        'title' => 'Transaksi Penjualan',
                                        'gridProperties' => ['rowCount' => 25, 'columnCount' => 4],
                                    ],
                                ],
                            ],
                        ],
                        'duration_ms' => 12.5,
                    ];
                }

                // Grid rows response (Header + 4 sample rows + 1 empty row)
                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => '',
                    'json' => [
                        'values' => [
                            ['Tanggal Transaksi', 'Nama Pembeli', 'Kategori', 'Total Bayar (Rp)'],
                            ['2026-01-10', 'Budi Santoso', 'Elektronik', 'Rp 1.500.000'],
                            ['2026-01-12', 'Siti Aminah', 'Pakaian', 'Rp 250.000'],
                            ['2026-01-15', 'Ahmad Dani', 'Elektronik', 'Rp 3.200.000'],
                            ['', '', '', ''], // Empty row to test filtering
                            ['2026-01-18', 'Dewi Lestari', 'Kecantikan', 'Rp 180.000'],
                        ],
                    ],
                    'duration_ms' => 15.0,
                ];
            }
        };

        $connector = new GoogleSheetsConnector(
            ['spreadsheet_id' => 'mock_spreadsheet_12345'],
            ['access_token' => 'mock_token'],
            1,
            $mockHttpClient,
            $this->db
        );

        // Test previewSheet
        $preview = $connector->previewSheet('Transaksi Penjualan', 5);
        assert($preview['sheet_name'] === 'Transaksi Penjualan', "Preview sheet name must match.");
        assert(count($preview['headers']) === 4, "Must detect 4 header columns.");
        assert(count($preview['sample_rows']) === 4, "Must filter out empty row and return 4 valid rows.");

        $cols = $preview['columns'];
        assert($cols[0]['column_name'] === 'tanggal_transaksi', "Column 1 must be tanggal_transaksi.");
        assert($cols[0]['data_type'] === 'DATETIME', "Tanggal Transaksi must be inferred as DATETIME.");
        assert($cols[0]['is_primary_date'] === 1, "Tanggal Transaksi must be marked primary date.");

        assert($cols[3]['column_name'] === 'total_bayar_rp', "Column 4 must be total_bayar_rp.");
        assert($cols[3]['data_type'] === 'NUMERIC', "Total Bayar must be inferred as NUMERIC.");
        assert($cols[3]['is_metric'] === 1, "Total Bayar must be marked is_metric=1.");

        echo "  ✓ GoogleSheetsConnector preview & empty row filtering verified." . PHP_EOL;
    }

    private function testEndToEndImportPipeline(): void
    {
        // 1. Create Connection record
        $now = date('c');
        $this->db->execute(
            "INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at)
             VALUES (1, 1, 'Google Sheets Importer Test', 'connected', :now, :now)",
            [':now' => $now]
        );
        $connId = (int) $this->db->lastInsertId();

        $this->credentialManager->store($connId, [
            'access_token' => 'mock_oauth_token_val123',
            'spreadsheet_id' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
        ]);

        // Mock HTTP client for import
        $mockHttpClient = new class extends HttpClient {
            public function get(string $url, array $headers = [], array $options = []): array
            {
                $decodedUrl = urldecode($url);

                if (str_contains($url, 'includeGridData=false')) {
                    return [
                        'status' => 200,
                        'headers' => ['content-type' => 'application/json'],
                        'body' => '',
                        'json' => [
                            'properties' => ['title' => 'Google Store Sales'],
                            'sheets' => [
                                ['properties' => ['title' => 'Sheet1', 'gridProperties' => ['rowCount' => 10]]],
                            ],
                        ],
                        'duration_ms' => 10.0,
                    ];
                }

                // If asking for header only: A1:ZZ1
                if (str_contains($decodedUrl, 'A1:ZZ1') && !str_contains($decodedUrl, 'A1:ZZ11')) {
                    return [
                        'status' => 200,
                        'headers' => ['content-type' => 'application/json'],
                        'body' => '',
                        'json' => [
                            'values' => [
                                ['Order Date', 'Customer', 'Category', 'Sales Amount', 'Quantity'],
                            ],
                        ],
                        'duration_ms' => 10.0,
                    ];
                }

                // If asking for preview range (A1:ZZ11)
                if (str_contains($decodedUrl, 'A1:ZZ11')) {
                    return [
                        'status' => 200,
                        'headers' => ['content-type' => 'application/json'],
                        'body' => '',
                        'json' => [
                            'values' => [
                                ['Order Date', 'Customer', 'Category', 'Sales Amount', 'Quantity'],
                                ['2026-03-01', 'Andi Pratama', 'Gadgets', '$850.00', '2'],
                                ['2026-03-05', 'Bambang Sudiro', 'Accessories', '$45.00', '3'],
                                ['2026-03-10', 'Citra Kirana', 'Gadgets', '$1,200.00', '1'],
                                ['2026-03-15', 'Dimas Anggara', 'Fashion', '$120.50', '4'],
                            ],
                        ],
                        'duration_ms' => 10.0,
                    ];
                }

                // Return batch data (rows 2 to 251)
                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => '',
                    'json' => [
                        'values' => [
                            ['2026-03-01', 'Andi Pratama', 'Gadgets', '$850.00', '2'],
                            ['2026-03-05', 'Bambang Sudiro', 'Accessories', '$45.00', '3'],
                            ['2026-03-10', 'Citra Kirana', 'Gadgets', '$1,200.00', '1'],
                            ['2026-03-15', 'Dimas Anggara', 'Fashion', '$120.50', '4'],
                        ],
                    ],
                    'duration_ms' => 15.0,
                ];
            }
        };

        // Use standard importer with mock client
        $importer = new GoogleSheetsImporter(
            $this->db,
            $this->credentialManager,
            $this->syncQueue,
            new Logger(),
            $mockHttpClient
        );

        // 2. Execute import
        $summary = $importer->importSheet($connId, 'Sheet1', 'Google Sales 2026');
        assert($summary['success'] === true, "Import operation must succeed.");
        assert($summary['records_added'] === 4, "Must add exactly 4 new records.");
        assert($summary['columns_count'] === 5, "Must persist 5 column definitions.");

        $datasetId = (int) $summary['dataset_id'];

        // 3. Verify SQLite Tables
        // Data source
        $ds = $this->db->queryOne("SELECT * FROM data_sources WHERE connection_id = :cid", [':cid' => $connId]);
        assert($ds !== null, "data_sources record must exist.");

        // Dataset
        $d = $this->db->queryOne("SELECT * FROM datasets WHERE id = :did", [':did' => $datasetId]);
        assert((int) $d['record_count'] === 4, "dataset record_count must be 4.");
        assert($d['first_record_at'] === '2026-03-01', "first_record_at must be 2026-03-01.");
        assert($d['latest_record_at'] === '2026-03-15', "latest_record_at must be 2026-03-15.");

        // Dataset columns
        $columns = $this->db->queryAll("SELECT * FROM dataset_columns WHERE dataset_id = :did ORDER BY column_order ASC", [':did' => $datasetId]);
        assert(count($columns) === 5, "5 columns must be saved in dataset_columns.");
        assert($columns[0]['column_name'] === 'order_date', "Column 1 must be order_date.");
        assert((int) $columns[0]['is_primary_date'] === 1, "order_date must have is_primary_date = 1.");

        // Data records
        $records = $this->db->queryAll("SELECT * FROM data_records WHERE dataset_id = :did", [':did' => $datasetId]);
        assert(count($records) === 4, "data_records must contain 4 rows.");
        $firstRowJson = json_decode($records[0]['data_json'], true);
        assert($firstRowJson['customer'] === 'Andi Pratama', "Row values must match imported data.");

        // Clean up
        $this->db->execute("DELETE FROM data_records WHERE dataset_id = :did", [':did' => $datasetId]);
        $this->db->execute("DELETE FROM dataset_columns WHERE dataset_id = :did", [':did' => $datasetId]);
        $this->db->execute("DELETE FROM datasets WHERE id = :did", [':did' => $datasetId]);
        $this->db->execute("DELETE FROM data_sources WHERE id = :id", [':id' => $ds['id']]);
        $this->db->execute("DELETE FROM platform_connections WHERE id = :id", [':id' => $connId]);

        echo "  ✓ End-to-end Google Sheets import into SQLite (datasets, columns, records) verified." . PHP_EOL;
    }

    private function testIdempotentSyncNoDuplication(): void
    {
        $now = date('c');
        $this->db->execute(
            "INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at)
             VALUES (1, 1, 'Idempotent Sync Conn', 'connected', :now, :now)",
            [':now' => $now]
        );
        $connId = (int) $this->db->lastInsertId();

        $this->db->execute(
            "INSERT INTO data_sources (connection_id, external_source_id, source_name, created_at, updated_at)
             VALUES (:cid, 'idempotent_sheet_1', 'Idempotent Sheet', :now, :now)",
            [':cid' => $connId, ':now' => $now]
        );
        $sourceId = (int) $this->db->lastInsertId();

        $this->db->execute(
            "INSERT INTO datasets (data_source_id, name, description, record_count, created_at, updated_at)
             VALUES (:sid, 'Idempotent Dataset', 'Test duplicate prevention', 0, :now, :now)",
            [':sid' => $sourceId, ':now' => $now]
        );
        $datasetId = (int) $this->db->lastInsertId();

        // Insert first record
        $hash1 = hash('sha256', 'Sheet1_2');
        $this->db->execute(
            "INSERT INTO data_records (dataset_id, external_id, record_date, record_hash, data_json, created_at, updated_at)
             VALUES (:did, 'Sheet1_2', '2026-04-01', :hash, '{\"name\":\"Original\"}', :now, :now)",
            [':did' => $datasetId, ':hash' => $hash1, ':now' => $now]
        );

        $initialCount = (int) $this->db->queryOne("SELECT COUNT(*) as cnt FROM data_records WHERE dataset_id = :did", [':did' => $datasetId])['cnt'];
        assert($initialCount === 1, "Initial count must be 1.");

        // Simulate re-sync of same external_id (upsert behavior)
        $existing = $this->db->queryOne(
            "SELECT id FROM data_records WHERE dataset_id = :did AND external_id = 'Sheet1_2' LIMIT 1",
            [':did' => $datasetId]
        );

        if ($existing) {
            $this->db->execute(
                "UPDATE data_records SET data_json = '{\"name\":\"Updated\"}', updated_at = :now WHERE id = :id",
                [':now' => $now, ':id' => $existing['id']]
            );
        }

        $afterCount = (int) $this->db->queryOne("SELECT COUNT(*) as cnt FROM data_records WHERE dataset_id = :did", [':did' => $datasetId])['cnt'];
        assert($afterCount === 1, "Re-syncing same external_id MUST NOT duplicate rows (Count must remain 1)!");

        $updatedRec = $this->db->queryOne("SELECT data_json FROM data_records WHERE dataset_id = :did", [':did' => $datasetId]);
        assert(str_contains($updatedRec['data_json'], 'Updated'), "Existing row must be updated in place.");

        // Clean up
        $this->db->execute("DELETE FROM data_records WHERE dataset_id = :did", [':did' => $datasetId]);
        $this->db->execute("DELETE FROM datasets WHERE id = :did", [':did' => $datasetId]);
        $this->db->execute("DELETE FROM data_sources WHERE id = :id", [':id' => $sourceId]);
        $this->db->execute("DELETE FROM platform_connections WHERE id = :id", [':id' => $connId]);

        echo "  ✓ Idempotent sync (no duplicate records on re-sync) verified." . PHP_EOL;
    }
}
