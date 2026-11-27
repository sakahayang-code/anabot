<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\AI\AIManager;
use AnaBot\AI\PromptManager;
use AnaBot\Core\Database;
use AnaBot\Core\Response;
use AnaBot\Models\Dataset;
use AnaBot\Services\AnomalyDetector;
use AnaBot\Services\ForecastEngine;
use Throwable;

/**
 * AnaBot Predictive & Anomaly Detection Controller
 * 
 * Manages predictive analytics, forecasting models (OLS, Holt-Winters, Moving Average),
 * statistical outlier detection (IQR, Z-Score), and AI predictive interpretation.
 */
class PredictiveController
{
    private Database $db;
    private AnomalyDetector $anomalyDetector;
    private ForecastEngine $forecastEngine;
    private AIManager $aiManager;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->anomalyDetector = new AnomalyDetector($this->db);
        $this->forecastEngine = new ForecastEngine($this->db);
        $this->aiManager = AIManager::getInstance($this->db);
    }

    /**
     * Web View: Predictive Analytics & Anomaly Detection Dashboard
     */
    public function predictivePage(mixed $params = null): void
    {
        // 1. Fetch available datasets
        $datasets = Dataset::all(50, 0);
        if (empty($datasets)) {
            Response::view('layouts/main', [
                'pageTitle' => 'Analytics — Predictive & Anomaly Detection',
                'content' => $this->renderEmptyState('Belum ada dataset yang tersedia untuk peramalan & deteksi anomali. Silakan hubungkan platform data pada menu Connectors terlebih dahulu.'),
            ]);
            return;
        }

        // 2. Resolve dataset ID
        $selectedDatasetId = isset($_GET['dataset_id']) ? (int) $_GET['dataset_id'] : (int) $datasets[0]['id'];
        $selectedDataset = null;
        foreach ($datasets as $ds) {
            if ((int) $ds['id'] === $selectedDatasetId) {
                $selectedDataset = $ds;
                break;
            }
        }
        if (!$selectedDataset) {
            $selectedDataset = $datasets[0];
            $selectedDatasetId = (int) $selectedDataset['id'];
        }

        // 3. Resolve metric columns
        $columns = $this->db->query(
            "SELECT column_name, data_type, is_primary_date, is_metric, is_dimension 
             FROM dataset_columns WHERE dataset_id = :did ORDER BY column_order ASC",
            [':did' => $selectedDatasetId]
        );

        $metrics = [];
        foreach ($columns as $c) {
            if ($c['is_metric'] || in_array($c['data_type'], ['NUMERIC', 'INTEGER'], true)) {
                $metrics[] = $c['column_name'];
            }
        }

        if (empty($metrics)) {
            Response::view('layouts/main', [
                'pageTitle' => 'Analytics — Predictive & Anomaly Detection',
                'content' => $this->renderEmptyState('Dataset ini tidak memiliki kolom numerik yang terukur untuk dianalisis peramalan.'),
            ]);
            return;
        }

        $selectedMetric = $_GET['metric'] ?? $metrics[0];
        if (!in_array($selectedMetric, $metrics, true)) {
            $selectedMetric = $metrics[0];
        }

        $interval = $_GET['interval'] ?? 'day';
        $forecastMethod = $_GET['method'] ?? 'ols';
        $horizon = isset($_GET['horizon']) ? max(3, min(24, (int)$_GET['horizon'])) : 6;
        $zThreshold = isset($_GET['sensitivity']) && $_GET['sensitivity'] === 'strict' ? 2.0 : 2.5;

        // 4. Run Anomaly Detection & Forecasting
        $anomalyReport = null;
        $forecastReport = null;
        $errorMessage = null;

        try {
            $anomalyReport = $this->anomalyDetector->detectDatasetAnomalies(
                $selectedDatasetId,
                $selectedMetric,
                [
                    'interval' => $interval,
                    'z_threshold' => $zThreshold,
                ]
            );

            $forecastReport = $this->forecastEngine->forecastDataset(
                $selectedDatasetId,
                $selectedMetric,
                $forecastMethod,
                $horizon,
                ['interval' => $interval]
            );
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        // Render page view
        ob_start();
        $pageTitle = 'Predictive Analytics & Anomaly Detection — AnaBot';
        include dirname(__DIR__, 2) . '/resources/views/analytics/predictive.php';
        $content = ob_get_clean();

        Response::view('layouts/main', [
            'pageTitle' => $pageTitle,
            'content' => $content,
        ]);
    }

    // ==========================================
    // REST API ENDPOINTS
    // ==========================================

    /**
     * API: Get Anomaly Detection Report
     * GET /api/v1/analytics/datasets/{id}/anomalies
     */
    public function anomaliesApi(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $metric = $_GET['metric'] ?? '';
        $interval = $_GET['interval'] ?? 'day';
        $zThreshold = isset($_GET['z_threshold']) ? (float)$_GET['z_threshold'] : 2.5;

        if ($metric === '') {
            $first = $this->getFirstMetric($id);
            if (!$first) {
                Response::json(['success' => false, 'error' => 'Tidak ditemukan kolom metrik numerik.'], 404);
                return;
            }
            $metric = $first;
        }

        try {
            $report = $this->anomalyDetector->detectDatasetAnomalies($id, $metric, [
                'interval' => $interval,
                'z_threshold' => $zThreshold,
            ]);

            Response::json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * API: Get Time Series Forecast Projection
     * GET /api/v1/analytics/datasets/{id}/forecast
     */
    public function forecastApi(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $metric = $_GET['metric'] ?? '';
        $method = $_GET['method'] ?? 'ols';
        $interval = $_GET['interval'] ?? 'day';
        $horizon = isset($_GET['horizon']) ? max(1, min(36, (int)$_GET['horizon'])) : 6;

        if ($metric === '') {
            $first = $this->getFirstMetric($id);
            if (!$first) {
                Response::json(['success' => false, 'error' => 'Tidak ditemukan kolom metrik numerik.'], 404);
                return;
            }
            $metric = $first;
        }

        try {
            $report = $this->forecastEngine->forecastDataset($id, $metric, $method, $horizon, [
                'interval' => $interval,
            ]);

            Response::json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * API: AI Interpretation for Predictive Analytics & Anomalies
     * POST /api/v1/analytics/datasets/{id}/interpret-predictive
     */
    public function interpretPredictiveApi(mixed $params): void
    {
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;
        $input = $this->getRequestJson();
        $metric = $input['metric'] ?? $this->getFirstMetric($id);
        $interval = $input['interval'] ?? 'day';

        try {
            $anomalies = $this->anomalyDetector->detectDatasetAnomalies($id, $metric, ['interval' => $interval]);
            $forecast = $this->forecastEngine->forecastDataset($id, $metric, 'ols', 6, ['interval' => $interval]);

            // Format deterministic predictive facts
            $facts = "### FAKTA PREDIKSI & ANOMALI DETERMINISTIK (PHP ANABOT):\n";
            $facts .= "- **Metrik:** {$metric} (Interval: {$interval})\n";
            $facts .= "- **Tingkat Risiko Anomali:** {$anomalies['risk_level']} (Ditemukan {$anomalies['anomaly_count']} anomali, {$anomalies['critical_count']} kritis)\n";

            if (!empty($anomalies['anomalies'])) {
                $facts .= "#### Rincian Anomali Terdeteksi:\n";
                foreach (array_slice($anomalies['anomalies'], 0, 5) as $a) {
                    $facts .= sprintf("- Periode %s: Nilai %s (%s) — %s\n", $a['period'], number_format($a['value'], 2), strtoupper($a['severity']), implode('; ', $a['reasons']));
                }
            }

            $facts .= "\n#### Proyeksi Tren Masa Depan (OLS Regression, Horizon 6 Periode):\n";
            $facts .= sprintf("- Nilai Historis Terakhir: %s\n", number_format($forecast['latest_actual_value'], 2));
            $facts .= sprintf("- Ekspektasi Pertumbuhan Kumulatif: %s%%\n", $forecast['projected_growth_pct']);
            $facts .= sprintf("- Evaluasi Akurasi Model: R² = %s, MAPE = %s%%\n", $forecast['parameters']['r_squared'], $forecast['accuracy_evaluation']['mape_pct']);
            $facts .= "- Titik Proyeksi Terakhir: " . number_format(end($forecast['forecast_points'])['forecast'], 2) . " (Rentang 95%: " . number_format(end($forecast['forecast_points'])['lower_bound_95'], 2) . " s/d " . number_format(end($forecast['forecast_points'])['upper_bound_95'], 2) . ")\n";
            $facts .= "\n- **Klausul Asumsi Mutlak:** " . $forecast['assumption_disclaimer'] . "\n";

            $system = PromptManager::buildSystemInstruction() . "\n\n" . $facts;
            $userPrompt = <<<PROMPT
Berikan interpretasi profesional terhadap temuan anomali dan proyeksi di atas:
1. **Diagnosis Anomali:** Jelaskan potensi pemicu anomali dan implikasinya terhadap stabilitas bisnis.
2. **Evaluasi Trajektori Masa Depan:** Tafsirkan arah proyeksi ke depan dan tingkat kepastian model.
3. **Rekomendasi Aksi Pencegahan & Mitigasi:** Berikan 3 langkah taktis terukur berdasarkan bukti data aktual di atas.
PROMPT;

            $messages = [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $aiResult = $this->aiManager->generateChat($messages);

            Response::json([
                'success' => true,
                'data' => [
                    'metric' => $metric,
                    'interpretation' => $aiResult['text'] ?? $aiResult['content'] ?? '',
                    'provider' => $aiResult['provider'] ?? 'AI',
                    'model' => $aiResult['model'] ?? '',
                    'tokens' => ($aiResult['prompt_tokens'] ?? 0) + ($aiResult['completion_tokens'] ?? 0),
                ],
            ]);
        } catch (Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function getFirstMetric(int $datasetId): ?string
    {
        $cols = $this->db->query(
            "SELECT column_name FROM dataset_columns 
             WHERE dataset_id = :did AND (is_metric = 1 OR data_type IN ('NUMERIC', 'INTEGER'))
             ORDER BY column_order ASC LIMIT 1",
            [':did' => $datasetId]
        );
        return $cols[0]['column_name'] ?? null;
    }

    private function getRequestJson(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST ?? [];
    }

    private function renderEmptyState(string $message): string
    {
        return <<<HTML
<div class="card border-0 shadow-sm text-center py-5">
    <div class="card-body py-5">
        <div class="avatar-lg rounded-circle bg-warning bg-opacity-10 text-warning mx-auto mb-3 d-flex align-items-center justify-content-center" style="width: 64px; height: 64px;">
            <i class="fas fa-chart-line fs-2"></i>
        </div>
        <h4>Belum Ada Data Tersedia</h4>
        <p class="text-muted col-lg-6 mx-auto mb-4">{$message}</p>
        <a href="/connectors" class="btn btn-primary px-4">
            <i class="fas fa-plug me-1"></i> Buka Data Sources
        </a>
    </div>
</div>
HTML;
    }
}
