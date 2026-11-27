<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\Core\Database;
use AnaBot\Core\Response;
use AnaBot\Models\AnalysisResult;
use AnaBot\Models\Dataset;
use AnaBot\Services\AnalyticsEngine;

/**
 * AnaBot Analytics Controller
 * 
 * Manages Trends & Growth, Comparison & Correlation, and REST API endpoints.
 */
class AnalyticsController
{
    private Database $db;
    private AnalyticsEngine $engine;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->engine = new AnalyticsEngine($this->db);
    }

    /**
     * Web View: Trends & Growth Dashboard
     */
    public function trendsPage(int|array $id = 0): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;

        // 1. Fetch available datasets
        $datasets = Dataset::all(50, 0);
        if (empty($datasets)) {
            Response::view('layouts/main', [
                'pageTitle' => 'Analytics — Trends & Growth',
                'content' => $this->renderEmptyState('Belum ada dataset yang tersedia untuk dianalisis. Silakan hubungkan platform pada menu Connectors terlebih dahulu.'),
            ]);
            return;
        }

        // 2. Select dataset
        $selectedDataset = null;
        if ($id > 0) {
            $selectedDataset = Dataset::find($id);
        }
        if (!$selectedDataset) {
            $selectedDataset = $datasets[0];
            $id = (int) $selectedDataset['id'];
        }

        // 3. Columns for selected dataset
        $columns = $this->db->queryAll(
            "SELECT * FROM dataset_columns WHERE dataset_id = :did ORDER BY column_order ASC",
            [':did' => $id]
        );

        $metrics = [];
        $dimensions = [];
        $dateColumn = null;

        foreach ($columns as $c) {
            if ($c['is_primary_date'] && $dateColumn === null) {
                $dateColumn = $c['column_name'];
            }
            if ($c['is_metric']) {
                $metrics[] = $c['column_name'];
            }
            if ($c['is_dimension']) {
                $dimensions[] = $c['column_name'];
            }
        }

        if (empty($metrics)) {
            foreach ($columns as $c) {
                if (in_array($c['data_type'], ['NUMERIC', 'INTEGER'], true)) {
                    $metrics[] = $c['column_name'];
                }
            }
        }

        // 4. Metric & Interval selection from query params
        $selectedMetric = $_GET['metric'] ?? ($metrics[0] ?? '');
        $interval = strtolower($_GET['interval'] ?? 'day');
        if (!in_array($interval, ['day', 'week', 'month', 'year'], true)) {
            $interval = 'day';
        }

        // 5. Compute or fetch analytics
        $stats = [];
        $timeSeries = [];
        $growth = ['periods' => [], 'summary' => []];
        $trend = [];

        if (!empty($selectedMetric)) {
            $stats = $this->engine->getColumnStats($id, $selectedMetric, true);
            $rawSeries = $this->engine->aggregateTimeSeries($id, $selectedMetric, $dateColumn, $interval);
            $growth = $this->engine->computeGrowth($rawSeries, 'total');
            $timeSeries = $this->engine->computeMovingAverages($growth['periods'], 7, 'total');
            $trend = $this->engine->detectTrend($rawSeries, 'total');
        }

        Response::view('layouts/main', [
            'pageTitle' => 'Analytics — Trends & Growth',
            'content' => view('analytics/trends', [
                'datasets' => $datasets,
                'currentDataset' => $selectedDataset,
                'metrics' => $metrics,
                'selectedMetric' => $selectedMetric,
                'interval' => $interval,
                'stats' => $stats,
                'timeSeries' => $timeSeries,
                'growth' => $growth,
                'trend' => $trend,
            ]),
        ]);
    }

    /**
     * Web View: Comparison & Correlation Dashboard
     */
    public function comparisonPage(int|array $id = 0): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;

        // 1. Fetch available datasets
        $datasets = Dataset::all(50, 0);
        if (empty($datasets)) {
            Response::view('layouts/main', [
                'pageTitle' => 'Analytics — Comparison & Correlation',
                'content' => $this->renderEmptyState('Belum ada dataset yang tersedia untuk dianalisis komparasi. Silakan hubungkan platform terlebih dahulu.'),
            ]);
            return;
        }

        // 2. Select dataset
        $selectedDataset = null;
        if ($id > 0) {
            $selectedDataset = Dataset::find($id);
        }
        if (!$selectedDataset) {
            $selectedDataset = $datasets[0];
            $id = (int) $selectedDataset['id'];
        }

        // 3. Columns for selected dataset
        $columns = $this->db->queryAll(
            "SELECT * FROM dataset_columns WHERE dataset_id = :did ORDER BY column_order ASC",
            [':did' => $id]
        );

        $metrics = [];
        $dimensions = [];

        foreach ($columns as $c) {
            if ($c['is_metric'] || in_array($c['data_type'], ['NUMERIC', 'INTEGER'], true)) {
                $metrics[] = $c['column_name'];
            }
            if ($c['is_dimension']) {
                $dimensions[] = $c['column_name'];
            }
        }

        // 4. Compute correlation matrix across metrics
        $correlation = $this->engine->computeCorrelationMatrix($id, $metrics);

        // 5. Dimension breakdown for primary dimension
        $selectedDimension = $_GET['dimension'] ?? ($dimensions[0] ?? '');
        $selectedMetric = $_GET['metric'] ?? ($metrics[0] ?? '');
        $breakdown = [];

        if (!empty($selectedDimension) && !empty($selectedMetric)) {
            $breakdown = $this->engine->breakdownByDimension($id, $selectedDimension, $selectedMetric, 10);
        }

        Response::view('layouts/main', [
            'pageTitle' => 'Analytics — Comparison & Correlation',
            'content' => view('analytics/comparison', [
                'datasets' => $datasets,
                'currentDataset' => $selectedDataset,
                'metrics' => $metrics,
                'dimensions' => $dimensions,
                'selectedMetric' => $selectedMetric,
                'selectedDimension' => $selectedDimension,
                'correlation' => $correlation,
                'breakdown' => $breakdown,
            ]),
        ]);
    }

    /**
     * API: Get Descriptive Statistics Summary
     */
    public function summaryApi(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        $metric = $_GET['metric'] ?? null;

        try {
            if (!empty($metric)) {
                $stats = $this->engine->getColumnStats($id, $metric, false);
                Response::json(['success' => true, 'data' => $stats]);
                return;
            }

            // All metrics
            $columns = $this->db->queryAll(
                "SELECT column_name FROM dataset_columns 
                 WHERE dataset_id = :did AND (is_metric = 1 OR data_type IN ('NUMERIC', 'INTEGER'))",
                [':did' => $id]
            );

            $allStats = [];
            foreach ($columns as $c) {
                $allStats[$c['column_name']] = $this->engine->getColumnStats($id, $c['column_name'], false);
            }

            Response::json(['success' => true, 'data' => $allStats]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * API: Get Time Series, Moving Averages, and Trend
     */
    public function timeseriesApi(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        $metric = $_GET['metric'] ?? '';
        $interval = $_GET['interval'] ?? 'day';
        $window = (int) ($_GET['window'] ?? 7);

        try {
            if (empty($metric)) {
                throw new \InvalidArgumentException('Parameter metric wajib disertakan.');
            }

            $rawSeries = $this->engine->aggregateTimeSeries($id, $metric, null, $interval);
            $growth = $this->engine->computeGrowth($rawSeries, 'total');
            $withMa = $this->engine->computeMovingAverages($growth['periods'], $window, 'total');
            $trend = $this->engine->detectTrend($rawSeries, 'total');

            Response::json([
                'success' => true,
                'data' => [
                    'dataset_id' => $id,
                    'metric' => $metric,
                    'interval' => $interval,
                    'series' => $withMa,
                    'growth_summary' => $growth['summary'],
                    'trend' => $trend,
                ],
            ]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * API: Get Correlation Matrix
     */
    public function correlationApi(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;

        try {
            $matrix = $this->engine->computeCorrelationMatrix($id);
            Response::json(['success' => true, 'data' => $matrix]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * API: Get Dimension Breakdown
     */
    public function breakdownApi(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        $dimension = $_GET['dimension'] ?? '';
        $metric = $_GET['metric'] ?? '';
        $limit = (int) ($_GET['limit'] ?? 10);

        try {
            if (empty($dimension) || empty($metric)) {
                throw new \InvalidArgumentException('Parameter dimension dan metric wajib disertakan.');
            }

            $breakdown = $this->engine->breakdownByDimension($id, $dimension, $metric, $limit);
            Response::json(['success' => true, 'data' => $breakdown]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * API: Force Recalculate Full Analysis Suite
     */
    public function recalculateApi(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;

        try {
            $results = $this->engine->runFullAnalysis($id);
            Response::json(['success' => true, 'message' => 'Analisis dataset berhasil dihitung ulang dan disimpan.', 'data' => $results]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function renderEmptyState(string $message): string
    {
        return '<div class="chart-card" style="text-align: center; padding: 4rem 2rem;">
            <div style="font-size: 3rem; margin-bottom: 1rem;">📊</div>
            <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">Tidak Ada Data Analisis</h3>
            <p style="color: var(--text-muted); max-width: 480px; margin: 0 auto 1.5rem auto;">' . e($message) . '</p>
            <div style="display: flex; gap: 0.75rem; justify-content: center;">
                <a href="/connectors" class="btn btn-primary btn-sm">Buka Connectors</a>
                <a href="/explorer/datasets" class="btn btn-outline btn-sm">Lihat Datasets</a>
            </div>
        </div>';
    }
}
