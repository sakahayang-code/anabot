<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\Core\Auth;
use AnaBot\Core\Database;
use AnaBot\Core\Response;
use AnaBot\Core\Validator;
use AnaBot\Models\PlatformConnection;
use AnaBot\Services\ConnectorManager;
use AnaBot\Services\CredentialManager;
use AnaBot\Services\OAuthStateManager;
use AnaBot\Services\SyncQueueManager;
use RuntimeException;

/**
 * Controller for Managing External Platform Connectors & Sync Orchestration
 */
class ConnectorController
{
    private Database $db;
    private PlatformConnection $connectionModel;
    private ConnectorManager $connectorManager;
    private CredentialManager $credentialManager;
    private SyncQueueManager $syncQueue;
    private OAuthStateManager $oauthStateManager;

    public function __construct(
        ?Database $db = null,
        ?PlatformConnection $connectionModel = null,
        ?ConnectorManager $connectorManager = null,
        ?CredentialManager $credentialManager = null,
        ?SyncQueueManager $syncQueue = null,
        ?OAuthStateManager $oauthStateManager = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->connectionModel = $connectionModel ?? new PlatformConnection($this->db);
        $this->connectorManager = $connectorManager ?? new ConnectorManager($this->db);
        $this->credentialManager = $credentialManager ?? new CredentialManager($this->db);
        $this->syncQueue = $syncQueue ?? new SyncQueueManager($this->db);
        $this->oauthStateManager = $oauthStateManager ?? new OAuthStateManager($this->db);
    }

    /**
     * Web View: List Active Connections & Connector Catalog
     */
    public function index(): void
    {
        $connections = $this->connectionModel->getAllWithConnectors();
        $catalog = $this->connectorManager->listCatalog();

        view('connectors/index', [
            'pageTitle' => 'Konektor & Integrasi Data',
            'connections' => $connections,
            'catalog' => $catalog,
        ]);
    }

    /**
     * Web View: Connection Setup Form
     */
    public function create(): void
    {
        $selectedCode = $_GET['code'] ?? 'custom_api';
        $catalog = $this->connectorManager->listCatalog();
        $selectedConnector = $catalog[$selectedCode] ?? ($catalog['custom_api'] ?? reset($catalog));

        view('connectors/create', [
            'pageTitle' => 'Tambah Koneksi Platform',
            'selectedCode' => $selectedCode,
            'connector' => $selectedConnector,
            'catalog' => $catalog,
        ]);
    }

