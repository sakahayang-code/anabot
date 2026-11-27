<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Core\Database;
use AnaBot\Core\MigrationRunner;
use AnaBot\Models\AnalysisResult;
use AnaBot\Services\AnalyticsEngine;
use AnaBot\Services\AnomalyDetector;
use AnaBot\Services\ForecastEngine;

/**
 * Phase 9: Predictive Analytics & Anomaly Detection Automated Test Suite
 * 
 * Verifies:
 * - John Tukey's IQR quartile & inner/outer fence calculations
 * - Standardized Z-Score calculation with Bessel's sample variance
 * - Sequential time series sudden shift & drop detection
 * - Unified dataset anomaly report generation & risk leveling
 * - OLS Linear Regression deterministic forecasting (slope, intercept, R²)
 * - 95% Confidence Intervals & Prediction Error bounds
 * - Holt-Winters double exponential smoothing (level & trend updates)
 * - Moving average forecasting extrapolation
 * - Model accuracy evaluation (MAPE & RMSE backtesting)
 * - Mandatory assumption transparency disclaimer & analysis_results persistence
 */
class PredictiveAnalyticsTest
{
    private Database $db;
    private AnalyticsEngine $engine;
    private AnomalyDetector $anomalyDetector;
    private ForecastEngine $forecastEngine;
    private int $testDatasetId;

    public function run(): void
    {
        echo "Testing Predictive Analytics & Anomaly Detection Engine (Isolated Test DB)..." . PHP_EOL;

        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $this->db = new Database($testDbPath);
        Database::setInstance($this->db);

        $runner = new MigrationRunner($this->db);
        $runner->run();

        $this->engine = new AnalyticsEngine($this->db);
        $this->anomalyDetector = new AnomalyDetector($this->db, $this->engine);
        $this->forecastEngine = new ForecastEngine($this->db, $this->engine);

        $this->seedDataset();

        $this->testIqrOutlierDetection();
        $this->testZScoreOutlierDetection();
        $this->testTimeSeriesShiftDetection();
        $this->testUnifiedDatasetAnomalyDetection();
        $this->testLinearOLSForecastExactness();
        $this->testPredictionIntervalConfidenceBounds();
        $this->testHoltWintersExponentialSmoothing();
        $this->testMovingAverageForecasting();
        $this->testForecastAccuracyAndDisclaimerRigor();
        $this->testAnalysisResultPersistence();

        echo "  [PASS] All 10 Predictive Analytics & Anomaly Detection test cases passed successfully." . PHP_EOL;
    }

    private function seedDataset(): void
    {
        $now = date('c');

        // User & Connector
        $this->db->execute("INSERT INTO users (username, email, password_hash, status, created_at, updated_at) VALUES ('predict_user', 'predict@anabot.local', 'h', 'active', :n, :n)", [':n' => $now]);
        $uid = (int) $this->db->lastInsertId();

        $this->db->execute("INSERT INTO connectors (connector_code, name, category, auth_type, created_at) VALUES ('sheets', 'Google Sheets', 'spreadsheet', 'oauth2', :n)", [':n' => $now]);
        $cid = (int) $this->db->lastInsertId();

        $this->db->execute("INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at) VALUES (:cid, :uid, 'Conn', 'connected', :n, :n)", [':cid' => $cid, ':uid' => $uid, ':n' => $now]);
        $pcid = (int) $this->db->lastInsertId();

        $this->db->execute("INSERT INTO data_sources (connection_id, external_source_id, source_name, created_at, updated_at) VALUES (:pcid, 'src_101', 'Source', :n, :n)", [':pcid' => $pcid, ':n' => $now]);
        $dsId = (int) $this->db->lastInsertId();

        $this->db->execute("INSERT INTO datasets (data_source_id, name, record_count, first_record_at, latest_record_at, created_at, updated_at) VALUES (:dsid, 'Dataset Penjualan Bulanan', 12, '2025-01-01', '2025-12-01', :n, :n)", [':dsid' => $dsId, ':n' => $now]);
        $this->testDatasetId = (int) $this->db->lastInsertId();

        // Columns
        $this->db->execute("INSERT INTO dataset_columns (dataset_id, column_name, data_type, is_primary_date, is_metric, is_dimension, column_order, created_at) VALUES (:did, 'bulan', 'DATETIME', 1, 0, 0, 1, :n)", [':did' => $this->testDatasetId, ':n' => $now]);
        $this->db->execute("INSERT INTO dataset_columns (dataset_id, column_name, data_type, is_primary_date, is_metric, is_dimension, column_order, created_at) VALUES (:did, 'revenue', 'NUMERIC', 0, 1, 0, 2, :n)", [':did' => $this->testDatasetId, ':n' => $now]);

        // Monthly Records with a spike in month 8 (anomali) and steady baseline
        $monthlyRevenue = [
            ['2025-01-01', 10000000],
            ['2025-02-01', 11000000],
            ['2025-03-01', 12000000],
            ['2025-04-01', 12500000],
            ['2025-05-01', 13000000],
            ['2025-06-01', 14000000],
            ['2025-07-01', 14500000],
            ['2025-08-01', 35000000], // Critical Spike (Outlier)
            ['2025-09-01', 15000000],
            ['2025-10-01', 16000000],
            ['2025-11-01', 16500000],
            ['2025-12-01', 17000000],
        ];

        foreach ($monthlyRevenue as $idx => $r) {
            $json = json_encode(['bulan' => $r[0], 'revenue' => $r[1]]);
            $this->db->execute(
                "INSERT INTO data_records (dataset_id, external_id, record_date, record_hash, data_json, created_at, updated_at)
                 VALUES (:did, :ext, :rdate, :rhash, :json, :n, :n)",
                [
                    ':did' => $this->testDatasetId,
                    ':ext' => 'm_' . ($idx + 1),
                    ':rdate' => $r[0],
                    ':rhash' => hash('sha256', (string)($idx + 1)),
                    ':json' => $json,
                    ':n' => $now,
                ]
            );
        }
    }

