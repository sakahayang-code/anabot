<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Database;
use AnaBot\Core\Logger;
use AnaBot\Models\AnalysisResult;
use RuntimeException;

/**
 * AnaBot Deterministic Forecasting Engine (Pure PHP 8.4 Native)
 * 
 * Implements deterministic time series projections:
 * 1. Ordinary Least Squares (OLS) Linear Trend Projection with 95% Confidence Intervals
 * 2. Holt's Double Exponential Smoothing (Level & Trend tracking)
 * 3. Moving Average Projection
 * 4. Model Goodness-of-Fit Evaluation (MAPE, RMSE, R²)
 * 
 * Enforces Rule 1.14 & Rule 2.3: Zero Hallucination Ground Truth & Assumption Transparency.
 */
class ForecastEngine
{
    private Database $db;
    private AnalyticsEngine $analyticsEngine;
    private Logger $logger;

    public function __construct(
        ?Database $db = null,
        ?AnalyticsEngine $analyticsEngine = null,
        ?Logger $logger = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->analyticsEngine = $analyticsEngine ?? new AnalyticsEngine($this->db);
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Forecast using Ordinary Least Squares (OLS) Linear Trend.
     * Computes point forecasts and 95% prediction intervals.
     * 
     * @param array $timeSeries Sequential time series points with period & valueKey
     * @param int $horizon Number of future periods to project (default 6)
     * @param string $valueKey Field name for numeric metric
     * @return array Forecast points, model parameters, and evaluation metrics
     */
    public function forecastLinearOLS(
        array $timeSeries,
        int $horizon = 6,
        string $valueKey = 'total'
    ): array {
        $clean = [];
        foreach ($timeSeries as $pt) {
            if (isset($pt[$valueKey]) && is_numeric($pt[$valueKey])) {
                $clean[] = [
                    'period' => $pt['period'] ?? '',
                    'value' => (float) $pt[$valueKey],
                ];
            }
        }

        $n = count($clean);
        if ($n < 3) {
            throw new RuntimeException("Dibutuhkan minimal 3 periode data historis untuk memproyeksikan tren.");
        }

        // 1. Time indices t = 1, 2, ..., n
        $sumT = 0.0;
        $sumY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $t = $i + 1;
            $y = $clean[$i]['value'];
            $sumT += $t;
            $sumY += $y;
        }

        $meanT = $sumT / $n;
        $meanY = $sumY / $n;

        // 2. Slope (beta1) and Intercept (beta0)
        $numerator = 0.0;
        $denomT = 0.0;
        $denomY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $t = $i + 1;
            $y = $clean[$i]['value'];
            $dt = $t - $meanT;
            $dy = $y - $meanY;
            $numerator += $dt * $dy;
            $denomT += $dt ** 2;
            $denomY += $dy ** 2;
        }

        $slope = $denomT > 0 ? ($numerator / $denomT) : 0.0;
        $intercept = $meanY - ($slope * $meanT);

        // R-squared
        $rSquared = ($denomT > 0 && $denomY > 0) ? (($numerator ** 2) / ($denomT * $denomY)) : 0.0;
        $rSquared = max(0.0, min(1.0, $rSquared));

        // 3. Residuals & Standard Error of Regression
        $sumSqResiduals = 0.0;
        $mapeSum = 0.0;
        $fittedValues = [];

        for ($i = 0; $i < $n; $i++) {
            $t = $i + 1;
            $actual = $clean[$i]['value'];
            $fitted = $intercept + ($slope * $t);
            $fittedValues[] = round($fitted, 2);

            $residual = $actual - $fitted;
            $sumSqResiduals += $residual ** 2;

            if ($actual != 0.0) {
                $mapeSum += abs($residual / $actual);
            }
        }

        $degreesOfFreedom = max(1, $n - 2);
        $stdErrorRegression = sqrt($sumSqResiduals / $degreesOfFreedom);
        $mape = round(($mapeSum / $n) * 100.0, 2);
        $rmse = round(sqrt($sumSqResiduals / $n), 2);

        // 4. Generate Future Forecast Points
        $forecastPoints = [];
        $lastPeriod = $clean[$n - 1]['period'];
        $lastValue = $clean[$n - 1]['value'];

