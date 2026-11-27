<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\Core\Auth;
use AnaBot\Core\Response;
use AnaBot\Core\Validator;
use AnaBot\Models\Report;
use AnaBot\Models\Dataset;
use AnaBot\Models\DatasetColumn;
use AnaBot\Services\ReportEngine;
use RuntimeException;

/**
 * AnaBot Report Controller
 * 
 * Manages Report Center dashboard, interactive report builder, secure downloads,
 * and REST API endpoints.
 */
class ReportController
{
    /**
     * Web View: Report Center Dashboard (/reports)
     */
    public function index(): void
    {
        $userId = (int) Auth::id();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $typeFilter = !empty($_GET['type']) ? strtolower(trim((string) $_GET['type'])) : null;

        $reports = Report::getByUser($userId, $limit, $offset, $typeFilter);
        $totalReports = Report::countByUser($userId, $typeFilter);
        $totalPages = max(1, (int) ceil($totalReports / $limit));
        $storage = Report::getStorageUsage($userId);

        view('reports/index', [
            'pageTitle' => 'Pusat Laporan (Report Center)',
            'reports' => $reports,
            'totalReports' => $totalReports,
            'storage' => $storage,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'currentType' => $typeFilter,
        ]);
    }

    /**
     * Web View: Report Builder Wizard (/reports/create)
     */
    public function create(): void
    {
        $datasets = Dataset::all();
        $selectedDatasetId = (int) ($_GET['dataset_id'] ?? ($datasets[0]['id'] ?? 0));
        $columns = $selectedDatasetId > 0 ? DatasetColumn::getByDatasetId($selectedDatasetId) : [];

        view('reports/create', [
            'pageTitle' => 'Buat Laporan Baru',
            'datasets' => $datasets,
            'selectedDatasetId' => $selectedDatasetId,
            'columns' => $columns,
        ]);
    }

    /**
     * Web Action: Handle Report Generation (POST /reports/generate)
     */
    public function generate(): void
    {
        $userId = (int) Auth::id();

        $input = [
            '_csrf_token' => $_POST['_csrf_token'] ?? '',
            'dataset_id' => $_POST['dataset_id'] ?? '',
            'format' => strtolower((string) ($_POST['format'] ?? 'all')),
            'title' => trim((string) ($_POST['title'] ?? '')),
            'metric_name' => trim((string) ($_POST['metric_name'] ?? '')),
            'include_ai_summary' => isset($_POST['include_ai_summary']) ? (bool) $_POST['include_ai_summary'] : true,
            'date_start' => trim((string) ($_POST['date_start'] ?? '')),
            'date_end' => trim((string) ($_POST['date_end'] ?? '')),
        ];

        $validator = Validator::make($input, [
            '_csrf_token' => 'required|csrf',
            'dataset_id' => 'required|numeric',
            'format' => 'required|in:all,html,pdf,xlsx,csv',
        ]);

        if ($validator->fails()) {
            $_SESSION['flash_error'] = $validator->firstError();
            Response::redirect('/reports/create');
        }

        try {
            $engine = new ReportEngine();
            $options = [
                'title' => !empty($input['title']) ? $input['title'] : null,
                'metric_name' => !empty($input['metric_name']) ? $input['metric_name'] : null,
                'include_ai_summary' => (bool) $input['include_ai_summary'],
                'date_start' => !empty($input['date_start']) ? $input['date_start'] : null,
                'date_end' => !empty($input['date_end']) ? $input['date_end'] : null,
            ];

            $created = $engine->buildAndSave($userId, (int) $input['dataset_id'], $input['format'], $options);

            $_SESSION['flash_success'] = 'Berhasil membuat ' . count($created) . ' dokumen laporan baru.';
            Response::redirect('/reports');
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal membuat laporan: ' . $e->getMessage();
            Response::redirect('/reports/create');
        }
    }

    /**
     * Web View: Preview Report (/reports/{id}/preview)
     */
    public function preview(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $userId = (int) Auth::id();
        $report = Report::find($id);

        if (!$report || (int) $report['user_id'] !== $userId) {
            $_SESSION['flash_error'] = 'Laporan tidak ditemukan atau Anda tidak memiliki akses.';
            Response::redirect('/reports');
        }

        $realFilePath = $this->validateFilePath($report['file_path']);

        $htmlContent = null;
        if ($report['report_type'] === 'html') {
            $htmlContent = file_get_contents($realFilePath);
        }

        view('reports/preview', [
            'pageTitle' => 'Pratinjau: ' . $report['title'],
            'report' => $report,
            'htmlContent' => $htmlContent,
        ]);
    }