    private function testIqrOutlierDetection(): void
    {
        // Dataset with a clear high outlier: [10, 12, 14, 15, 16, 18, 20, 100] (N = 8)
        $values = [10, 12, 14, 15, 16, 18, 20, 100];
        $res = $this->anomalyDetector->detectIqrOutliers($values);

        assert($res['count'] === 8, 'Count must be 8');
        assert($res['q1'] > 0 && $res['q3'] > $res['q1'], 'Q3 must be greater than Q1');
        assert($res['iqr'] > 0, 'IQR must be positive');
        assert($res['outlier_count'] === 1, 'Must detect exactly 1 outlier');

        $outlier = $res['outliers'][0];
        assert($outlier['value'] === 100.0, 'Outlier value must be 100');
        assert($outlier['direction'] === 'high', 'Outlier direction must be high');
        assert($outlier['type'] === 'EXTREME_OUTLIER', 'Value 100 must be classified as EXTREME_OUTLIER');
        assert($outlier['severity'] === 'critical', 'Severity must be critical');

        echo "  ✓ John Tukey's IQR quartile and mild/extreme fence calculation verified." . PHP_EOL;
    }

    private function testZScoreOutlierDetection(): void
    {
        // Distribution with 1 extreme outlier and n=15: fourteen 10s and one 100 (z ≈ 3.61)
        $values = [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 100];
        $res = $this->anomalyDetector->detectZScoreOutliers($values, 2.5);

        assert($res['count'] === 15, 'Count must be 15');
        assert($res['std_dev'] > 0, 'Sample standard deviation must be positive');
        assert($res['outlier_count'] === 1, 'Must detect exactly 1 outlier with z >= 2.5');

        $outlier = $res['outliers'][0];
        assert($outlier['value'] === 100.0, 'Outlier value must be 100');
        assert($outlier['z_score'] > 2.5, 'z_score must exceed threshold 2.5');
        assert($outlier['direction'] === 'high', 'Direction must be high');

        // Test constant array (zero variance protection)
        $constantValues = [5, 5, 5, 5, 5];
        $constRes = $this->anomalyDetector->detectZScoreOutliers($constantValues, 2.5);
        assert($constRes['std_dev'] === 0.0, 'Standard deviation must be zero');
        assert($constRes['outlier_count'] === 0, 'Zero variance must produce 0 outliers without error');

        echo "  ✓ Standardized Z-Score calculation and zero-variance protection verified." . PHP_EOL;
    }

