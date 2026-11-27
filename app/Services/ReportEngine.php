<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Database;
use AnaBot\Models\Dataset;
use AnaBot\Models\DatasetColumn;
use AnaBot\Models\DataRecord;
use AnaBot\Models\Report;
use AnaBot\AI\AIManager;
use AnaBot\AI\PromptManager;
use ZipArchive;
use RuntimeException;

/**
 * AnaBot Report Engine
 * 
 * Generates executive, data-driven reports in HTML, PDF, XLSX, and CSV formats
 * adhering strictly to mathematical integrity (Section 2.3 & Rule 1.14).
 */
class ReportEngine
{
    private Database $db;
    private AnalyticsEngine $analyticsEngine;
    private AnomalyDetector $anomalyDetector;
    private ForecastEngine $forecastEngine;
    private string $storagePath;

    public function __construct(
        ?string $customStoragePath = null,
        ?Database $db = null,
        ?AnalyticsEngine $analyticsEngine = null,
        ?AnomalyDetector $anomalyDetector = null,
        ?ForecastEngine $forecastEngine = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->analyticsEngine = $analyticsEngine ?? new AnalyticsEngine($this->db);
        $this->anomalyDetector = $anomalyDetector ?? new AnomalyDetector($this->db, $this->analyticsEngine);
        $this->forecastEngine = $forecastEngine ?? new ForecastEngine($this->db, $this->analyticsEngine);
        $this->storagePath = $customStoragePath 
            ?? config('app.paths.reports', storage_path('reports'));

        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0750, true);
        }
    }

    /**
     * Compile verified mathematical report data from SQLite.
     */
    public function compileReportData(
        int $datasetId,
        ?string $metricName = null,
        ?string $dateStart = null,
        ?string $dateEnd = null,
        array $options = []
    ): array {
        $dataset = Dataset::find($datasetId);
        if (!$dataset) {
            throw new RuntimeException("Dataset ID #{$datasetId} tidak ditemukan.");
        }

        // 1. Resolve Primary Metric
        if (empty($metricName)) {
            $metricCol = $this->db->queryOne(
                "SELECT column_name FROM dataset_columns 
                 WHERE dataset_id = :did AND is_metric = 1 
                 ORDER BY column_order ASC LIMIT 1",
                [':did' => $datasetId]
            );
            if ($metricCol) {
                $metricName = $metricCol['column_name'];
            }
        }

        if (empty($metricName)) {
            throw new RuntimeException("Tidak ditemukan kolom metrik numerik pada dataset #{$datasetId}.");
        }

        $dateCol = $this->db->queryOne(
            "SELECT column_name FROM dataset_columns 
             WHERE dataset_id = :did AND is_primary_date = 1 
             LIMIT 1",
            [':did' => $datasetId]
        );
        $dateColumnName = $dateCol['column_name'] ?? null;

        $title = $options['title'] ?? ("Laporan Analisis Eksekutif - " . ($dataset['name'] ?? 'Dataset #' . $datasetId));
        $horizon = (int) ($options['forecast_horizon'] ?? 6);
        $includeAi = (bool) ($options['include_ai_summary'] ?? true);

        // 2. Compute Deterministic Analytics
        $descriptiveStats = $this->analyticsEngine->getColumnStats($datasetId, $metricName, true);
        $timeSeries = $this->analyticsEngine->aggregateTimeSeries($datasetId, $metricName, $dateColumnName, 'day');
        $growth = $this->analyticsEngine->computeGrowth($timeSeries, 'total');
        $trend = $this->analyticsEngine->detectTrend($timeSeries, 'total');

        $timeSeriesFormatted = array_map(function($pt) {
            return ['date' => $pt['period'], 'value' => (float) $pt['total']];
        }, $timeSeries);

        $timeSeriesGrowth = [
            'time_series' => $timeSeriesFormatted,
            'growth' => $growth,
        ];

        $tsValues = array_column($timeSeriesFormatted, 'value');

        // 3. Compute Anomalies
        $anomalies = $this->anomalyDetector->detectDatasetAnomalies($datasetId, $metricName, ['interval' => 'day']);

        // 4. Compute Forecast
        $forecast = $this->forecastEngine->forecastDataset($datasetId, $metricName, 'ols', $horizon, ['interval' => 'day']);

        // 5. Fetch Historical Sample Records (up to 50 latest)
        $records = DataRecord::getByDatasetId($datasetId, 50, 0);

        // 6. Generate Executive AI / Grounded Summary
        $aiSummary = $this->resolveExecutiveSummary(
            $dataset,
            $metricName,
            $descriptiveStats,
            $timeSeriesGrowth,
            $trend,
            $anomalies,
            $forecast,
            $includeAi
        );

        $now = date('Y-m-d H:i:s');

        return [
            'title' => $title,
            'generated_at' => $now,
            'dataset' => $dataset,
            'metric_name' => $metricName,
            'date_range' => [
                'start' => $dateStart ?: ($timeSeriesGrowth['time_series'][0]['date'] ?? null),
                'end' => $dateEnd ?: (end($timeSeriesGrowth['time_series'])['date'] ?? null),
            ],
            'descriptive_stats' => $descriptiveStats,
            'growth' => $timeSeriesGrowth,
            'trend' => $trend,
            'anomalies' => $anomalies,
            'forecast' => $forecast,
            'ai_summary' => $aiSummary,
            'sample_records' => $records,
            'total_points' => count($tsValues),
        ];
    }

    /**
     * Resolve AI Executive Summary or generate mathematically grounded deterministic fallback.
     */
    private function resolveExecutiveSummary(
        array $dataset,
        string $metric,
        array $stats,
        array $growth,
        ?array $trend,
        array $anomalies,
        array $forecast,
        bool $includeAi
    ): string {
        $datasetName = $dataset['name'] ?? 'Dataset';
        $totalPts = $stats['count'] ?? 0;
        $mean = number_format((float) ($stats['mean'] ?? 0), 2);
        $median = number_format((float) ($stats['median'] ?? 0), 2);
        $min = number_format((float) ($stats['min'] ?? 0), 2);
        $max = number_format((float) ($stats['max'] ?? 0), 2);
        $stdDev = number_format((float) ($stats['std_dev'] ?? 0), 2);
        $riskLevel = $anomalies['risk_level'] ?? 'LOW';
        $anomalyCount = $anomalies['anomaly_count'] ?? 0;
        $trendDir = $trend['trend_direction'] ?? 'Sideways';
        $slope = number_format((float) ($trend['slope'] ?? 0), 4);
        $r2 = number_format((float) ($trend['r_squared'] ?? 0), 4);
        $nextTarget = !empty($forecast['predictions'][0]['predicted_value']) 
            ? number_format((float) $forecast['predictions'][0]['predicted_value'], 2) 
            : 'N/A';
        $lower95 = !empty($forecast['predictions'][0]['lower_bound_95']) 
            ? number_format((float) $forecast['predictions'][0]['lower_bound_95'], 2) 
            : 'N/A';
        $upper95 = !empty($forecast['predictions'][0]['upper_bound_95']) 
            ? number_format((float) $forecast['predictions'][0]['upper_bound_95'], 2) 
            : 'N/A';

        // Deterministic Mathematical Summary Template
        $deterministicSummary = "### Ringkasan Eksekutif Kinerja Data\n\n" .
            "1. **Distribusi & Skala Data:** Dataset **{$datasetName}** mencatat total **{$totalPts}** observasi pada metrik **{$metric}**. Rata-rata terhitung sebesar **{$mean}** dengan nilai median **{$median}** (rentang minimum **{$min}** hingga maksimum **{$max}**, deviasi standar **{$stdDev}**).\n" .
            "2. **Pola Tren:** Arah pergerakan data diklasifikasikan sebagai **{$trendDir}** dengan slope regresi **{$slope}** dan koefisien determinasi R² sebesar **{$r2}**.\n" .
            "3. **Evaluasi Anomali & Risiko:** Terdeteksi **{$anomalyCount} titik anomali** fluktuatif dengan status risiko dataset **{$riskLevel}**.\n" .
            "4. **Proyeksi Masa Depan (OLS 95% CI):** Model Ordinary Least Squares memproyeksikan estimasi periode berikutnya sebesar **{$nextTarget}** dengan rentang interval kepercayaan 95% antara **{$lower95}** hingga **{$upper95}**.\n" .
            "5. **Rekomendasi Strategis:** Pantau secara ketat titik data dengan deviasi ekstrem untuk memitigasi risiko volatilitas dan pastikan stabilitas operasional pada koridor kepercayaan terprediksi.";

        if (!$includeAi) {
            return $deterministicSummary;
        }

        // Try AI generation if provider is configured
        try {
            $contextBuilder = new AIContextBuilder($this->db, $this->analyticsEngine);
            $facts = $contextBuilder->buildFactsMarkdown((int) $dataset['id']);
            $promptManager = new PromptManager();
            $prompt = $promptManager->buildQuickActionPrompt('executive_summary', $facts);

            $aiManager = AIManager::getInstance();
            $response = $aiManager->generateWithFallback([
                ['role' => 'system', 'content' => $prompt['system']],
                ['role' => 'user', 'content' => $prompt['user']],
            ]);

            if (!empty($response['content'])) {
                return trim($response['content']);
            }
        } catch (\Throwable $e) {
            // Gracefully fallback to deterministic summary
        }

        return $deterministicSummary;
    }

    /**
     * Master Orchestrator: Generate and persist report.
     */
    public function buildAndSave(int $userId, int $datasetId, string $format, array $options = []): array
    {
        $data = $this->compileReportData($datasetId, $options['metric_name'] ?? null, $options['date_start'] ?? null, $options['date_end'] ?? null, $options);
        $title = $data['title'];
        $format = strtolower(trim($format));
        $createdReports = [];

        $formatsToGenerate = ($format === 'all') 
            ? ['html', 'pdf', 'xlsx', 'csv'] 
            : [$format];

        foreach ($formatsToGenerate as $fmt) {
            $result = match ($fmt) {
                'html' => $this->generateHtml($data, $options['filename'] ?? null),
                'pdf' => $this->generatePdf($data, $options['filename'] ?? null),
                'xlsx' => $this->generateXlsx($data, $options['filename'] ?? null),
                'csv' => $this->generateCsv($data, $options['filename'] ?? null),
                default => throw new RuntimeException("Format laporan [{$fmt}] tidak didukung."),
            };

            $reportId = Report::create([
                'user_id' => $userId,
                'title' => $title,
                'report_type' => $fmt,
                'file_path' => $result['file_path'],
                'file_size_bytes' => $result['file_size'],
                'date_range_start' => $data['date_range']['start'],
                'date_range_end' => $data['date_range']['end'],
                'summary_content' => $data['ai_summary'],
                'status' => 'generated',
                'created_at' => date('c'),
            ]);

            $createdReports[] = array_merge($result, [
                'id' => $reportId,
                'title' => $title,
                'report_type' => $fmt,
            ]);
        }

        return $createdReports;
    }

    /**
     * Generate HTML Executive Report.
     */
    public function generateHtml(array $data, ?string $filename = null): array
    {
        $safeName = $filename ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $filename) : 'anabot_report_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $fullPath = $this->storagePath . '/' . $safeName . '.html';

        $title = e($data['title']);
        $datasetName = e($data['dataset']['name'] ?? 'Dataset');
        $metric = e($data['metric_name']);
        $generatedAt = e($data['generated_at']);
        $stats = $data['descriptive_stats'];
        $anomalies = $data['anomalies'];
        $forecast = $data['forecast'];
        $riskLevel = $anomalies['risk_level'] ?? 'LOW';
        $riskColor = match ($riskLevel) {
            'CRITICAL' => '#ef4444',
            'MODERATE' => '#f59e0b',
            default => '#10b981',
        };

        // Render SVG Line Chart
        $svgChart = $this->renderSvgChart($data);

        // Convert markdown paragraphs in AI summary to clean HTML
        $aiSummaryHtml = nl2br(e($data['ai_summary']));
        $aiSummaryHtml = preg_replace('/### (.*?)\n/m', '<h3 style="color:#38bdf8;margin:1rem 0 0.5rem 0;">$1</h3>', $aiSummaryHtml);
        $aiSummaryHtml = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $aiSummaryHtml);

        $html = '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . ' - AnaBot</title>
    <style>
        :root {
            --bg: #0b1120;
            --surface: #1e293b;
            --border: #334155;
            --text: #f8fafc;
            --muted: #94a3b8;
            --primary: #0284c7;
            --accent: #38bdf8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 2rem;
            line-height: 1.6;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid var(--border);
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .brand-badge {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: linear-gradient(135deg, #0284c7, #38bdf8);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 800;
            color: #fff;
        }
        .header-title h1 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--text);
        }
        .header-title p {
            margin: 4px 0 0 0;
            font-size: 0.85rem;
            color: var(--muted);
        }
        .meta-badge {
            text-align: right;
            font-size: 0.8rem;
            color: var(--muted);
        }
        .badge-confidential {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 4px;
        }
        .bento-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .bento-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.25rem;
        }
        .bento-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            margin-bottom: 0.5rem;
        }
        .bento-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text);
        }
        .bento-sub {
            font-size: 0.8rem;
            color: var(--muted);
            margin-top: 4px;
        }
        .section-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .section-title {
            font-size: 1.15rem;
            font-weight: 700;
            margin-top: 0;
            margin-bottom: 1rem;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ai-box {
            background: rgba(2, 132, 199, 0.08);
            border-left: 4px solid var(--primary);
            padding: 1.25rem;
            border-radius: 0 8px 8px 0;
            font-size: 0.95rem;
            line-height: 1.7;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            margin-top: 1rem;
        }
        th, td {
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th {
            background: rgba(15, 23, 42, 0.6);
            color: var(--muted);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
        }
        tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        .tag {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .tag-critical { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .tag-warning { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .tag-success { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .disclaimer-box {
            background: rgba(245, 158, 11, 0.08);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 8px;
            padding: 1rem;
            font-size: 0.8rem;
            color: #fbbf24;
            margin-top: 2rem;
            line-height: 1.5;
        }
        .footer {
            margin-top: 3rem;
            text-align: center;
            font-size: 0.8rem;
            color: var(--muted);
            border-top: 1px solid var(--border);
            padding-top: 1.5rem;
        }
        .print-btn {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 1.5rem;
        }
        @media print {
            body { background: #fff !important; color: #000 !important; padding: 0 !important; }
            .section-card, .bento-card { background: #fff !important; border: 1px solid #ccc !important; color: #000 !important; }
            .print-btn { display: none !important; }
            th { background: #eee !important; color: #000 !important; }
            .ai-box { background: #f8fafc !important; color: #000 !important; border-left-color: #0284c7 !important; }
            .bento-value, .bento-label, .section-title, .header-title h1 { color: #000 !important; }
        }
    </style>
</head>
<body>
    <div class="container">
        <button class="print-btn" onclick="window.print()">🖨️ Cetak / Simpan sebagai PDF</button>

        <header class="header">
            <div class="brand">
                <div class="brand-badge">A</div>
                <div class="header-title">
                    <h1>' . $title . '</h1>
                    <p>Dataset: <strong>' . $datasetName . '</strong> &bull; Metrik: <strong>' . $metric . '</strong></p>
                </div>
            </div>
            <div class="meta-badge">
                <div class="badge-confidential">DOKUMEN RAHASIA</div>
                <div>Digenerate: ' . $generatedAt . '</div>
            </div>
        </header>

        <!-- Bento KPI Summary -->
        <div class="bento-grid">
            <div class="bento-card">
                <div class="bento-label">Rata-Rata (Mean)</div>
                <div class="bento-value">' . number_format((float) ($stats['mean'] ?? 0), 2) . '</div>
                <div class="bento-sub">Median: ' . number_format((float) ($stats['median'] ?? 0), 2) . ' | StdDev: ' . number_format((float) ($stats['std_dev'] ?? 0), 2) . '</div>
            </div>
            <div class="bento-card">
                <div class="bento-label">Arah Tren (OLS)</div>
                <div class="bento-value">' . e($data['trend']['trend_direction'] ?? 'Sideways') . '</div>
                <div class="bento-sub">Slope: ' . number_format((float) ($data['trend']['slope'] ?? 0), 4) . ' | R²: ' . number_format((float) ($data['trend']['r_squared'] ?? 0), 4) . '</div>
            </div>
            <div class="bento-card">
                <div class="bento-label">Tingkat Risiko Anomali</div>
                <div class="bento-value" style="color:' . $riskColor . ';">' . e($riskLevel) . '</div>
                <div class="bento-sub">' . (int) ($anomalies['anomaly_count'] ?? 0) . ' Anomali dari ' . (int) ($data['total_points'] ?? 0) . ' titik data</div>
            </div>
            <div class="bento-card">
                <div class="bento-label">Target Proyeksi (+1)</div>
                <div class="bento-value" style="color:var(--accent);">' . (!empty($forecast['predictions'][0]['predicted_value']) ? number_format((float) $forecast['predictions'][0]['predicted_value'], 2) : 'N/A') . '</div>
                <div class="bento-sub">95% CI: [' . (!empty($forecast['predictions'][0]['lower_bound_95']) ? number_format((float) $forecast['predictions'][0]['lower_bound_95'], 2) : '0') . ' - ' . (!empty($forecast['predictions'][0]['upper_bound_95']) ? number_format((float) $forecast['predictions'][0]['upper_bound_95'], 2) : '0') . ']</div>
            </div>
        </div>

        <!-- AI Executive Briefing -->
        <div class="section-card">
            <h2 class="section-title">🤖 Ringkasan Eksekutif & Rekomendasi Tindakan</h2>
            <div class="ai-box">' . $aiSummaryHtml . '</div>
        </div>

        <!-- Visual Trend & Anomaly Chart -->
        <div class="section-card">
            <h2 class="section-title">📈 Visualisasi Deret Waktu, Anomali & Proyeksi</h2>
            ' . $svgChart . '
        </div>

        <!-- Descriptive Statistics Table -->
        <div class="section-card">
            <h2 class="section-title">📊 Rincian Statistik Deskriptif</h2>
            <table>
                <thead>
                    <tr>
                        <th>Parameter Statistik</th>
                        <th>Nilai Perhitungan</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Jumlah Observasi (N)</td><td>' . (int) ($stats['count'] ?? 0) . '</td><td>Total titik data valid</td></tr>
                    <tr><td>Rata-Rata (Mean)</td><td>' . number_format((float) ($stats['mean'] ?? 0), 4) . '</td><td>Nilai tendensi sentral</td></tr>
                    <tr><td>Median (Q2)</td><td>' . number_format((float) ($stats['median'] ?? 0), 4) . '</td><td>Titik tengah persentil ke-50</td></tr>
                    <tr><td>Nilai Minimum</td><td>' . number_format((float) ($stats['min'] ?? 0), 4) . '</td><td>Batas terendah</td></tr>
                    <tr><td>Nilai Maksimum</td><td>' . number_format((float) ($stats['max'] ?? 0), 4) . '</td><td>Batas tertinggi</td></tr>
                    <tr><td>Standar Deviasi (Sample s)</td><td>' . number_format((float) ($stats['std_dev'] ?? 0), 4) . '</td><td>Tingkat sebaran data (n-1)</td></tr>
                    <tr><td>Interquartile Range (IQR)</td><td>' . number_format((float) ($stats['iqr'] ?? 0), 4) . '</td><td>Rentang Q3 - Q1 (Pagar Tukey)</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Anomalies Table -->
        <div class="section-card">
            <h2 class="section-title">🚨 Deteksi Anomali Signifikan</h2>';

        if (empty($anomalies['anomalies'])) {
            $html .= '<p style="color:var(--muted);font-style:italic;">Tidak ditemukan anomali fluktuasi signifikan pada dataset ini.</p>';
        } else {
            $html .= '<table>
                <thead>
                    <tr>
                        <th>Indeks/Tanggal</th>
                        <th>Nilai Aktual</th>
                        <th>Z-Score</th>
                        <th>Tipe Anomali</th>
                        <th>Tingkat Keparahan</th>
                    </tr>
                </thead>
                <tbody>';
            $displayed = array_slice($anomalies['anomalies'], 0, 15);
            foreach ($displayed as $a) {
                $sev = $a['severity'] ?? 'warning';
                $tagClass = ($sev === 'critical') ? 'tag-critical' : 'tag-warning';
                $desc = !empty($a['reasons']) ? implode('; ', (array) $a['reasons']) : ($a['description'] ?? ($a['iqr_type'] ?? ($a['type'] ?? 'Outlier')));
                $period = $a['period'] ?? ($a['date'] ?? ('#' . ($a['index'] ?? 0)));
                $html .= '<tr>
                    <td>' . e((string) $period) . '</td>
                    <td><strong>' . number_format((float) ($a['value'] ?? 0), 2) . '</strong></td>
                    <td>' . (isset($a['z_score']) ? number_format((float) $a['z_score'], 2) : '-') . '</td>
                    <td>' . e((string) $desc) . '</td>
                    <td><span class="tag ' . $tagClass . '">' . strtoupper(e($sev)) . '</span></td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</div>

        <!-- Forecast Table -->
        <div class="section-card">
            <h2 class="section-title">🔮 Tabel Proyeksi Peramalan Masa Depan</h2>
            <table>
                <thead>
                    <tr>
                        <th>Langkah Horizon</th>
                        <th>Nilai Proyeksi</th>
                        <th>Batas Bawah 95% CI</th>
                        <th>Batas Atas 95% CI</th>
                    </tr>
                </thead>
                <tbody>';
        if (!empty($forecast['predictions'])) {
            foreach ($forecast['predictions'] as $pred) {
                $html .= '<tr>
                    <td>+' . (int) $pred['step'] . ' Periode</td>
                    <td><strong style="color:var(--accent);">' . number_format((float) $pred['predicted_value'], 2) . '</strong></td>
                    <td>' . number_format((float) $pred['lower_bound_95'], 2) . '</td>
                    <td>' . number_format((float) $pred['upper_bound_95'], 2) . '</td>
                </tr>';
            }
        }
        $html .= '</tbody>
            </table>
        </div>

        <!-- Strict Assumption Disclaimer -->
        <div class="disclaimer-box">
            <strong>⚠️ KLAUSUL ASUMSI MATEMATIS (DISCLAIMER):</strong><br>
            ' . e($forecast['assumption_disclaimer'] ?? 'Proyeksi ini dihasilkan secara matematis murni menggunakan model historis dan mengasumsikan pola masa lalu berlanjut. Angka ini adalah estimasi probabilitas bersyarat, bukan jaminan masa depan.') . '
        </div>

        <footer class="footer">
            <div>AnaBot &bull; AI Analytics Robot &bull; Dokumen Rahasia &bull; Dibuat secara otomatis tanpa rekayasa data</div>
        </footer>
    </div>
</body>
</html>';

        file_put_contents($fullPath, $html);
        return [
            'file_path' => $fullPath,
            'file_size' => filesize($fullPath),
            'filename' => basename($fullPath),
            'mime_type' => 'text/html',
        ];
    }

    /**
     * Render embedded standalone SVG line chart for HTML and preview.
     */
    private function renderSvgChart(array $data): string
    {
        $timeSeries = $data['growth']['time_series'] ?? [];
        if (empty($timeSeries)) {
            return '<div style="padding:2rem;text-align:center;color:#94a3b8;">Tidak ada deret waktu untuk grafik.</div>';
        }

        $forecastPts = $data['forecast']['predictions'] ?? [];
        $anomalies = $data['anomalies']['anomalies'] ?? [];
        $anomalyIndices = [];
        foreach ($anomalies as $anom) {
            if (isset($anom['index'])) {
                $anomalyIndices[(int) $anom['index']] = $anom;
            }
        }

        $allValues = array_column($timeSeries, 'value');
        foreach ($forecastPts as $fp) {
            $allValues[] = $fp['predicted_value'];
            if (isset($fp['upper_bound_95'])) $allValues[] = $fp['upper_bound_95'];
            if (isset($fp['lower_bound_95'])) $allValues[] = $fp['lower_bound_95'];
        }

        $minVal = !empty($allValues) ? min($allValues) : 0;
        $maxVal = !empty($allValues) ? max($allValues) : 100;
        $range = max(1.0, $maxVal - $minVal);

        $width = 900;
        $height = 280;
        $padX = 40;
        $padY = 30;
        $plotW = $width - ($padX * 2);
        $plotH = $height - ($padY * 2);

        $totalPoints = count($timeSeries) + count($forecastPts);
        $stepX = $plotW / max(1, $totalPoints - 1);

        // Historical Path
        $histPath = '';
        $dots = '';
        foreach ($timeSeries as $i => $pt) {
            $val = (float) $pt['value'];
            $x = $padX + ($i * $stepX);
            $y = $height - $padY - (($val - $minVal) / $range * $plotH);

            if ($i === 0) {
                $histPath .= "M {$x} {$y}";
            } else {
                $histPath .= " L {$x} {$y}";
            }

            // Anomaly point
            if (isset($anomalyIndices[$i])) {
                $dots .= "<circle cx='{$x}' cy='{$y}' r='5' fill='#ef4444' stroke='#fff' stroke-width='1.5'><title>Anomali: " . e((string) $val) . "</title></circle>";
            }
        }

        // Forecast Path & Shaded 95% Confidence Band
        $forecastPath = '';
        $upperPath = '';
        $lowerPath = '';
        $lastHistX = $padX + ((count($timeSeries) - 1) * $stepX);
        $lastHistVal = !empty($timeSeries) ? (float) end($timeSeries)['value'] : 0;
        $lastHistY = $height - $padY - (($lastHistVal - $minVal) / $range * $plotH);

        if (!empty($forecastPts)) {
            $forecastPath .= "M {$lastHistX} {$lastHistY}";
            $upperCoords = [];
            $lowerCoords = [];

            foreach ($forecastPts as $j => $fp) {
                $idx = count($timeSeries) + $j;
                $fx = $padX + ($idx * $stepX);
                $fy = $height - $padY - (((float) $fp['predicted_value'] - $minVal) / $range * $plotH);
                $forecastPath .= " L {$fx} {$fy}";

                $uy = $height - $padY - (((float) $fp['upper_bound_95'] - $minVal) / $range * $plotH);
                $ly = $height - $padY - (((float) $fp['lower_bound_95'] - $minVal) / $range * $plotH);
                $upperCoords[] = "{$fx} {$uy}";
                $lowerCoords[] = "{$fx} {$ly}";
            }

            // Build confidence band polygon
            $reversedLower = array_reverse($lowerCoords);
            $bandPoly = "M {$lastHistX} {$lastHistY} L " . implode(' L ', $upperCoords) . ' L ' . implode(' L ', $reversedLower) . ' Z';
        } else {
            $bandPoly = '';
        }

        $svg = "<svg viewBox='0 0 {$width} {$height}' style='width:100%;height:auto;background:#0f172a;border-radius:8px;'>";
        // Grid lines
        for ($g = 0; $g <= 4; $g++) {
            $gy = $padY + ($g * ($plotH / 4));
            $gval = $maxVal - ($g * ($range / 4));
            $svg .= "<line x1='{$padX}' y1='{$gy}' x2='" . ($width - $padX) . "' y2='{$gy}' stroke='#334155' stroke-dasharray='3 3' stroke-width='0.5'/>";
            $svg .= "<text x='" . ($padX - 8) . "' y='" . ($gy + 4) . "' fill='#64748b' font-size='10' text-anchor='end'>" . number_format($gval, 1) . "</text>";
        }

        // Confidence band
        if (!empty($bandPoly)) {
            $svg .= "<path d='{$bandPoly}' fill='rgba(16, 185, 129, 0.12)' />";
        }

        // Historical line
        $svg .= "<path d='{$histPath}' fill='none' stroke='#0284c7' stroke-width='2.5' />";

        // Forecast dashed line
        if (!empty($forecastPath)) {
            $svg .= "<path d='{$forecastPath}' fill='none' stroke='#10b981' stroke-width='2.5' stroke-dasharray='5 4' />";
        }

        // Anomaly dots
        $svg .= $dots;

        $svg .= "</svg>";
        return $svg;
    }

    /**
     * Generate UTF-8 BOM CSV Report.
     */
    public function generateCsv(array $data, ?string $filename = null): array
    {
        $safeName = $filename ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $filename) : 'anabot_report_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $fullPath = $this->storagePath . '/' . $safeName . '.csv';

        $fp = fopen($fullPath, 'w');
        if (!$fp) {
            throw new RuntimeException("Gagal membuka file tujuan CSV di [{$fullPath}].");
        }

        // Write UTF-8 BOM for Microsoft Excel compatibility
        fwrite($fp, "\xEF\xBB\xBF");

        // Section 1: Report Metadata
        $this->writeCsvRow($fp, ['# ANABOT EXECUTIVE PERFORMANCE REPORT']);
        $this->writeCsvRow($fp, ['Judul Laporan', $data['title']]);
        $this->writeCsvRow($fp, ['Dataset', $data['dataset']['name'] ?? 'Dataset']);
        $this->writeCsvRow($fp, ['Metrik Target', $data['metric_name']]);
        $this->writeCsvRow($fp, ['Waktu Pembuatan', $data['generated_at']]);
        $this->writeCsvRow($fp, ['Rentang Data', ($data['date_range']['start'] ?? '-') . ' s/d ' . ($data['date_range']['end'] ?? '-')]);
        $this->writeCsvRow($fp, []);

        // Section 2: Key Indicators
        $stats = $data['descriptive_stats'];
        $this->writeCsvRow($fp, ['# RINGKASAN INDIKATOR UTAMA']);
        $this->writeCsvRow($fp, ['Indikator', 'Nilai']);
        $this->writeCsvRow($fp, ['Total Observasi (N)', $stats['count'] ?? 0]);
        $this->writeCsvRow($fp, ['Rata-Rata (Mean)', $stats['mean'] ?? 0]);
        $this->writeCsvRow($fp, ['Median (Q2)', $stats['median'] ?? 0]);
        $this->writeCsvRow($fp, ['Minimum', $stats['min'] ?? 0]);
        $this->writeCsvRow($fp, ['Maksimum', $stats['max'] ?? 0]);
        $this->writeCsvRow($fp, ['Standar Deviasi', $stats['std_dev'] ?? 0]);
        $this->writeCsvRow($fp, ['Interquartile Range (IQR)', $stats['iqr'] ?? 0]);
        $this->writeCsvRow($fp, ['Arah Tren OLS', $data['trend']['trend_direction'] ?? 'Sideways']);
        $this->writeCsvRow($fp, ['Kemiringan Tren (Slope)', $data['trend']['slope'] ?? 0]);
        $this->writeCsvRow($fp, ['Koefisien Determinasi (R2)', $data['trend']['r_squared'] ?? 0]);
        $this->writeCsvRow($fp, ['Tingkat Risiko Anomali', $data['anomalies']['risk_level'] ?? 'LOW']);
        $this->writeCsvRow($fp, ['Total Titik Anomali', $data['anomalies']['anomaly_count'] ?? 0]);
        $this->writeCsvRow($fp, []);

        // Section 3: Anomalies List
        $this->writeCsvRow($fp, ['# DAFTAR ANOMALI TERDETEKSI']);
        $this->writeCsvRow($fp, ['Indeks / Tanggal', 'Nilai Aktual', 'Z-Score', 'Tipe Anomali', 'Tingkat Keparahan']);
        if (!empty($data['anomalies']['anomalies'])) {
            foreach ($data['anomalies']['anomalies'] as $a) {
                $desc = !empty($a['reasons']) ? implode('; ', (array) $a['reasons']) : ($a['description'] ?? ($a['iqr_type'] ?? ($a['type'] ?? 'Outlier')));
                $period = $a['period'] ?? ($a['date'] ?? ('#' . ($a['index'] ?? 0)));
                $this->writeCsvRow($fp, [
                    $period,
                    $a['value'] ?? 0,
                    $a['z_score'] ?? '-',
                    $desc,
                    $a['severity'] ?? 'warning',
                ]);
            }
        } else {
            $this->writeCsvRow($fp, ['Tidak ada anomali terdeteksi']);
        }
        $this->writeCsvRow($fp, []);

        // Section 4: Forecast Projections
        $this->writeCsvRow($fp, ['# PROYEKSI PERAMALAN MASA DEPAN (OLS + 95% CONFIDENCE INTERVAL)']);
        $this->writeCsvRow($fp, ['Langkah Horizon', 'Nilai Proyeksi', 'Batas Bawah 95%', 'Batas Atas 95%']);
        if (!empty($data['forecast']['predictions'])) {
            foreach ($data['forecast']['predictions'] as $pred) {
                $this->writeCsvRow($fp, [
                    '+' . $pred['step'] . ' Periode',
                    $pred['predicted_value'],
                    $pred['lower_bound_95'],
                    $pred['upper_bound_95'],
                ]);
            }
        }
        $this->writeCsvRow($fp, ['Disclaimer', $data['forecast']['assumption_disclaimer'] ?? 'Proyeksi matematis murni.']);
        $this->writeCsvRow($fp, []);

        // Section 5: Historical Data Points
        $this->writeCsvRow($fp, ['# DATA HISTORIS']);
        $this->writeCsvRow($fp, ['Tanggal / Urutan', 'Nilai']);
        $timeSeries = $data['growth']['time_series'] ?? [];
        foreach ($timeSeries as $ts) {
            $this->writeCsvRow($fp, [$ts['date'] ?? '-', $ts['value'] ?? 0]);
        }

        fclose($fp);

        return [
            'file_path' => $fullPath,
            'file_size' => filesize($fullPath),
            'filename' => basename($fullPath),
            'mime_type' => 'text/csv',
        ];
    }

    /**
     * Memory-safe CSV row writer with explicit escape parameter (PHP 8.4 compliant).
     */
    private function writeCsvRow($fp, array $fields): void
    {
        fputcsv($fp, $fields, ',', '"', '\\');
    }

    /**
     * Generate Genuine OpenXML (.xlsx) Multi-Sheet Workbook using ZipArchive.
     */
    public function generateXlsx(array $data, ?string $filename = null): array
    {
        $safeName = $filename ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $filename) : 'anabot_report_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $fullPath = $this->storagePath . '/' . $safeName . '.xlsx';

        $zip = new ZipArchive();
        if ($zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Gagal menginisialisasi berkas XLSX ZipArchive pada [{$fullPath}].");
        }

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>
  <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/>
  <Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/workbook.xml
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Ringkasan Eksekutif" sheetId="1" r:id="rId1"/>
    <sheet name="Statistik Deskriptif" sheetId="2" r:id="rId2"/>
    <sheet name="Deteksi Anomali" sheetId="3" r:id="rId3"/>
    <sheet name="Proyeksi Peramalan" sheetId="4" r:id="rId4"/>
  </sheets>
</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // 5. xl/styles.xml
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><name val="Calibri"/><sz val="11"/></font>
    <font><b/><name val="Calibri"/><sz val="11"/><color rgb="FFFFFFFF"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill>
  </fills>
  <borders count="1">
    <border><left/><right/><top/><bottom/></border>
  </borders>
  <cellStyleXfs count="1"><xf/></cellStyleXfs>
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
  </cellXfs>
</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        // 6. Sheet 1: Ringkasan Eksekutif
        $sheet1Data = [
            ['ANABOT EXECUTIVE PERFORMANCE REPORT', ''],
            ['Judul Laporan', $data['title']],
            ['Dataset', $data['dataset']['name'] ?? 'Dataset'],
            ['Metrik Target', $data['metric_name']],
            ['Waktu Pembuatan', $data['generated_at']],
            ['Rentang Data', ($data['date_range']['start'] ?? '-') . ' s/d ' . ($data['date_range']['end'] ?? '-')],
            ['', ''],
            ['INDIKATOR KUNCI', 'NILAI'],
            ['Total Observasi', (string) ($data['descriptive_stats']['count'] ?? 0)],
            ['Rata-Rata (Mean)', (string) round((float) ($data['descriptive_stats']['mean'] ?? 0), 4)],
            ['Median', (string) round((float) ($data['descriptive_stats']['median'] ?? 0), 4)],
            ['Arah Tren (OLS)', (string) ($data['trend']['trend_direction'] ?? 'Sideways')],
            ['Tingkat Risiko Anomali', (string) ($data['anomalies']['risk_level'] ?? 'LOW')],
            ['Target Proyeksi (+1)', (string) round((float) ($data['forecast']['predictions'][0]['predicted_value'] ?? 0), 4)],
            ['', ''],
            ['RINGKASAN EKSEKUTIF AI', ''],
            [strip_tags(str_replace("\n", " ", $data['ai_summary'])), ''],
        ];
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->buildWorksheetXml($sheet1Data));

        // 7. Sheet 2: Statistik Deskriptif
        $stats = $data['descriptive_stats'];
        $sheet2Data = [
            ['PARAMETER STATISTIK', 'NILAI'],
            ['Observasi Valid (N)', (string) ($stats['count'] ?? 0)],
            ['Rata-Rata (Mean)', (string) round((float) ($stats['mean'] ?? 0), 4)],
            ['Median (Q2)', (string) round((float) ($stats['median'] ?? 0), 4)],
            ['Kuartil Bawah (Q1)', (string) round((float) ($stats['q1'] ?? 0), 4)],
            ['Kuartil Atas (Q3)', (string) round((float) ($stats['q3'] ?? 0), 4)],
            ['Interquartile Range (IQR)', (string) round((float) ($stats['iqr'] ?? 0), 4)],
            ['Nilai Minimum', (string) round((float) ($stats['min'] ?? 0), 4)],
            ['Nilai Maksimum', (string) round((float) ($stats['max'] ?? 0), 4)],
            ['Varians Sampel', (string) round((float) ($stats['variance'] ?? 0), 4)],
            ['Standar Deviasi Sampel', (string) round((float) ($stats['std_dev'] ?? 0), 4)],
            ['Trend Slope', (string) round((float) ($data['trend']['slope'] ?? 0), 6)],
            ['Koefisien Determinasi R2', (string) round((float) ($data['trend']['r_squared'] ?? 0), 4)],
        ];
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->buildWorksheetXml($sheet2Data));

        // 8. Sheet 3: Deteksi Anomali
        $sheet3Data = [
            ['TANGGAL / INDEKS', 'NILAI AKTUAL', 'Z-SCORE', 'TIPE ANOMALI', 'KEPARAHAN'],
        ];
        if (!empty($data['anomalies']['anomalies'])) {
            foreach ($data['anomalies']['anomalies'] as $a) {
                $desc = !empty($a['reasons']) ? implode('; ', (array) $a['reasons']) : ($a['description'] ?? ($a['iqr_type'] ?? ($a['type'] ?? 'Outlier')));
                $period = $a['period'] ?? ($a['date'] ?? ('#' . ($a['index'] ?? 0)));
                $sheet3Data[] = [
                    (string) $period,
                    (string) ($a['value'] ?? 0),
                    (string) (isset($a['z_score']) ? round((float) $a['z_score'], 2) : '-'),
                    (string) $desc,
                    (string) strtoupper($a['severity'] ?? 'warning'),
                ];
            }
        } else {
            $sheet3Data[] = ['Tidak ada anomali terdeteksi', '-', '-', '-', '-'];
        }
        $zip->addFromString('xl/worksheets/sheet3.xml', $this->buildWorksheetXml($sheet3Data));

        // 9. Sheet 4: Proyeksi Peramalan
        $sheet4Data = [
            ['HORIZON PERIODE', 'NILAI PROYEKSI', 'BATAS BAWAH 95%', 'BATAS ATAS 95%'],
        ];
        if (!empty($data['forecast']['predictions'])) {
            foreach ($data['forecast']['predictions'] as $pred) {
                $sheet4Data[] = [
                    '+' . $pred['step'] . ' Periode',
                    (string) round((float) $pred['predicted_value'], 4),
                    (string) round((float) $pred['lower_bound_95'], 4),
                    (string) round((float) $pred['upper_bound_95'], 4),
                ];
            }
        }
        $sheet4Data[] = ['', '', '', ''];
        $sheet4Data[] = ['DISCLAIMER MATEMATIS', $data['forecast']['assumption_disclaimer'] ?? 'Proyeksi probabilitas matematis murni.', '', ''];
        $zip->addFromString('xl/worksheets/sheet4.xml', $this->buildWorksheetXml($sheet4Data));

        $zip->close();

        return [
            'file_path' => $fullPath,
            'file_size' => filesize($fullPath),
            'filename' => basename($fullPath),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * Helper to build OpenXML worksheet XML with inline strings and numbers.
     */
    private function buildWorksheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
        $xml .= '<sheetData>' . "\n";

        $colLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

        foreach ($rows as $rIdx => $row) {
            $rowNum = $rIdx + 1;
            $xml .= "<row r=\"{$rowNum}\">\n";

            foreach ($row as $cIdx => $cellVal) {
                $colLetter = $colLetters[$cIdx] ?? 'A';
                $cellRef = "{$colLetter}{$rowNum}";
                $styleAttr = ($rIdx === 0) ? ' s="1"' : '';

                if (is_numeric($cellVal) && !str_starts_with((string) $cellVal, '+') && !str_starts_with((string) $cellVal, '#')) {
                    $xml .= "<c r=\"{$cellRef}\"{$styleAttr}><v>{$cellVal}</v></c>\n";
                } else {
                    $escaped = htmlspecialchars((string) $cellVal, ENT_XML1, 'UTF-8');
                    $xml .= "<c r=\"{$cellRef}\" t=\"inlineStr\"{$styleAttr}><is><t>{$escaped}</t></is></c>\n";
                }
            }

            $xml .= "</row>\n";
        }

        $xml .= '</sheetData>' . "\n";
        $xml .= '</worksheet>';
        return $xml;
    }

    /**
     * Generate Genuine Standard PDF 1.4 Binary File via Pure PHP.
     */
    public function generatePdf(array $data, ?string $filename = null): array
    {
        $safeName = $filename ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $filename) : 'anabot_report_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $fullPath = $this->storagePath . '/' . $safeName . '.pdf';

        $title = $data['title'];
        $datasetName = $data['dataset']['name'] ?? 'Dataset';
        $metric = $data['metric_name'];
        $stats = $data['descriptive_stats'];
        $anomalies = $data['anomalies'];
        $forecast = $data['forecast'];
        $generatedAt = $data['generated_at'];
        $riskLevel = $anomalies['risk_level'] ?? 'LOW';

        $objects = [];
        $offsets = [];
        $buffer = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";

        $addObj = function ($content) use (&$buffer, &$offsets, &$objects): int {
            $id = count($objects) + 1;
            $offsets[$id] = strlen($buffer);
            $obj = "{$id} 0 obj\n{$content}\nendobj\n";
            $buffer .= $obj;
            $objects[$id] = $obj;
            return $id;
        };

        // Construct PDF Content Stream
        $stream = "";

        // Background White Page
        $stream .= "1 1 1 rg 0 0 595.28 841.89 re f\n";

        // Top Header Banner (Dark Navy #0f172a: 15/255, 23/255, 42/255)
        $stream .= "0.06 0.09 0.16 rg 0 765 595.28 77 re f\n";

        // Brand Badge (Cyan Accent)
        $stream .= "0.01 0.52 0.78 rg 40 782 36 36 re f\n";
        $stream .= "BT /F2 20 Tf 1 1 1 rg 51 794 Td (A) Tj ET\n";

        // Brand Header Text
        $stream .= "BT /F2 16 Tf 1 1 1 rg 88 806 Td (ANABOT) Tj ET\n";
        $stream .= "BT /F1 9 Tf 0.58 0.64 0.72 rg 88 792 Td (AI Analytics Robot - Executive Data Report) Tj ET\n";

        // Meta Badge right
        $stream .= "BT /F2 8 Tf 0.94 0.27 0.27 rg 460 806 Td (DOKUMEN RAHASIA) Tj ET\n";
        $stream .= "BT /F1 8 Tf 0.58 0.64 0.72 rg 450 792 Td (" . $this->pdfEscape($generatedAt) . ") Tj ET\n";

        // Report Title Bar
        $stream .= "BT /F2 14 Tf 0.06 0.09 0.16 rg 40 735 Td (" . $this->pdfEscape($title) . ") Tj ET\n";
        $stream .= "BT /F1 9 Tf 0.4 0.45 0.52 rg 40 720 Td (Dataset: " . $this->pdfEscape($datasetName) . "  |  Metrik: " . $this->pdfEscape($metric) . ") Tj ET\n";
        $stream .= "0.8 0.83 0.88 RG 1 w 40 710 m 555 710 l S\n";

        // 4 Bento KPI Metric Boxes
        $kpis = [
            ['RATA-RATA', number_format((float) ($stats['mean'] ?? 0), 2), 'Median: ' . number_format((float) ($stats['median'] ?? 0), 2)],
            ['TREN (OLS)', $data['trend']['trend_direction'] ?? 'Sideways', 'Slope: ' . number_format((float) ($data['trend']['slope'] ?? 0), 4)],
            ['RISIKO ANOMALI', $riskLevel, $anomalies['anomaly_count'] . ' Anomali dari ' . $data['total_points'] . ' Pts'],
            ['TARGET PROYEKSI', !empty($forecast['predictions'][0]['predicted_value']) ? number_format((float) $forecast['predictions'][0]['predicted_value'], 2) : 'N/A', '95% CI Terhitung'],
        ];

        for ($k = 0; $k < 4; $k++) {
            $kx = 40 + ($k * 132);
            $stream .= "0.96 0.97 0.98 rg {$kx} 645 122 55 re f\n";
            $stream .= "0.85 0.88 0.92 RG 1 w {$kx} 645 122 55 re S\n";
            $stream .= "BT /F2 7 Tf 0.4 0.45 0.52 rg " . ($kx + 8) . " 685 Td (" . $kpis[$k][0] . ") Tj ET\n";
            $stream .= "BT /F2 12 Tf 0.06 0.09 0.16 rg " . ($kx + 8) . " 668 Td (" . $this->pdfEscape($kpis[$k][1]) . ") Tj ET\n";
            $stream .= "BT /F1 7 Tf 0.4 0.45 0.52 rg " . ($kx + 8) . " 654 Td (" . $this->pdfEscape($kpis[$k][2]) . ") Tj ET\n";
        }

        // Section: AI Executive Briefing Callout Box
        $stream .= "0.93 0.97 1.0 rg 40 540 515 90 re f\n";
        $stream .= "0.01 0.52 0.78 rg 40 540 4 90 re f\n";
        $stream .= "BT /F2 10 Tf 0.01 0.52 0.78 rg 52 615 Td (RINGKASAN EKSEKUTIF & REKOMENDASI AI) Tj ET\n";

        // Wrap AI Summary Text lines
        $cleanAi = strip_tags(str_replace(["\r", "### "], ["", ""], $data['ai_summary']));
        $summaryLines = explode("\n", wordwrap($cleanAi, 105, "\n"));
        $maxLines = min(4, count($summaryLines));
        $sy = 600;
        for ($l = 0; $l < $maxLines; $l++) {
            $stream .= "BT /F1 8.5 Tf 0.12 0.16 0.22 rg 52 {$sy} Td (" . $this->pdfEscape($summaryLines[$l]) . ") Tj ET\n";
            $sy -= 14;
        }

        // Section: Descriptive Stats & Forecast Tables (Side-by-Side or Stacked)
        // Table 1: Parameter Statistik
        $stream .= "BT /F2 10 Tf 0.06 0.09 0.16 rg 40 515 Td (Rincian Statistik Deskriptif) Tj ET\n";
        $stream .= "0.12 0.16 0.22 rg 40 495 250 16 re f\n";
        $stream .= "BT /F2 8 Tf 1 1 1 rg 46 500 Td (Parameter) Tj 180 500 Td (Nilai) Tj ET\n";

        $statRows = [
            ['Total Data (N)', (string) ($stats['count'] ?? 0)],
            ['Rata-Rata (Mean)', number_format((float) ($stats['mean'] ?? 0), 2)],
            ['Median (Q2)', number_format((float) ($stats['median'] ?? 0), 2)],
            ['Minimum / Maksimum', number_format((float) ($stats['min'] ?? 0), 2) . ' / ' . number_format((float) ($stats['max'] ?? 0), 2)],
            ['Standar Deviasi', number_format((float) ($stats['std_dev'] ?? 0), 2)],
            ['Interquartile Range (IQR)', number_format((float) ($stats['iqr'] ?? 0), 2)],
            ['Trend Slope / R2', number_format((float) ($data['trend']['slope'] ?? 0), 4) . ' / ' . number_format((float) ($data['trend']['r_squared'] ?? 0), 4)],
        ];

        $ty = 480;
        foreach ($statRows as $idx => $sRow) {
            $bg = ($idx % 2 === 0) ? '0.98 0.98 0.99' : '1.0 1.0 1.0';
            $stream .= "{$bg} rg 40 {$ty} 250 15 re f\n";
            $stream .= "0.88 0.9 0.93 RG 0.5 w 40 {$ty} 250 15 re S\n";
            $stream .= "BT /F1 8 Tf 0.2 0.25 0.3 rg 46 " . ($ty + 4) . " Td (" . $this->pdfEscape($sRow[0]) . ") Tj ET\n";
            $stream .= "BT /F2 8 Tf 0.06 0.09 0.16 rg 180 " . ($ty + 4) . " Td (" . $this->pdfEscape($sRow[1]) . ") Tj ET\n";
            $ty -= 15;
        }

        // Table 2: Forecast Predictions
        $stream .= "BT /F2 10 Tf 0.06 0.09 0.16 rg 305 515 Td (Proyeksi Peramalan (OLS + 95% CI)) Tj ET\n";
        $stream .= "0.12 0.16 0.22 rg 305 495 250 16 re f\n";
        $stream .= "BT /F2 8 Tf 1 1 1 rg 311 500 Td (Horizon) Tj 380 500 Td (Proyeksi) Tj 450 500 Td (95% Bounds) Tj ET\n";

        $preds = array_slice($forecast['predictions'] ?? [], 0, 7);
        $fy = 480;
        foreach ($preds as $fIdx => $fRow) {
            $bg = ($fIdx % 2 === 0) ? '0.98 0.98 0.99' : '1.0 1.0 1.0';
            $stream .= "{$bg} rg 305 {$fy} 250 15 re f\n";
            $stream .= "0.88 0.9 0.93 RG 0.5 w 305 {$fy} 250 15 re S\n";
            $stream .= "BT /F1 8 Tf 0.2 0.25 0.3 rg 311 " . ($fy + 4) . " Td (+" . (int) $fRow['step'] . " Periode) Tj ET\n";
            $stream .= "BT /F2 8 Tf 0.01 0.52 0.78 rg 380 " . ($fy + 4) . " Td (" . number_format((float) $fRow['predicted_value'], 2) . ") Tj ET\n";
            $stream .= "BT /F1 7.5 Tf 0.3 0.35 0.4 rg 450 " . ($fy + 4) . " Td ([" . number_format((float) $fRow['lower_bound_95'], 1) . " - " . number_format((float) $fRow['upper_bound_95'], 1) . "]) Tj ET\n";
            $fy -= 15;
        }

        // Section: Detected Anomalies Summary Table
        $stream .= "BT /F2 10 Tf 0.06 0.09 0.16 rg 40 355 Td (Daftar Anomali Kritis Terdeteksi) Tj ET\n";
        $stream .= "0.12 0.16 0.22 rg 40 335 515 16 re f\n";
        $stream .= "BT /F2 8 Tf 1 1 1 rg 46 340 Td (Indeks / Tanggal) Tj 160 340 Td (Nilai Aktual) Tj 240 340 Td (Z-Score) Tj 320 340 Td (Tipe Anomali) Tj 460 340 Td (Status) Tj ET\n";

        $topAnomalies = array_slice($anomalies['anomalies'] ?? [], 0, 8);
        $ay = 320;
        if (empty($topAnomalies)) {
            $stream .= "1 1 1 rg 40 {$ay} 515 15 re f\n";
            $stream .= "0.88 0.9 0.93 RG 0.5 w 40 {$ay} 515 15 re S\n";
            $stream .= "BT /F1 8 Tf 0.4 0.45 0.5 rg 46 " . ($ay + 4) . " Td (Tidak ditemukan anomali fluktuasi signifikan.) Tj ET\n";
            $ay -= 15;
        } else {
            foreach ($topAnomalies as $aIdx => $anom) {
                $bg = ($aIdx % 2 === 0) ? '0.98 0.98 0.99' : '1.0 1.0 1.0';
                $desc = !empty($anom['reasons']) ? (is_array($anom['reasons']) ? $anom['reasons'][0] : (string) $anom['reasons']) : ($anom['description'] ?? ($anom['iqr_type'] ?? ($anom['type'] ?? 'Outlier')));
                $period = $anom['period'] ?? ($anom['date'] ?? ('#' . ($anom['index'] ?? 0)));
                $stream .= "{$bg} rg 40 {$ay} 515 15 re f\n";
                $stream .= "0.88 0.9 0.93 RG 0.5 w 40 {$ay} 515 15 re S\n";
                $stream .= "BT /F1 8 Tf 0.2 0.25 0.3 rg 46 " . ($ay + 4) . " Td (" . $this->pdfEscape((string) $period) . ") Tj ET\n";
                $stream .= "BT /F2 8 Tf 0.06 0.09 0.16 rg 160 " . ($ay + 4) . " Td (" . number_format((float) ($anom['value'] ?? 0), 2) . ") Tj ET\n";
                $stream .= "BT /F1 8 Tf 0.2 0.25 0.3 rg 240 " . ($ay + 4) . " Td (" . (isset($anom['z_score']) ? number_format((float) $anom['z_score'], 2) : '-') . ") Tj ET\n";
                $stream .= "BT /F1 8 Tf 0.2 0.25 0.3 rg 320 " . ($ay + 4) . " Td (" . $this->pdfEscape((string) $desc) . ") Tj ET\n";
                $sevColor = (($anom['severity'] ?? 'warning') === 'critical') ? '0.94 0.27 0.27' : '0.96 0.62 0.04';
                $stream .= "BT /F2 8 Tf {$sevColor} rg 460 " . ($ay + 4) . " Td (" . strtoupper(e($anom['severity'] ?? 'warning')) . ") Tj ET\n";
                $ay -= 15;
            }
        }

        // Section: Disclaimer Box
        $stream .= "0.99 0.97 0.93 rg 40 100 515 50 re f\n";
        $stream .= "0.96 0.62 0.04 RG 0.75 w 40 100 515 50 re S\n";
        $stream .= "BT /F2 8 Tf 0.7 0.45 0.02 rg 48 136 Td (KLAUSUL ASUMSI MATEMATIS (DISCLAIMER):) Tj ET\n";
        $disclaimer = $forecast['assumption_disclaimer'] ?? 'Proyeksi ini dihasilkan secara matematis murni menggunakan model historis dan mengasumsikan pola masa lalu berlanjut. Angka ini adalah estimasi probabilitas bersyarat, bukan jaminan kepastian mutlak.';
        $disLines = explode("\n", wordwrap($disclaimer, 115, "\n"));
        $dy = 124;
        foreach (array_slice($disLines, 0, 2) as $dLine) {
            $stream .= "BT /F1 7.5 Tf 0.45 0.35 0.1 rg 48 {$dy} Td (" . $this->pdfEscape($dLine) . ") Tj ET\n";
            $dy -= 10;
        }

        // Footer
        $stream .= "0.85 0.88 0.92 RG 0.5 w 40 50 515 0.5 re S\n";
        $stream .= "BT /F1 8 Tf 0.58 0.64 0.72 rg 40 38 Td (AnaBot - AI Analytics Robot  |  Dokumen Rahasia Internal  |  Halaman 1 dari 1) Tj ET\n";

        // Assemble Indirect Objects
        $catalogId = $addObj("<< /Type /Catalog /Pages 2 0 R >>");
        $pagesId = $addObj("<< /Type /Pages /Kids [3 0 R] /Count 1 >>");
        $pageId = $addObj("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>");
        $font1Id = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");
        $font2Id = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>");

        $streamLen = strlen($stream);
        $contentId = $addObj("<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream");

        // Write Cross-Reference Table
        $xrefOffset = strlen($buffer);
        $count = count($objects) + 1;
        $buffer .= "xref\n0 {$count}\n";
        $buffer .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $buffer .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $buffer .= "trailer\n<< /Size {$count} /Root 1 0 R >>\n";
        $buffer .= "startxref\n{$xrefOffset}\n%%EOF\n";

        file_put_contents($fullPath, $buffer);

        return [
            'file_path' => $fullPath,
            'file_size' => filesize($fullPath),
            'filename' => basename($fullPath),
            'mime_type' => 'application/pdf',
        ];
    }

    /**
     * Escape strings for PDF text literal syntax (parentheses and backslashes).
     */
    private function pdfEscape(string $str): string
    {
        // Replace non-ascii with closest ascii
        $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str) ?: $str;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $str);
    }

    /**
     * Garbage collector: clean up old generated report files older than specified days.
     */
    public function cleanupOldReports(int $days = 30): int
    {
        $db = Database::getInstance();
        $cutoff = date('c', strtotime("-{$days} days"));

        $oldReports = $db->queryAll(
            "SELECT id, file_path FROM reports WHERE created_at < :cutoff",
            [':cutoff' => $cutoff]
        );

        $deletedCount = 0;
        foreach ($oldReports as $r) {
            if (!empty($r['file_path']) && file_exists($r['file_path'])) {
                @unlink($r['file_path']);
            }
            $db->execute("DELETE FROM reports WHERE id = :id", [':id' => $r['id']]);
            $deletedCount++;
        }

        return $deletedCount;
    }
}