    /**
     * Web / API Action: Secure File Download (/reports/{id}/download)
     */
    public function download(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $userId = (int) Auth::id();
        $report = Report::find($id);

        if (!$report || (int) $report['user_id'] !== $userId) {
            if (is_api_request()) {
                Response::error('Laporan tidak ditemukan atau akses ditolak.', 'NOT_FOUND', null, 404);
            }
            $_SESSION['flash_error'] = 'Laporan tidak ditemukan atau akses ditolak.';
            Response::redirect('/reports');
        }

        $realFilePath = $this->validateFilePath($report['file_path']);

        $filename = basename($realFilePath);
        $fileSize = filesize($realFilePath);
        $mimeType = match ($report['report_type']) {
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv; charset=UTF-8',
            'html' => 'text/html; charset=UTF-8',
            default => 'application/octet-stream',
        };

        // Clean output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: private, no-transform, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($realFilePath);
        exit;
    }

    /**
     * Web / API Action: Delete Report (/reports/{id}/delete)
     */
    public function delete(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $userId = (int) Auth::id();
        $report = Report::find($id);

        if (!$report || (int) $report['user_id'] !== $userId) {
            if (is_api_request()) {
                Response::error('Laporan tidak ditemukan.', 'NOT_FOUND', null, 404);
            }
            $_SESSION['flash_error'] = 'Laporan tidak ditemukan.';
            Response::redirect('/reports');
        }

        Report::deleteWithFile($id);

        if (is_api_request()) {
            Response::success(null, 'Laporan berhasil dihapus.');
        }

        $_SESSION['flash_success'] = 'Laporan #' . $id . ' berhasil dihapus.';
        Response::redirect('/reports');
    }

    /**
     * REST API: List Reports (GET /api/v1/reports)
     */
    public function apiIndex(): void
    {
        $userId = (int) Auth::id();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $type = !empty($_GET['type']) ? strtolower(trim((string) $_GET['type'])) : null;

        $reports = Report::getByUser($userId, $limit, $offset, $type);
        $total = Report::countByUser($userId, $type);
        $storage = Report::getStorageUsage($userId);

        Response::success([
            'reports' => $reports,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => $total,
                'total_pages' => (int) ceil($total / $limit),
            ],
            'storage' => $storage,
        ]);
    }

    /**
     * REST API: Create Report (POST /api/v1/reports)
     */
    public function apiCreate(): void
    {
        $userId = (int) Auth::id();
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true) ?: $_POST;

        $validator = Validator::make($payload, [
            'dataset_id' => 'required|numeric',
            'format' => 'required|in:all,html,pdf,xlsx,csv',
        ]);

        if ($validator->fails()) {
            Response::error('Validasi gagal', 'VALIDATION_FAILED', $validator->errors(), 422);
        }

        try {
            $engine = new ReportEngine();
            $options = [
                'title' => !empty($payload['title']) ? (string) $payload['title'] : null,
                'metric_name' => !empty($payload['metric_name']) ? (string) $payload['metric_name'] : null,
                'include_ai_summary' => isset($payload['include_ai_summary']) ? (bool) $payload['include_ai_summary'] : true,
                'date_start' => !empty($payload['date_start']) ? (string) $payload['date_start'] : null,
                'date_end' => !empty($payload['date_end']) ? (string) $payload['date_end'] : null,
            ];

            $created = $engine->buildAndSave($userId, (int) $payload['dataset_id'], (string) $payload['format'], $options);
            Response::success($created, 'Laporan berhasil dibuat.', 201);
        } catch (\Throwable $e) {
            Response::error('Gagal membuat laporan: ' . $e->getMessage(), 'REPORT_GENERATION_FAILED', null, 500);
        }
    }

    /**
     * REST API: Get Report Details (GET /api/v1/reports/{id})
     */
    public function apiShow(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $userId = (int) Auth::id();
        $report = Report::find($id);

        if (!$report || (int) $report['user_id'] !== $userId) {
            Response::error('Laporan tidak ditemukan.', 'NOT_FOUND', null, 404);
        }

        Response::success($report);
    }

    /**
     * Path Traversal Protection: Ensure file is inside the reports storage directory.
     */
    private function validateFilePath(string $filePath): string
    {
        $resolved = str_starts_with($filePath, '/') ? $filePath : dirname(__DIR__, 2) . '/' . ltrim($filePath, '/');

        if (empty($resolved) || !file_exists($resolved)) {
            throw new RuntimeException("Berkas laporan fisik tidak ditemukan di penyimpanan.");
        }

        $realTarget = realpath($resolved);
        $storageDir = realpath(config('app.paths.reports', storage_path('reports')));

        if ($realTarget === false || $storageDir === false || !str_starts_with($realTarget, $storageDir)) {
            throw new RuntimeException("Akses berkas ditolak: Path traversal detected.");
        }

        return $realTarget;
    }
}
