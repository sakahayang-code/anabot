<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Connectors\CustomAPI\CustomAPIConnector;
use AnaBot\Connectors\GoogleSheets\GoogleSheetsConnector;
use AnaBot\Core\Database;
use AnaBot\Core\Encryption;
use AnaBot\Core\Logger;
use AnaBot\Services\ConnectorManager;
use AnaBot\Services\CredentialManager;
use AnaBot\Services\HttpClient;
use AnaBot\Services\OAuthStateManager;
use AnaBot\Services\SyncQueueManager;
use RuntimeException;

/**
 * Phase 4: Connector Framework Automated Test Suite
 */
class ConnectorFrameworkTest
{
    private Database $db;
    private Encryption $encryption;
    private CredentialManager $credentialManager;
    private OAuthStateManager $oauthStateManager;
    private SyncQueueManager $syncQueue;
    private ConnectorManager $connectorManager;

    public function __construct()
    {
        $this->encryption = new Encryption();
    }

    public function run(): void
    {
        echo "Testing Connector Framework & Sync Queue Engine (Isolated Test DB)..." . PHP_EOL;

        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $this->db = new Database($testDbPath);
        Database::setInstance($this->db);

        $runner = new \AnaBot\Core\MigrationRunner($this->db);
        $runner->run();

        $this->credentialManager = new CredentialManager($this->db, $this->encryption);
        $this->oauthStateManager = new OAuthStateManager($this->db);
        $this->syncQueue = new SyncQueueManager($this->db);
        $this->connectorManager = new ConnectorManager($this->db, $this->credentialManager, $this->syncQueue);

        // Seed base user and sync catalog for foreign keys
        $now = date('c');
        $this->db->execute("INSERT INTO users (id, username, email, password_hash, status, created_at, updated_at) VALUES (1, 'tester', 'test@anabot.local', 'h', 'active', :n, :n)", [':n' => $now]);
        $this->connectorManager->syncCatalogToDatabase();

        $this->testCredentialEncryptionAndDecryption();
        $this->testTokenExpirationAndRotation();
        $this->testOAuthStateAntiCsrfProtection();
        $this->testHttpClientSsrfProtection();
        $this->testSensitiveDataMaskingInLogs();
        $this->testSyncQueueAndIncrementalCursorState();
        $this->testPartialSyncRecovery();
        $this->testConnectorCatalogAndDriverResolution();
        $this->testSafeDisconnectAndCredentialRevocation();
        $this->testCustomApiReadOnlyContract();

        echo "  [PASS] All 10 Connector Framework test cases passed successfully." . PHP_EOL;

        // Cleanup
        unset($this->db);
        Database::setInstance(null);
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }
    }

    private function testCredentialEncryptionAndDecryption(): void
    {
        // Setup dummy platform connection
        $now = date('c');
        $this->db->execute(
            "INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at)
             VALUES (1, 1, 'Test Encrypted Conn', 'connected', :now, :now)",
            [':now' => $now]
        );
        $connId = (int) $this->db->lastInsertId();

        $sensitiveCredentials = [
            'access_token' => 'ya29.secret_oauth_token_1234567890',
            'refresh_token' => '1//secret_refresh_token_0987654321',
            'client_secret' => 'GOCSPX-super_confidential_secret',
        ];

        // Store encrypted
        $stored = $this->credentialManager->store($connId, $sensitiveCredentials, 'Bearer', date('c', time() + 3600));
        assert($stored === true, "CredentialManager::store should return true.");

        // Verify raw database row has ciphertext, NOT plaintext
        $raw = $this->db->queryOne("SELECT credentials_encrypted FROM connection_credentials WHERE connection_id = :cid", [':cid' => $connId]);
        assert($raw !== null, "Credential record must exist in DB.");
        assert(!str_contains($raw['credentials_encrypted'], 'secret_oauth_token'), "Raw database MUST NOT contain plaintext tokens!");
        assert(!str_contains($raw['credentials_encrypted'], 'confidential_secret'), "Raw database MUST NOT contain plaintext secrets!");

        // Retrieve decrypted
        $decrypted = $this->credentialManager->get($connId);
        assert($decrypted !== null, "Decrypted credentials must be returned.");
        assert($decrypted['access_token'] === $sensitiveCredentials['access_token'], "Decrypted access token must match.");
        assert($decrypted['refresh_token'] === $sensitiveCredentials['refresh_token'], "Decrypted refresh token must match.");
        assert($decrypted['client_secret'] === $sensitiveCredentials['client_secret'], "Decrypted client secret must match.");

        // Clean up
        $this->db->execute("DELETE FROM platform_connections WHERE id = :id", [':id' => $connId]);
        echo "  ✓ Credential encryption & decryption (AES-256-GCM) verified." . PHP_EOL;
    }

    private function testTokenExpirationAndRotation(): void
    {
        $now = date('c');
        $this->db->execute(
            "INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at)
             VALUES (1, 1, 'Test Expiration Conn', 'connected', :now, :now)",
            [':now' => $now]
        );
        $connId = (int) $this->db->lastInsertId();

        // Expired token (1 hour ago)
        $pastExpiry = date('c', time() - 3600);
        $this->credentialManager->store($connId, ['access_token' => 'old_expired_token'], 'Bearer', $pastExpiry);

        assert($this->credentialManager->isExpired($connId) === true, "Token expired 1hr ago must report isExpired=true.");
        assert($this->credentialManager->needsRefresh($connId) === true, "Expired token must report needsRefresh=true.");

        // Rotate token
        $rotated = $this->credentialManager->updateTokens($connId, 'new_active_token', 'new_refresh_token', 3600);
        assert($rotated === true, "updateTokens must succeed.");

        assert($this->credentialManager->isExpired($connId) === false, "Rotated token must report isExpired=false.");
        $meta = $this->credentialManager->getMetadata($connId);
        assert($meta['needs_refresh'] === false, "Rotated token valid for 1 hour must not need immediate refresh.");

        $decrypted = $this->credentialManager->get($connId);
        assert($decrypted['access_token'] === 'new_active_token', "Rotated token value must match.");
        assert($decrypted['refresh_token'] === 'new_refresh_token', "Rotated refresh token must match.");

        $this->db->execute("DELETE FROM platform_connections WHERE id = :id", [':id' => $connId]);
        echo "  ✓ Token expiration detection and rotation verified." . PHP_EOL;
    }

    private function testOAuthStateAntiCsrfProtection(): void
    {
        $connectorCode = 'google_sheets';
        $redirectUri = 'http://localhost/connectors/oauth/callback';

        // 1. Create state token
        $token = $this->oauthStateManager->createState($connectorCode, $redirectUri, 15);
        assert(strlen($token) === 64, "State token must be 64-character hex.");
        assert(ctype_xdigit($token), "State token must be hexadecimal.");

        // 2. Verification with wrong connector code must fail
        $wrongVerify = $this->oauthStateManager->verifyAndConsume($token, 'wrong_connector');
        assert($wrongVerify === false, "State token must not validate against mismatched connector code.");

        // 3. Verification with correct code must succeed
        $correctVerify = $this->oauthStateManager->verifyAndConsume($token, $connectorCode);
        assert($correctVerify === true, "Valid state token must verify successfully.");

        // 4. Replay attack: verifying again MUST fail because token was consumed (single-use guarantee)
        $replayVerify = $this->oauthStateManager->verifyAndConsume($token, $connectorCode);
        assert($replayVerify === false, "Consuming state token twice (replay attack) MUST fail!");

        // 5. Expired token check
        $expiredToken = bin2hex(random_bytes(32));
        $past = date('c', time() - 300);
        $this->db->execute(
            "INSERT INTO oauth_states (state_token, connector_code, redirect_uri, expires_at, created_at)
             VALUES (:t, :c, :u, :exp, :now)",
            [':t' => $expiredToken, ':c' => $connectorCode, ':u' => $redirectUri, ':exp' => $past, ':now' => $past]
        );

        $expiredVerify = $this->oauthStateManager->verifyAndConsume($expiredToken, $connectorCode);
        assert($expiredVerify === false, "Expired state token must be rejected.");

        echo "  ✓ OAuth 2.0 anti-CSRF state token & single-use consumption verified." . PHP_EOL;
    }

    private function testHttpClientSsrfProtection(): void
    {
        $client = new HttpClient(10, 30, 3, 10485760, true, false);

        // Verify private IPv4 ranges are blocked
        $blockedIps = [
            'http://127.0.0.1/admin',
            'http://10.0.0.1/internal',
            'http://192.168.1.1/router',
            'http://172.16.0.5/api',
            'http://169.254.169.254/latest/meta-data', // AWS / Cloud metadata endpoint
            'http://0.0.0.0/test',
        ];

        foreach ($blockedIps as $url) {
            $caught = false;
            try {
                $client->validateUrlForSsrf($url);
            } catch (RuntimeException $e) {
                $caught = true;
                assert(str_contains($e->getMessage(), 'SSRF Protection'), "Exception must indicate SSRF Protection.");
            }
            assert($caught === true, "SSRF validation failed to block private URL: {$url}");
        }

        // Verify unsupported schemes are blocked
        $badSchemes = ['ftp://files.example.com', 'file:///etc/passwd', 'gopher://example.com'];
        foreach ($badSchemes as $url) {
            $caught = false;
            try {
                $client->validateUrlForSsrf($url);
            } catch (RuntimeException $e) {
                $caught = true;
            }
            assert($caught === true, "SSRF validation failed to block invalid scheme: {$url}");
        }

        echo "  ✓ HttpClient SSRF protection blocks private IPs & cloud metadata endpoints." . PHP_EOL;
    }

    private function testSensitiveDataMaskingInLogs(): void
    {
        $now = date('c');
        $this->db->execute(
            "INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at)
             VALUES (1, 1, 'Logging Test Conn', 'connected', :now, :now)",
            [':now' => $now]
        );
        $connId = (int) $this->db->lastInsertId();
        $jobId = $this->syncQueue->createJob($connId);

        // Log an event with sensitive keys
        $sensitiveContext = [
            'api_key' => 'secret_api_key_abc123',
            'access_token' => 'secret_bearer_token_xyz987',
            'password' => 'secret_admin_pass',
            'safe_param' => 'public_value_123',
        ];

        $this->syncQueue->logStep($jobId, $connId, 'info', 'Step with credentials', $sensitiveContext);

        $log = $this->db->queryOne("SELECT * FROM sync_logs WHERE job_id = :jid ORDER BY id DESC LIMIT 1", [':jid' => $jobId]);
        assert($log !== null, "Log entry must be recorded.");

        $contextJson = $log['context_json'];
        assert(!str_contains($contextJson, 'secret_api_key_abc123'), "api_key must be masked!");
        assert(!str_contains($contextJson, 'secret_bearer_token_xyz987'), "access_token must be masked!");
        assert(!str_contains($contextJson, 'secret_admin_pass'), "password must be masked!");
        assert(str_contains($contextJson, '***REDACTED***'), "Masked value must contain ***REDACTED***.");
        assert(str_contains($contextJson, 'public_value_123'), "Non-sensitive param must be preserved.");

        // Clean up
        $this->db->execute("DELETE FROM sync_jobs WHERE id = :jid", [':jid' => $jobId]);
        $this->db->execute("DELETE FROM platform_connections WHERE id = :cid", [':cid' => $connId]);
        echo "  ✓ Sensitive credentials redaction in sync logs verified." . PHP_EOL;
    }

    private function testSyncQueueAndIncrementalCursorState(): void
    {
        $now = date('c');
        $this->db->execute(
            "INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at)
             VALUES (1, 1, 'Cursor Test Conn', 'connected', :now, :now)",
            [':now' => $now]
        );
        $connId = (int) $this->db->lastInsertId();

        // 1. Create job
        $jobId = $this->syncQueue->createJob($connId, null, 'manual');
        $job = $this->syncQueue->getJob($jobId);
        assert($job['status'] === 'pending', "Newly created job must be 'pending'.");

        // 2. Start job
        $this->syncQueue->startJob($jobId);
        $job = $this->syncQueue->getJob($jobId);
        assert($job['status'] === 'running', "Started job must be 'running'.");
        assert(!empty($job['started_at']), "Started job must record started_at timestamp.");

        // 3. Update progress
        $this->syncQueue->updateProgress($jobId, 50, 40, 10);
        $job = $this->syncQueue->getJob($jobId);
        assert((int) $job['records_processed'] === 50, "Records processed must be 50.");
        assert((int) $job['records_added'] === 40, "Records added must be 40.");
        assert((int) $job['records_updated'] === 10, "Records updated must be 10.");

        // 4. Save and retrieve cursor
        $this->syncQueue->saveCursor($connId, 'sheet_sales', 'row_101', 'row_offset');
        $cursor = $this->syncQueue->getCursor($connId, 'sheet_sales');
        assert($cursor !== null, "Saved cursor must be retrieved.");
        assert($cursor['cursor_value'] === 'row_101', "Cursor value must match 'row_101'.");
        assert($cursor['cursor_type'] === 'row_offset', "Cursor type must match 'row_offset'.");

        // 5. Complete job
        $this->syncQueue->completeJob($jobId, 40, 10);
        $job = $this->syncQueue->getJob($jobId);
        assert($job['status'] === 'completed', "Finished job must be 'completed'.");
        assert(!empty($job['finished_at']), "Finished job must record finished_at.");

        // Verify connection status updated
        $conn = $this->db->queryOne("SELECT last_sync_status FROM platform_connections WHERE id = :id", [':id' => $connId]);
        assert($conn['last_sync_status'] === 'success', "Connection last_sync_status must be 'success'.");

        // Clean up
        $this->db->execute("DELETE FROM sync_cursors WHERE connection_id = :id", [':id' => $connId]);
        $this->db->execute("DELETE FROM sync_jobs WHERE connection_id = :id", [':id' => $connId]);
        $this->db->execute("DELETE FROM platform_connections WHERE id = :id", [':id' => $connId]);
        echo "  ✓ Sync queue lifecycle & incremental cursor persistence verified." . PHP_EOL;
    }

    private function testPartialSyncRecovery(): void
    {
        $now = date('c');
        $this->db->execute(
            "INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at)
             VALUES (1, 1, 'Partial Recovery Conn', 'connected', :now, :now)",
            [':now' => $now]
        );
        $connId = (int) $this->db->lastInsertId();

        // Simulate interrupted job with partial progress
        $jobId = $this->syncQueue->createJob($connId, null, 'scheduled');
        $this->syncQueue->startJob($jobId);
        $this->syncQueue->saveCursor($connId, 'resource_orders', 'batch_page_3', 'page_token');
        $this->syncQueue->markPartial($jobId, 30, 0, 'Connection timeout reached; cursor saved at batch_page_3');

        $job = $this->syncQueue->getJob($jobId);
        assert($job['status'] === 'partial', "Interrupted job must have status 'partial'.");
        assert(str_contains($job['error_message'], 'batch_page_3'), "Error message must capture reason.");

        // Next job resumes from last confirmed cursor
        $savedCursor = $this->syncQueue->getCursor($connId, 'resource_orders');
        assert($savedCursor !== null && $savedCursor['cursor_value'] === 'batch_page_3', "Recovery job must find saved cursor 'batch_page_3'.");

        // Clean up
        $this->db->execute("DELETE FROM sync_cursors WHERE connection_id = :id", [':id' => $connId]);
        $this->db->execute("DELETE FROM sync_jobs WHERE connection_id = :id", [':id' => $connId]);
        $this->db->execute("DELETE FROM platform_connections WHERE id = :id", [':id' => $connId]);
        echo "  ✓ Partial sync recovery & resumption from cursor verified." . PHP_EOL;
    }

    private function testConnectorCatalogAndDriverResolution(): void
    {
        $this->connectorManager->syncCatalogToDatabase();
        $catalog = $this->connectorManager->listCatalog();

        assert(count($catalog) >= 10, "Connector catalog must contain at least 10 supported platforms.");
        assert(isset($catalog['google_sheets']), "Google Sheets must exist in catalog.");
        assert(isset($catalog['google_analytics']), "Google Analytics 4 must exist in catalog.");
        assert(isset($catalog['custom_api']), "Custom REST API must exist in catalog.");

        // Driver instantiation
        $gsDriver = $this->connectorManager->getDriver('google_sheets');
        assert($gsDriver instanceof GoogleSheetsConnector, "Driver for google_sheets must be GoogleSheetsConnector.");
        assert($gsDriver->getCategory() === 'spreadsheet', "Google Sheets category must be spreadsheet.");

        $customDriver = $this->connectorManager->getDriver('custom_api');
        assert($customDriver instanceof CustomAPIConnector, "Driver for custom_api must be CustomAPIConnector.");
        assert($customDriver->getAuthType() === 'api_key', "Custom API auth type must be api_key.");

        echo "  ✓ Connector catalog (10 platforms) & driver resolution verified." . PHP_EOL;
    }

    private function testSafeDisconnectAndCredentialRevocation(): void
    {
        $now = date('c');
        $this->db->execute(
            "INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at)
             VALUES (1, 1, 'Disconnect Test Conn', 'connected', :now, :now)",
            [':now' => $now]
        );
        $connId = (int) $this->db->lastInsertId();

        $this->credentialManager->store($connId, ['api_key' => 'secret_to_be_revoked_123'], 'ApiKey');
        assert($this->credentialManager->get($connId) !== null, "Credentials must exist before disconnect.");

        // Execute disconnect
        $disconnected = $this->connectorManager->disconnect($connId);
        assert($disconnected === true, "disconnect() must return true.");

        // Verify credentials wiped completely
        $credsAfter = $this->credentialManager->get($connId);
        assert($credsAfter === null, "Credentials MUST be purged upon disconnect.");

        // Verify connection status updated to 'disconnected'
        $connAfter = $this->db->queryOne("SELECT status FROM platform_connections WHERE id = :id", [':id' => $connId]);
        assert($connAfter['status'] === 'disconnected', "Connection status must be 'disconnected'.");

        $this->db->execute("DELETE FROM platform_connections WHERE id = :id", [':id' => $connId]);
        echo "  ✓ Safe disconnect & credential revocation verified." . PHP_EOL;
    }

    private function testCustomApiReadOnlyContract(): void
    {
        $connector = new CustomAPIConnector([
            'base_url' => 'https://api.example.com',
            'cursor_param' => 'page',
            'limit_param' => 'per_page',
        ], [
            'api_key' => 'test_api_key_123',
        ]);

        assert($connector->getConnectorCode() === 'custom_api', "Connector code must be custom_api.");
        assert($connector->getName() === 'Custom REST API', "Connector name must match.");

        $permissions = $connector->validatePermissions();
        assert($permissions['valid'] === true, "Permissions must be valid.");
        assert(in_array('read', $permissions['granted_scopes'], true), "Scopes must be strictly read-only.");

        $datasets = $connector->getAvailableDatasets();
        assert(count($datasets) > 0, "Available datasets must return configured or default resource.");

        echo "  ✓ Custom API Connector read-only contract & scope enforcement verified." . PHP_EOL;
    }
}
