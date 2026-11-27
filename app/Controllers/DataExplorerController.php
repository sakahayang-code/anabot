<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\Core\Response;
use AnaBot\Core\Validator;
use AnaBot\Models\Dataset;
use AnaBot\Services\DataExplorerService;

/**
 * AnaBot Data Explorer Controller
 */
class DataExplorerController
{
    private DataExplorerService $service;

    public function __construct()
    {
        $this->service = new DataExplorerService();
    }

    public function datasets(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $data = $this->service->listDatasets($page, 15);

        ob_start();
        extract($data, EXTR_SKIP);
        require dirname(__DIR__, 2) . '/resources/views/explorer/datasets.php';
        $content = ob_get_clean();

        Response::view('layouts/main', [
            'pageTitle' => 'Data Explorer - Datasets',
            'content' => $content,
        ]);
    }

    public function records(array $params = []): void
    {
        $datasetId = (int) ($params['id'] ?? $_GET['dataset_id'] ?? 0);

        if ($datasetId <= 0) {
            $first = Dataset::findFirst();
            if ($first) {
                $datasetId = (int) $first['id'];
            }
        }

        if ($datasetId <= 0) {
            // No dataset exists in database
            ob_start();
            $dataset = null;
            $allDatasets = [];
            require dirname(__DIR__, 2) . '/resources/views/explorer/records.php';
            $content = ob_get_clean();

            Response::view('layouts/main', [
                'pageTitle' => 'Data Explorer - Records Browser',
                'content' => $content,
            ]);
            return;
        }

        $data = $this->service->getRecords($datasetId, $_GET);
        $data['allDatasets'] = Dataset::all(100, 0);

        ob_start();
        extract($data, EXTR_SKIP);
        require dirname(__DIR__, 2) . '/resources/views/explorer/records.php';
        $content = ob_get_clean();

        Response::view('layouts/main', [
            'pageTitle' => 'Records Browser - ' . ($data['dataset']['name'] ?? 'Dataset'),
            'content' => $content,
        ]);
    }

    public function schema(array $params = []): void
    {
        $datasetId = (int) ($params['id'] ?? $_GET['dataset_id'] ?? 0);

        if ($datasetId <= 0) {
            $first = Dataset::findFirst();
            if ($first) {
                $datasetId = (int) $first['id'];
            }
        }

        if ($datasetId <= 0) {
            ob_start();
            $dataset = null;
            $allDatasets = [];
            require dirname(__DIR__, 2) . '/resources/views/explorer/schema.php';
            $content = ob_get_clean();

            Response::view('layouts/main', [
                'pageTitle' => 'Schema Discovery',
                'content' => $content,
            ]);
            return;
        }

        $data = $this->service->getSchema($datasetId);
        $data['allDatasets'] = Dataset::all(100, 0);

        ob_start();
        extract($data, EXTR_SKIP);
        require dirname(__DIR__, 2) . '/resources/views/explorer/schema.php';
        $content = ob_get_clean();

        Response::view('layouts/main', [
            'pageTitle' => 'Schema Discovery - ' . ($data['dataset']['name'] ?? 'Dataset'),
            'content' => $content,
        ]);
    }

    public function exportCsv(array $params = []): void
    {
        $datasetId = (int) ($params['id'] ?? $_GET['dataset_id'] ?? 0);
        $dataset = Dataset::find($datasetId);

        if (!$dataset) {
            Response::error('Dataset not found', 'NOT_FOUND', null, 404);
        }

        $filename = 'dataset_' . $datasetId . '_' . date('Ymd_His') . '.csv';

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header("Content-Disposition: attachment; filename=\"{$filename}\"");
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        // Add UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";

        // Stream generator line-by-line without high RAM usage
        $stream = $this->service->streamCsvExport($datasetId);
        foreach ($stream as $line) {
            echo $line;
            flush();
        }
        exit;
    }

    public function updateColumn(array $params = []): void
    {
        $columnId = (int) ($params['id'] ?? 0);
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;

        $validator = Validator::make($data, [
            'data_type' => 'in:TEXT,INTEGER,NUMERIC,DATETIME,BOOLEAN',
        ]);

        if ($validator->fails()) {
            Response::error('Validasi gagal', 'VALIDATION_FAILED', $validator->errors(), 422);
        }

        $success = $this->service->updateColumn($columnId, $data);
        if (!$success) {
            Response::error('Gagal memperbarui kolom', 'UPDATE_FAILED', null, 400);
        }

        if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            Response::success(null, 'Metadata kolom berhasil diperbarui.');
        }

        Response::redirect($_SERVER['HTTP_REFERER'] ?? '/explorer/schema');
    }

    public function deleteDataset(array $params = []): void
    {
        $datasetId = (int) ($params['id'] ?? 0);

        // Verify CSRF
        $token = $_POST['_csrf_token'] ?? '';
        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        if (empty($sessionToken) || !hash_equals($sessionToken, (string) $token)) {
            Response::error('CSRF token mismatch', 'CSRF_FAILED', null, 403);
        }

        $success = $this->service->deleteDataset($datasetId);
        if (!$success) {
            Response::error('Gagal menghapus dataset', 'DELETE_FAILED', null, 400);
        }

        $_SESSION['flash_success'] = 'Dataset berhasil dihapus dari database lokal.';
        Response::redirect('/explorer/datasets');
    }

    // === REST API ENDPOINTS ===

    public function apiOverview(): void
    {
        $overview = $this->service->getOverview();
        Response::success($overview);
    }

    public function apiListDatasets(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($_GET['per_page'] ?? 15)));
        $result = $this->service->listDatasets($page, $perPage);
        Response::success($result);
    }

    public function apiGetDataset(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $dataset = $this->service->getDatasetDetails($id);
        if (!$dataset) {
            Response::error('Dataset not found', 'NOT_FOUND', null, 404);
        }
        Response::success($dataset);
    }

    public function apiGetRecords(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        try {
            $records = $this->service->getRecords($id, $_GET);
            Response::success($records);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 'DATASET_ERROR', null, 404);
        }
    }

    public function apiGetSchema(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        try {
            $schema = $this->service->getSchema($id);
            Response::success($schema);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 'DATASET_ERROR', null, 404);
        }
    }
}
