<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Core\Database;
use AnaBot\Core\Encryption;
use AnaBot\Core\MigrationRunner;
use AnaBot\Services\CredentialManager;

/**
 * Phase 13: Zero-Data-Loss Application Update & Migration Simulation Test Suite
 * 
 * Verifies that application updates, repeated schema migrations, and ongoing operations
 * NEVER cause data loss, schema corruption, or credential invalidation.
 */
class ZeroDataLossMigrationTest
{
    private Database $db;
    private Encryption $encryption;
    private CredentialManager $credentialManager;

    public function __construct()
    {
        $this->encryption = new Encryption();
    }

    public function run(): void
    {
        echo "Testing Zero-Data-Loss Migration & Update Simulation (Isolated Test DB)..." . PHP_EOL;

        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $this->db = new Database($testDbPath);
        Database::setInstance($this->db);

        // Step 1: Initial schema migration
        $runner = new MigrationRunner($this->db);
        $initialMigrations = $runner->run();
        assert($initialMigrations > 0, "Initial migrations must be executed.");

        $this->credentialManager = new CredentialManager($this->db, $this->encryption);

        // Step 2: Populate with realistic production records
        $now = date('c');
        $this->db->execute(
            "INSERT INTO users (id, username, email, password_hash, status, created_at, updated_at) 
             VALUES (1, 'business_owner', 'owner@company.local', 'argon2id_mock_hash', 'active', :now, :now)",
            [':now' => $now]
        );

        // Sync connector catalog
        (new \AnaBot\Services\ConnectorManager($this->db))->syncCatalogToDatabase();

        // Seed platform connections & encrypted credentials
        $this->db->execute(
            "INSERT INTO platform_connections (id, connector_id, user_id, name, status, created_at, updated_at)
             VALUES (1, 1, 1, 'Main E-Commerce Connection', 'connected', :now, :now)",
            [':now' => $now]
        );

        $sensitiveCreds = [
            'access_token'  => 'shpat_super_confidential_token_999',
            'shop_domain'   => 'mystore.myshopify.com',
            'refresh_token' => 'rt_keep_safe_across_updates',
        ];
        $this->credentialManager->store(1, $sensitiveCreds, 'Bearer');

        // Seed data sources, datasets, columns, and records
        $this->db->execute(
            "INSERT INTO data_sources (id, connection_id, external_source_id, source_name, is_active, created_at, updated_at)
             VALUES (1, 1, 'shopify_orders', 'Shopify Store Orders', 1, :now, :now)",
            [':now' => $now]
        );

        $this->db->execute(
            "INSERT INTO datasets (id, data_source_id, name, description, record_count, first_record_at, latest_record_at, created_at, updated_at)
             VALUES (1, 1, 'Monthly Transactions 2026', 'Data transaksi bulanan', 150, '2026-01-01', '2026-04-10', :now, :now)",
            [':now' => $now]
        );

        $this->db->execute(
            "INSERT INTO dataset_columns (dataset_id, column_name, data_type, is_primary_date, is_metric, is_dimension, column_order, created_at)
             VALUES (1, 'revenue', 'NUMERIC', 0, 1, 0, 1, :now)",
            [':now' => $now]
        );

        $payload = json_encode(['order_id' => 'ORD-9912', 'revenue' => 450000.0, 'status' => 'paid']);
        $recordHash = hash('sha256', (string) $payload);
        $this->db->execute(
            "INSERT INTO data_records (dataset_id, external_id, record_date, data_json, record_hash, created_at, updated_at)
             VALUES (1, 'ORD-9912', '2026-04-10', :json, :hash, :now, :now)",
            [':json' => $payload, ':hash' => $recordHash, ':now' => $now]
        );

        // Seed deterministic analysis results
        $analysisPayload = json_encode([
            'mean' => 450000.0,
            'iqr_fences' => ['lower' => 100000, 'upper' => 800000],
            'holt_winters_forecast' => [465000.0, 480000.0],
        ]);
        $this->db->execute(
            "INSERT INTO analysis_results (dataset_id, metric_name, analysis_type, calculated_value_json, calculated_at)
             VALUES (1, 'revenue', 'predictive_forecast', :res, :now)",
            [':res' => $analysisPayload, ':now' => $now]
        );

        echo "  ✓ Pre-migration data seeded successfully." . PHP_EOL;

        // Step 3: Run migration runner again (simulating an app update / re-deployment)
        $secondRunCount = $runner->run();
        assert($secondRunCount === 0, "Repeated migration run on fully up-to-date schema must apply 0 new migrations.");

        // Step 4: Verify Pragmas and SQLite database integrity
        $integrity = (string) $this->db->queryOne("PRAGMA integrity_check")['integrity_check'];
        assert($integrity === 'ok', "SQLite integrity check must return 'ok'.");

        $journalMode = (string) $this->db->queryOne("PRAGMA journal_mode")['journal_mode'];
        assert(strtoupper($journalMode) === 'WAL', "Database must remain in WAL mode.");

        $foreignKeys = (int) $this->db->queryOne("PRAGMA foreign_keys")['foreign_keys'];
        assert($foreignKeys === 1, "Foreign keys must remain enabled (1).");

        // Step 5: Verify 100% Zero Data Loss across all records
        $user = $this->db->queryOne("SELECT * FROM users WHERE id = 1");
        assert($user !== null && $user['username'] === 'business_owner', "User record must remain completely intact.");

        $decryptedCreds = $this->credentialManager->get(1);
        assert($decryptedCreds !== null, "Credentials must decrypt cleanly after migration.");
        assert($decryptedCreds['access_token'] === 'shpat_super_confidential_token_999', "Decrypted token must match exactly.");
        assert($decryptedCreds['shop_domain'] === 'mystore.myshopify.com');

        $dataset = $this->db->queryOne("SELECT * FROM datasets WHERE id = 1");
        assert($dataset !== null && (int) $dataset['record_count'] === 150, "Dataset must remain intact.");

        $record = $this->db->queryOne("SELECT * FROM data_records WHERE id = 1");
        assert($record !== null, "Data record must exist.");
        assert($record['record_hash'] === $recordHash, "Record SHA-256 hash must remain identical.");

        $result = $this->db->queryOne("SELECT * FROM analysis_results WHERE id = 1");
        assert($result !== null, "Analysis result must exist.");
        $decoded = json_decode((string) $result['calculated_value_json'], true);
        assert($decoded['mean'] === 450000.0, "Mathematical analytical fact must remain exact.");

        echo "  ✓ Zero data loss verified: 100% data, credentials, and analysis results intact." . PHP_EOL;
        echo "  [PASS] Zero-Data-Loss Migration test suite passed." . PHP_EOL;

        // Cleanup
        unset($this->db);
        Database::setInstance(null);
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }
    }
}