    private function testTimeSeriesShiftDetection(): void
    {
        $series = [
            ['period' => '2026-01-01', 'total' => 1000],
            ['period' => '2026-01-02', 'total' => 1050],
            ['period' => '2026-01-03', 'total' => 2500], // +138% jump
            ['period' => '2026-01-04', 'total' => 800],  // -68% drop
            ['period' => '2026-01-05', 'total' => 850],
        ];

        $shifts = $this->anomalyDetector->detectTimeSeriesShifts($series, 'total', 40.0);
        assert(count($shifts) === 2, 'Must detect 2 sudden shift anomalies');

        assert($shifts[0]['type'] === 'SUDDEN_SPIKE', 'Shift 0 must be SUDDEN_SPIKE');
        assert($shifts[0]['period'] === '2026-01-03', 'Shift 0 period must match');
        assert($shifts[0]['pct_change'] > 100.0, 'Shift 0 percentage must exceed 100%');

        assert($shifts[1]['type'] === 'SUDDEN_DROP', 'Shift 1 must be SUDDEN_DROP');
        assert($shifts[1]['period'] === '2026-01-04', 'Shift 1 period must match');
        assert($shifts[1]['pct_change'] < -50.0, 'Shift 1 percentage must be negative drop');

        echo "  ✓ Time series sudden spike & steep drop detection verified." . PHP_EOL;
    }

    private function testUnifiedDatasetAnomalyDetection(): void
    {
        $report = $this->anomalyDetector->detectDatasetAnomalies(
            $this->testDatasetId,
            'revenue',
            ['interval' => 'day', 'z_threshold' => 2.0]
        );

        assert($report['dataset_id'] === $this->testDatasetId, 'Dataset ID must match');
        assert($report['metric'] === 'revenue', 'Metric must be revenue');
        assert($report['total_points'] === 12, 'Total points must be 12');
        assert($report['anomaly_count'] >= 1, 'Must detect at least 1 anomaly');

        // Month 8 (value 35,000,000) must be detected as anomaly
        $month8 = null;
        foreach ($report['anomalies'] as $a) {
            if ($a['period'] === '2025-08-01') {
                $month8 = $a;
                break;
            }
        }

        assert($month8 !== null, 'Month 2025-08-01 must be detected as anomaly');
        assert($month8['value'] === 35000000.0, 'Anomaly value must be 35000000');
        assert($month8['severity'] === 'critical', 'Severity must be critical');
        assert(in_array('iqr', $month8['methods'], true), 'IQR method must flag this anomaly');

        echo "  ✓ Unified dataset anomaly detection & risk leveling verified." . PHP_EOL;
    }

    private function testLinearOLSForecastExactness(): void
    {
        // Perfectly linear series: [10, 20, 30, 40, 50] (y = 10 * t)
        $series = [
            ['period' => '2026-01-01', 'total' => 10],
            ['period' => '2026-01-02', 'total' => 20],
            ['period' => '2026-01-03', 'total' => 30],
            ['period' => '2026-01-04', 'total' => 40],
            ['period' => '2026-01-05', 'total' => 50],
        ];

        $res = $this->forecastEngine->forecastLinearOLS($series, 3, 'total');

        assert($res['method'] === 'OLS_LINEAR_REGRESSION', 'Method must be OLS');
        assert(abs($res['parameters']['slope'] - 10.0) < 1e-4, 'Slope must be exactly 10.0');
        assert(abs($res['parameters']['intercept'] - 0.0) < 1e-4, 'Intercept must be 0.0');
        assert(abs($res['parameters']['r_squared'] - 1.0) < 1e-4, 'R-squared must be 1.0 (perfect fit)');

        // Next 3 points must be 60, 70, 80
        $points = $res['forecast_points'];
        assert(count($points) === 3, 'Must return 3 forecast points');
        assert(abs($points[0]['forecast'] - 60.0) < 1e-2, 'Point 1 forecast must be 60.0');
        assert(abs($points[1]['forecast'] - 70.0) < 1e-2, 'Point 2 forecast must be 70.0');
        assert(abs($points[2]['forecast'] - 80.0) < 1e-2, 'Point 3 forecast must be 80.0');

        echo "  ✓ Deterministic OLS linear regression forecast mathematical exactness verified." . PHP_EOL;
    }

    private function testPredictionIntervalConfidenceBounds(): void
    {
        // Series with moderate variance: [10, 22, 29, 41, 52, 59]
        $series = [
            ['period' => '2026-01-01', 'total' => 10],
            ['period' => '2026-01-02', 'total' => 22],
            ['period' => '2026-01-03', 'total' => 29],
            ['period' => '2026-01-04', 'total' => 41],
            ['period' => '2026-01-05', 'total' => 52],
            ['period' => '2026-01-06', 'total' => 59],
        ];

        $res = $this->forecastEngine->forecastLinearOLS($series, 4, 'total');

        foreach ($res['forecast_points'] as $pt) {
            assert($pt['lower_bound_95'] <= $pt['forecast'], 'Lower bound must be <= forecast');
            assert($pt['upper_bound_95'] >= $pt['forecast'], 'Upper bound must be >= forecast');
            assert($pt['lower_bound_95'] >= 0.0, 'Lower bound must not be negative');
        }

        echo "  ✓ 95% Confidence Interval prediction bounds (lower & upper) verified." . PHP_EOL;
    }