        for ($h = 1; $h <= $horizon; $h++) {
            $tNew = $n + $h;
            $forecastVal = $intercept + ($slope * $tNew);

            // Prediction standard error (accounts for regression variance + sample uncertainty)
            $sePred = $stdErrorRegression * sqrt(1.0 + (1.0 / $n) + ((($tNew - $meanT) ** 2) / ($denomT > 0 ? $denomT : 1.0)));
            $marginOfError = 1.96 * $sePred; // 95% confidence interval

            $lowerBound = max(0.0, round($forecastVal - $marginOfError, 2));
            $upperBound = round($forecastVal + $marginOfError, 2);
            $pointForecast = max(0.0, round($forecastVal, 2));

            $futureLabel = $this->generateNextPeriodLabel($lastPeriod, $h);

            $forecastPoints[] = [
                'step' => $h,
                'period' => $futureLabel,
                'forecast' => $pointForecast,
                'lower_bound_95' => $lowerBound,
                'upper_bound_95' => $upperBound,
                'pct_change_from_latest' => $lastValue > 0 ? round((($pointForecast - $lastValue) / $lastValue) * 100.0, 2) : 0.0,
            ];
        }

        $endForecast = end($forecastPoints);
        $overallExpectedGrowth = $lastValue > 0 ? round((($endForecast['forecast'] - $lastValue) / $lastValue) * 100.0, 2) : 0.0;