    /**
     * Web Action: Store New Platform Connection
     */
    public function store(): void
    {
        $input = $_POST;
        $validator = Validator::make($input, [
            'connector_code' => 'required',
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            $_SESSION['flash_error'] = $validator->firstError() ?? 'Kode konektor dan nama koneksi wajib diisi.';
            redirect('/connectors/create?code=' . urlencode((string) ($input['connector_code'] ?? '')));
            return;
        }

        $code = trim($input['connector_code']);
        $name = trim($input['name']);
        $interval = max(5, (int) ($input['sync_interval_minutes'] ?? 60));
        $autoSync = !empty($input['auto_sync_enabled']);

        $connector = $this->db->queryOne("SELECT id, auth_type FROM connectors WHERE connector_code = :c LIMIT 1", [':c' => $code]);
        if (!$connector) {
            $_SESSION['flash_error'] = "Konektor '{$code}' tidak valid.";
            redirect('/connectors');
            return;
        }

        $userId = Auth::id() ?? 1;
        $connectionId = $this->connectionModel->create((int) $connector['id'], $userId, $name, $interval, $autoSync);

        // Prepare credentials and connection configurations
        $credentials = [];
        $tokenType = match ($connector['auth_type'] ?? '') {
            'database' => 'Database',
            'api_key'  => (!empty($input['consumer_key']) ? 'Basic' : 'ApiKey'),
            default    => 'Bearer',
        };

        $allowedFields = [
            'api_key', 'access_token', 'refresh_token', 'base_url',
            'site_url', 'shop_domain', 'property_id', 'ad_account_id', 'advertiser_id', 'channel_id',
            'consumer_key', 'consumer_secret', 'spreadsheet_id', 'spreadsheet_url', 'service_account_json',
            'host', 'port', 'database', 'username', 'password', 'charset',
        ];

        foreach ($allowedFields as $field) {
            if (isset($input[$field]) && $input[$field] !== '') {
                $credentials[$field] = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
            }
        }

        // Store encrypted credentials
        if (!empty($credentials)) {
            $this->credentialManager->store($connectionId, $credentials, $tokenType);
        }

        $_SESSION['flash_success'] = "Koneksi '{$name}' berhasil dibuat.";
        redirect("/connectors/{$connectionId}");
    }

    /**
     * Web View: Connection Detail & Sync History
     */
    public function show(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        $connection = $this->connectionModel->findById($id);
        if (!$connection) {
            redirect('/connectors');
            return;
        }

        $catalog = $this->connectorManager->listCatalog();
        $meta = $catalog[$connection['connector_code']] ?? [];

        $credMeta = $this->credentialManager->getMetadata($id);
        $credentials = $this->credentialManager->get($id) ?? [];
        $history = $this->syncQueue->getHistory($id, 15);
        $latestJob = $this->syncQueue->getLatestJob($id);
        $latestLogs = $latestJob ? $this->syncQueue->getJobLogs((int) $latestJob['id']) : [];

        // Check active cursors
        $cursors = $this->db->queryAll(
            "SELECT * FROM sync_cursors WHERE connection_id = :cid ORDER BY updated_at DESC",
            [':cid' => $id]
        );

        view('connectors/detail', [
            'pageTitle' => "Detail Koneksi: {$connection['name']}",
            'connection' => $connection,
            'meta' => $meta,
            'credMeta' => $credMeta,
            'credentials' => $credentials,
            'history' => $history,
            'latestJob' => $latestJob,
            'latestLogs' => $latestLogs,
            'cursors' => $cursors,
        ]);
    }

    /**
     * AJAX / API: Test Connection
     */
    public function test(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        try {
            $result = $this->connectorManager->testConnection($id);
            Response::json($result);
        } catch (\Throwable $e) {
            Response::json([
                'success' => false,
                'message' => 'Gagal menguji koneksi: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * AJAX / API: Trigger Sync
     */
    public function sync(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        try {
            $result = $this->connectorManager->syncConnection($id, null, 'manual');
            Response::json($result);
        } catch (\Throwable $e) {
            Response::json([
                'success' => false,
                'message' => 'Gagal sinkronisasi data: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Web / API: Disconnect & Revoke Credentials
     */
    public function disconnect(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        $success = $this->connectorManager->disconnect($id);

        if (is_api_request()) {
            Response::json(['success' => $success]);
            return;
        }

        $_SESSION['flash_success'] = "Koneksi berhasil diputus dan kredensial telah dihapus dari sistem.";
        redirect("/connectors/{$id}");
    }

    /**
     * Web: Update Connection Settings & Credentials
     */
    public function updateSettings(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        $connection = $this->connectionModel->findById($id);
        if (!$connection) {
            redirect('/connectors');
            return;
        }

        $input = $_POST;
        $name = trim($input['name'] ?? '');
        $interval = max(5, (int) ($input['sync_interval_minutes'] ?? 60));
        $autoSync = !empty($input['auto_sync_enabled']);

        if (!empty($name)) {
            $this->connectionModel->updateSettings($id, $name, $interval, $autoSync);
        }

        // Check if any credentials or configuration parameters were submitted to update
        $allowedFields = [
            'api_key', 'access_token', 'refresh_token', 'base_url',
            'site_url', 'shop_domain', 'property_id', 'ad_account_id', 'advertiser_id', 'channel_id',
            'consumer_key', 'consumer_secret', 'spreadsheet_id', 'spreadsheet_url', 'service_account_json',
            'host', 'port', 'database', 'username', 'password', 'charset',
        ];

        $currentCreds = $this->credentialManager->get($id) ?? [];
        $hasCredUpdate = false;

        foreach ($allowedFields as $field) {
            if (isset($input[$field]) && $input[$field] !== '') {
                $currentCreds[$field] = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
                $hasCredUpdate = true;
            }
        }

        if ($hasCredUpdate) {
            $tokenType = match ($connection['auth_type'] ?? '') {
                'database' => 'Database',
                'api_key'  => (!empty($currentCreds['consumer_key']) ? 'Basic' : 'ApiKey'),
                default    => 'Bearer',
            };
            $this->credentialManager->store($id, $currentCreds, $tokenType);
        }

        $_SESSION['flash_success'] = "Pengaturan dan kredensial koneksi berhasil diperbarui.";
        redirect("/connectors/{$id}");
    }

    /**
     * API: Get Sync History for a connection
     */
    public function history(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        $history = $this->syncQueue->getHistory($id, 30);
        Response::json(['success' => true, 'data' => $history]);
    }

    /**
     * Web View: Global Sync History across all platform connections
     */
    public function historyPage(): void
    {
        $jobs = $this->db->queryAll(
            "SELECT j.*, c.name as connection_name, conn.name as connector_name, conn.connector_code
             FROM sync_jobs j
             JOIN platform_connections c ON j.connection_id = c.id
             JOIN connectors conn ON c.connector_id = conn.id
             ORDER BY j.id DESC LIMIT 50"
        );

        view('connectors/history', [
            'pageTitle' => 'Riwayat Sinkronisasi Seluruh Platform',
            'jobs' => $jobs,
        ]);
    }

    /**
     * API: Get Job Logs
     */
    public function logs(int|array $jobId): void
    {
        $jobId = is_array($jobId) ? (int) ($jobId['job_id'] ?? $jobId['id'] ?? 0) : (int) $jobId;
        $logs = $this->syncQueue->getJobLogs($jobId);
        Response::json(['success' => true, 'data' => $logs]);
    }

    /**
     * OAuth Flow: Initialize State & Redirect
     */
    public function oauthAuthorize(string|array $code): void
    {
        $code = is_array($code) ? (string) ($code['code'] ?? '') : (string) $code;
        $redirectUri = url('/connectors/oauth/callback');
        $stateToken = $this->oauthStateManager->createState($code, $redirectUri);

        Response::json([
            'success' => true,
            'connector_code' => $code,
            'state_token' => $stateToken,
            'redirect_uri' => $redirectUri,
            'message' => 'OAuth state token generated with anti-CSRF protection.',
        ]);
    }

    /**
     * OAuth Flow: Verify State Callback
     */
    public function oauthCallback(): void
    {
        $state = $_GET['state'] ?? '';
        $code = $_GET['code'] ?? '';
        $connectorCode = $_GET['connector'] ?? 'google_sheets';

        if (empty($state) || !$this->oauthStateManager->verifyAndConsume($state, $connectorCode)) {
            if (is_api_request()) {
                Response::json(['success' => false, 'error' => 'Invalid or expired OAuth state token (CSRF check failed).'], 403);
                return;
            }
            $_SESSION['flash_error'] = 'Token OAuth CSRF tidak valid atau telah kadaluarsa.';
            redirect('/connectors');
            return;
        }

        Response::json([
            'success' => true,
            'message' => 'OAuth state token successfully verified.',
        ]);
    }

    /**
     * Web View: Google Sheets Import Studio
     */
    public function googleSheetsImportPage(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        $connection = $this->connectionModel->findById($id);
        if (!$connection) {
            redirect('/connectors');
            return;
        }

        $credentials = $this->credentialManager->get($id) ?? [];
        $connector = new \AnaBot\Connectors\GoogleSheets\GoogleSheetsConnector([], $credentials, $id, null, $this->db);
        $spreadsheetId = $connector->getSpreadsheetId();
        $availableSheets = $connector->getAvailableDatasets();

        view('connectors/google_sheets/import', [
            'pageTitle' => "Import Google Sheets: {$connection['name']}",
            'connection' => $connection,
            'spreadsheetId' => $spreadsheetId,
            'availableSheets' => $availableSheets,
        ]);
    }

    /**
     * API: Get Google Sheets Preview (Headers + Sample Rows + Inferred Schema)
     */
    public function googleSheetsPreviewApi(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        $sheetName = $_GET['sheet'] ?? null;

        try {
            $credentials = $this->credentialManager->get($id) ?? [];
            $connector = new \AnaBot\Connectors\GoogleSheets\GoogleSheetsConnector([], $credentials, $id, null, $this->db);
            $preview = $connector->previewSheet($sheetName, 5);

            Response::json(['success' => true, 'data' => $preview]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * API: Execute Google Sheets Atomic Import
     */
    public function googleSheetsImportApi(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        $input = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;

        $sheetName = $input['sheet_name'] ?? 'Sheet1';
        $datasetName = $input['dataset_name'] ?? null;
        $columns = $input['columns'] ?? null;

        try {
            $importer = new \AnaBot\Services\GoogleSheetsImporter($this->db, $this->credentialManager, $this->syncQueue);
            $result = $importer->importSheet($id, $sheetName, $datasetName, $columns);

            Response::json($result);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