    private function testHoltWintersExponentialSmoothing(): void
    {
        $series = [
            ['period' => '2026-01-01', 'total' => 100],
            ['period' => '2026-01-02', 'total' => 110],
            ['period' => '2026-01-03', 'total' => 125],
            ['period' => '2026-01-04', 'total' => 140],
            ['period' => '2026-01-05', 'total' => 155],
        ];

        $res = $this->forecastEngine->forecastHoltWinters($series, 3, 0.4, 0.2, 'total');

        assert($res['method'] === 'HOLT_WINTERS_LINEAR', 'Method must be Holt-Winters');
        assert(count($res['forecast_points']) === 3, 'Must produce 3 forecast points');
        assert($res['parameters']['final_level'] > 100, 'Level must be updated');
        assert($res['parameters']['final_trend'] > 0, 'Trend must be positive');

        // Forecast points should increase in positive trend
        $p1 = $res['forecast_points'][0]['forecast'];
        $p2 = $res['forecast_points'][1]['forecast'];
        assert($p2 > $p1, 'Point 2 must be higher than Point 1 in upward trend');

        echo "  ✓ Holt-Winters double exponential smoothing (level & trend updates) verified." . PHP_EOL;
    }

    private function testMovingAverageForecasting(): void
    {
        $series = [
            ['period' => '2026-01-01', 'total' => 100],
            ['period' => '2026-01-02', 'total' => 120],
            ['period' => '2026-01-03', 'total' => 140],
            ['period' => '2026-01-04', 'total' => 160], // Last 3 avg: (120 + 140 + 160) / 3 = 140.0
        ];

        $res = $this->forecastEngine->forecastMovingAverage($series, 3, 3, 'total');

        assert($res['method'] === 'MOVING_AVERAGE', 'Method must be Moving Average');
        assert($res['parameters']['moving_average_value'] === 140.0, 'Window avg must be exactly 140.0');
        assert($res['forecast_points'][0]['forecast'] === 140.0, 'Forecast point 1 must be 140.0');

        echo "  ✓ Simple Moving Average forecast extrapolation verified." . PHP_EOL;
    }

    private function testForecastAccuracyAndDisclaimerRigor(): void
    {
        $forecast = $this->forecastEngine->forecastDataset(
            $this->testDatasetId,
            'revenue',
            'ols',
            6,
            ['interval' => 'day']
        );

        assert(!empty($forecast['accuracy_evaluation']), 'Accuracy evaluation must be populated');
        assert(isset($forecast['accuracy_evaluation']['mape_pct']), 'MAPE must be computed');
        assert(isset($forecast['accuracy_evaluation']['rmse']), 'RMSE must be computed');

        // Strict Disclaimer Verification
        assert(!empty($forecast['assumption_disclaimer']), 'Assumption disclaimer must not be empty');
        assert(str_contains($forecast['assumption_disclaimer'], 'Proyeksi ini dihasilkan secara matematis'), 'Disclaimer must state mathematical projection');
        assert(str_contains($forecast['assumption_disclaimer'], 'mengasumsikan'), 'Disclaimer must mention historical assumptions');

        echo "  ✓ Forecast backtesting accuracy (MAPE & RMSE) and strict disclaimer verified." . PHP_EOL;
    }

    private function testAnalysisResultPersistence(): void
    {
        // Check persisted results in analysis_results table
        $anomalySaved = AnalysisResult::findLatest($this->testDatasetId, 'revenue', 'anomaly_detection');
        assert($anomalySaved !== null, 'Anomaly detection result must be persisted in analysis_results');
        assert($anomalySaved['calculated_value']['metric'] === 'revenue', 'Persisted metric must match');

        $forecastSaved = AnalysisResult::findLatest($this->testDatasetId, 'revenue', 'forecast_projection');
        assert($forecastSaved !== null, 'Forecast result must be persisted in analysis_results');
        assert($forecastSaved['calculated_value']['method'] === 'OLS_LINEAR_REGRESSION', 'Persisted forecast method must match');

        echo "  ✓ Deterministic persistence in analysis_results verified." . PHP_EOL;
    }
}