        return [
            'method' => 'OLS_LINEAR_REGRESSION',
            'method_name' => 'Regresi Tren Linear OLS',
            'horizon' => $horizon,
            'historical_points' => $n,
            'latest_actual_value' => $lastValue,
            'parameters' => [
                'slope' => round($slope, 4),
                'intercept' => round($intercept, 4),
                'r_squared' => round($rSquared, 4),
                'std_error_estimate' => round($stdErrorRegression, 4),
            ],
            'accuracy_evaluation' => [
                'mape_pct' => $mape,
                'rmse' => $rmse,
                'fit_quality' => $rSquared >= 0.7 ? 'TINGGI (Akurat)' : ($rSquared >= 0.4 ? 'MODERAT' : 'RENDAH (Fluktuatif)'),
            ],
            'projected_growth_pct' => $overallExpectedGrowth,
            'forecast_points' => $forecastPoints,
            'assumption_disclaimer' => 'Proyeksi ini dihasilkan secara matematis menggunakan model Ordinary Least Squares (OLS) berdasarkan tren historis data masa lalu. Hasil proyeksi mengasumsikan dinamika bisnis masa lalu berlanjut secara konsisten tanpa anomali atau guncangan eksternal tak terduga.',
        ];
    }

    /**
     * Forecast using Holt's Linear Exponential Smoothing (Level & Trend).
     * 
     * @param array $timeSeries Sequential time series
     * @param int $horizon Forecast horizon (periods)
     * @param float $alpha Level smoothing factor (0 < alpha < 1)
     * @param float $beta Trend smoothing factor (0 < beta < 1)
     * @param string $valueKey Metric key
     * @return array Forecast points and parameters
     */
    public function forecastHoltWinters(
        array $timeSeries,
        int $horizon = 6,
        float $alpha = 0.3,
        float $beta = 0.1,
        string $valueKey = 'total'
    ): array {
        $clean = [];
        foreach ($timeSeries as $pt) {
            if (isset($pt[$valueKey]) && is_numeric($pt[$valueKey])) {
                $clean[] = [
                    'period' => $pt['period'] ?? '',
                    'value' => (float) $pt[$valueKey],
                ];
            }
        }

        $n = count($clean);
        if ($n < 3) {
            throw new RuntimeException("Dibutuhkan minimal 3 periode data historis untuk Holt-Winters smoothing.");
        }

        // Clamp smoothing parameters
        $alpha = max(0.01, min(0.99, $alpha));
        $beta = max(0.01, min(0.99, $beta));

        // 1. Initial level & trend
        $level = $clean[0]['value'];
        $trend = ($clean[$n - 1]['value'] - $clean[0]['value']) / ($n - 1);

        $residuals = [];

        // 2. Sequential smoothing updates
        for ($t = 1; $t < $n; $t++) {
            $actual = $clean[$t]['value'];
            $predicted = $level + $trend;
            $residuals[] = $actual - $predicted;

            $prevLevel = $level;
            $level = ($alpha * $actual) + ((1.0 - $alpha) * ($prevLevel + $trend));
            $trend = ($beta * ($level - $prevLevel)) + ((1.0 - $beta) * $trend);
        }

        // Calculate residual standard deviation for confidence intervals
        $stdDevResiduals = 0.0;
        if (!empty($residuals)) {
            $sumSq = array_sum(array_map(fn($r) => $r ** 2, $residuals));
            $stdDevResiduals = sqrt($sumSq / count($residuals));
        }

        // 3. Generate future forecast points
        $forecastPoints = [];
        $lastPeriod = $clean[$n - 1]['period'];
        $lastValue = $clean[$n - 1]['value'];

        for ($h = 1; $h <= $horizon; $h++) {
            $forecastVal = $level + ($h * $trend);
            $se = $stdDevResiduals * sqrt($h);
            $margin = 1.96 * $se;

            $pointForecast = max(0.0, round($forecastVal, 2));
            $lower = max(0.0, round($forecastVal - $margin, 2));
            $upper = round($forecastVal + $margin, 2);

            $futureLabel = $this->generateNextPeriodLabel($lastPeriod, $h);

            $forecastPoints[] = [
                'step' => $h,
                'period' => $futureLabel,
                'forecast' => $pointForecast,
                'lower_bound_95' => $lower,
                'upper_bound_95' => $upper,
                'pct_change_from_latest' => $lastValue > 0 ? round((($pointForecast - $lastValue) / $lastValue) * 100.0, 2) : 0.0,
            ];
        }

        $endForecast = end($forecastPoints);
        $overallExpectedGrowth = $lastValue > 0 ? round((($endForecast['forecast'] - $lastValue) / $lastValue) * 100.0, 2) : 0.0;

        return [
            'method' => 'HOLT_WINTERS_LINEAR',
            'method_name' => 'Holt-Winters Double Exponential Smoothing',
            'horizon' => $horizon,
            'historical_points' => $n,
            'latest_actual_value' => $lastValue,
            'parameters' => [
                'alpha' => $alpha,
                'beta' => $beta,
                'final_level' => round($level, 2),
                'final_trend' => round($trend, 4),
            ],
            'accuracy_evaluation' => [
                'rmse' => round($stdDevResiduals, 2),
                'fit_quality' => $stdDevResiduals < ($lastValue * 0.15) ? 'TINGGI (Stabil)' : 'MODERAT',
            ],
            'projected_growth_pct' => $overallExpectedGrowth,
            'forecast_points' => $forecastPoints,
            'assumption_disclaimer' => 'Proyeksi Holt-Winters ini memperhitungkan bobot eksponensial level terkini dan laju pergerakan tren. Asumsi model menganggap momentum level dan tren terkini berlanjut secara proporsional.',
        ];
    }

    /**
     * Forecast using Simple Moving Average extrapolation.
     */
    public function forecastMovingAverage(
        array $timeSeries,
        int $horizon = 6,
        int $window = 3,
        string $valueKey = 'total'
    ): array {
        $clean = [];
        foreach ($timeSeries as $pt) {
            if (isset($pt[$valueKey]) && is_numeric($pt[$valueKey])) {
                $clean[] = [
                    'period' => $pt['period'] ?? '',
                    'value' => (float) $pt[$valueKey],
                ];
            }
        }

        $n = count($clean);
        if ($n < $window) {
            $window = max(2, $n);
        }

        $slice = array_slice($clean, -$window);
        $avg = array_sum(array_column($slice, 'value')) / count($slice);

        $forecastPoints = [];
        $lastPeriod = $clean[$n - 1]['period'];
        $lastValue = $clean[$n - 1]['value'];

        for ($h = 1; $h <= $horizon; $h++) {
            $futureLabel = $this->generateNextPeriodLabel($lastPeriod, $h);
            $forecastPoints[] = [
                'step' => $h,
                'period' => $futureLabel,
                'forecast' => round($avg, 2),
                'lower_bound_95' => max(0.0, round($avg * 0.85, 2)),
                'upper_bound_95' => round($avg * 1.15, 2),
                'pct_change_from_latest' => $lastValue > 0 ? round((($avg - $lastValue) / $lastValue) * 100.0, 2) : 0.0,
            ];
        }

        return [
            'method' => 'MOVING_AVERAGE',
            'method_name' => "Simple Moving Average ({$window} Periode)",
            'horizon' => $horizon,
            'historical_points' => $n,
            'latest_actual_value' => $lastValue,
            'parameters' => [
                'window' => $window,
                'moving_average_value' => round($avg, 2),
            ],
            'accuracy_evaluation' => [
                'fit_quality' => 'BASELINE_SMOOTHED',
            ],
            'projected_growth_pct' => $lastValue > 0 ? round((($avg - $lastValue) / $lastValue) * 100.0, 2) : 0.0,
            'forecast_points' => $forecastPoints,
            'assumption_disclaimer' => 'Proyeksi Moving Average mengasumsikan nilai masa depan konvergen ke rata-rata observasi periode terdekat.',
        ];
    }

    /**
     * Run forecasting for a dataset metric and persist result.
     * 
     * @param int $datasetId Dataset ID
     * @param string $metricName Metric column name
     * @param string $method Forecasting method: 'ols' | 'holt_winters' | 'moving_avg'
     * @param int $horizon Periods ahead
     * @param array $options Additional options
     * @return array Forecast report
     */
    public function forecastDataset(
        int $datasetId,
        string $metricName,
        string $method = 'ols',
        int $horizon = 6,
        array $options = []
    ): array {
        $interval = $options['interval'] ?? 'day';
        $persist = (bool) ($options['persist'] ?? true);

        // 1. Identify primary date column
        $dateCol = $this->db->queryOne(
            "SELECT column_name FROM dataset_columns 
             WHERE dataset_id = :did AND is_primary_date = 1 LIMIT 1",
            [':did' => $datasetId]
        );
        $dateColumnName = $dateCol ? $dateCol['column_name'] : null;

        // 2. Fetch time series data
        $series = $this->analyticsEngine->aggregateTimeSeries($datasetId, $metricName, $dateColumnName, $interval);
        if (count($series) < 3) {
            $series = $this->analyticsEngine->aggregateTimeSeries($datasetId, $metricName, $dateColumnName, 'day');
        }

        if (count($series) < 3) {
            throw new RuntimeException("Data tidak mencukupi untuk proyeksi peramalan (minimal 3 observasi).");
        }

        // 3. Choose algorithm
        $method = strtolower($method);
        switch ($method) {
            case 'holt_winters':
            case 'exponential':
                $alpha = (float) ($options['alpha'] ?? 0.3);
                $beta = (float) ($options['beta'] ?? 0.1);
                $forecast = $this->forecastHoltWinters($series, $horizon, $alpha, $beta, 'total');
                break;

            case 'moving_avg':
            case 'sma':
                $window = (int) ($options['window'] ?? 3);
                $forecast = $this->forecastMovingAverage($series, $horizon, $window, 'total');
                break;

            case 'ols':
            case 'linear':
            default:
                $forecast = $this->forecastLinearOLS($series, $horizon, 'total');
                break;
        }

        $forecast['dataset_id'] = $datasetId;
        $forecast['metric'] = $metricName;
        $forecast['interval'] = $interval;
        $forecast['historical_time_series'] = $series;
        $forecast['generated_at'] = date('c');

        // 4. Persist to analysis_results
        if ($persist) {
            AnalysisResult::store(
                $datasetId,
                $metricName,
                'forecast_projection',
                $forecast
            );
        }

        return $forecast;
    }

    /**
     * Generate human-readable label for next period step.
     */
    private function generateNextPeriodLabel(string $lastPeriod, int $step): string
    {
        // Try DateTime parsing
        $time = strtotime($lastPeriod);
        if ($time !== false) {
            // Check if format is daily (YYYY-MM-DD)
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastPeriod)) {
                return date('Y-m-d', strtotime("+{$step} days", $time));
            }
            // Monthly (YYYY-MM)
            if (preg_match('/^\d{4}-\d{2}$/', $lastPeriod)) {
                return date('Y-m', strtotime("+{$step} months", $time));
            }
            // Weekly (YYYY-Www)
            if (preg_match('/^\d{4}-W\d{2}$/', $lastPeriod)) {
                return "+{$step} minggu";
            }
            // Yearly (YYYY)
            if (preg_match('/^\d{4}$/', $lastPeriod)) {
                return (string) ((int) $lastPeriod + $step);
            }
        }

        return "+{$step} periode";
    }
}
