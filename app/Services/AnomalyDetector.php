<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Database;
use AnaBot\Core\Logger;
use AnaBot\Models\AnalysisResult;
use RuntimeException;

/**
 * AnaBot Anomaly Detection Engine (Pure PHP 8.4 Native)
 * 
 * Implements deterministic mathematical anomaly detection:
 * 1. John Tukey's Interquartile Range (IQR) Inner/Outer Fences
 * 2. Standardized Z-Score Method with Sample Variance & Bessel's Correction
 * 3. Sequential Time-Series Sudden Jump & Drop Detection
 * 
 * Enforces Rule 1.14 & Rule 2.3: Zero Hallucination Ground Truth.
 */
class AnomalyDetector
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
     * Detect outliers using Interquartile Range (IQR) method.
     * 
     * @param float[]|int[] $values Array of numeric values
     * @return array IQR metadata, fences, and detected outlier items
     */
    public function detectIqrOutliers(array $values): array
    {
        $clean = [];
        foreach ($values as $k => $v) {
            if (is_numeric($v)) {
                $clean[$k] = (float) $v;
            }
        }

        $n = count($clean);
        if ($n < 4) {
            return [
                'count' => $n,
                'q1' => 0.0,
                'median' => 0.0,
                'q3' => 0.0,
                'iqr' => 0.0,
                'lower_mild_fence' => 0.0,
                'upper_mild_fence' => 0.0,
                'lower_extreme_fence' => 0.0,
                'upper_extreme_fence' => 0.0,
                'outliers' => [],
            ];
        }

        $sorted = array_values($clean);
        sort($sorted, SORT_NUMERIC);

        // Percentiles via linear interpolation
        $q1 = $this->calculatePercentile($sorted, 25.0);
        $median = $this->calculatePercentile($sorted, 50.0);
        $q3 = $this->calculatePercentile($sorted, 75.0);
        $iqr = round($q3 - $q1, 4);

        $lowerMild = round($q1 - (1.5 * $iqr), 4);
        $upperMild = round($q3 + (1.5 * $iqr), 4);
        $lowerExtreme = round($q1 - (3.0 * $iqr), 4);
        $upperExtreme = round($q3 + (3.0 * $iqr), 4);

        $outliers = [];
        foreach ($clean as $idx => $val) {
            if ($val < $lowerMild || $val > $upperMild) {
                $isExtreme = ($val < $lowerExtreme || $val > $upperExtreme);
                $direction = $val > $upperMild ? 'high' : 'low';

                $outliers[] = [
                    'index' => $idx,
                    'value' => $val,
                    'direction' => $direction,
                    'severity' => $isExtreme ? 'critical' : 'warning',
                    'type' => $isExtreme ? 'EXTREME_OUTLIER' : 'MILD_OUTLIER',
                    'deviation_from_median' => round($val - $median, 4),
                    'distance_iqr' => $iqr > 0 ? round(abs($val - $median) / $iqr, 2) : 0.0,
                ];
            }
        }

        return [
            'count' => $n,
            'q1' => $q1,
            'median' => $median,
            'q3' => $q3,
            'iqr' => $iqr,
            'lower_mild_fence' => $lowerMild,
            'upper_mild_fence' => $upperMild,
            'lower_extreme_fence' => $lowerExtreme,
            'upper_extreme_fence' => $upperExtreme,
            'outlier_count' => count($outliers),
            'outliers' => $outliers,
        ];
    }

    /**
     * Detect anomalies using Standardized Z-Score Method.
     * 
     * @param float[]|int[] $values Array of numeric values
     * @param float $threshold Z-score cutoff (default 2.5)
     * @return array Z-score metadata and detected outlier items
     */
    public function detectZScoreOutliers(array $values, float $threshold = 2.5): array
    {
        $clean = [];
        foreach ($values as $k => $v) {
            if (is_numeric($v)) {
                $clean[$k] = (float) $v;
            }
        }

        $n = count($clean);
        if ($n < 3) {
            return [
                'count' => $n,
                'mean' => 0.0,
                'std_dev' => 0.0,
                'threshold' => $threshold,
                'outliers' => [],
            ];
        }

        $mean = array_sum($clean) / $n;

        // Sample Variance (Bessel's correction n-1)
        $sumSqDiff = 0.0;
        foreach ($clean as $v) {
            $sumSqDiff += ($v - $mean) ** 2;
        }
        $stdDev = sqrt($sumSqDiff / ($n - 1));

        if ($stdDev <= 1e-9) {
            // Constant series, no deviation possible
            return [
                'count' => $n,
                'mean' => round($mean, 4),
                'std_dev' => 0.0,
                'threshold' => $threshold,
                'outliers' => [],
            ];
        }

        $outliers = [];
        foreach ($clean as $idx => $val) {
            $z = ($val - $mean) / $stdDev;
            $absZ = abs($z);

            if ($absZ >= $threshold) {
                $severity = $absZ >= 3.0 ? 'critical' : 'warning';
                $direction = $z > 0 ? 'high' : 'low';

                $outliers[] = [
                    'index' => $idx,
                    'value' => $val,
                    'z_score' => round($z, 3),
                    'abs_z' => round($absZ, 3),
                    'direction' => $direction,
                    'severity' => $severity,
                    'deviation_from_mean' => round($val - $mean, 4),
                ];
            }
        }

        return [
            'count' => $n,
            'mean' => round($mean, 4),
            'std_dev' => round($stdDev, 4),
            'threshold' => $threshold,
            'outlier_count' => count($outliers),
            'outliers' => $outliers,
        ];
    }

    /**
     * Detect sudden jumps or steep drops in sequential time series.
     * 
     * @param array $timeSeries Array of points with period and valueKey
     * @param string $valueKey Key representing numeric metric
     * @param float $pctThreshold Percentage change threshold (default 40%)
     * @return array Detected shift anomalies
     */
    public function detectTimeSeriesShifts(
        array $timeSeries,
        string $valueKey = 'total',
        float $pctThreshold = 40.0
    ): array {
        $n = count($timeSeries);
        if ($n < 2) {
            return [];
        }

        $shifts = [];
        for ($i = 1; $i < $n; $i++) {
            $prev = (float) ($timeSeries[$i - 1][$valueKey] ?? 0.0);
            $curr = (float) ($timeSeries[$i][$valueKey] ?? 0.0);
            $period = $timeSeries[$i]['period'] ?? "Periode {$i}";

            if ($prev == 0.0) {
                if ($curr > 0.0) {
                    $shifts[] = [
                        'period' => $period,
                        'index' => $i,
                        'current_value' => $curr,
                        'previous_value' => $prev,
                        'pct_change' => 100.0,
                        'type' => 'ZERO_BASE_SPIKE',
                        'severity' => 'warning',
                        'description' => "Lonjakan dari basis nol ke {$curr}",
                    ];
                }
                continue;
            }

            $pctChange = round((($curr - $prev) / abs($prev)) * 100.0, 2);

            if (abs($pctChange) >= $pctThreshold) {
                $isDrop = ($pctChange < 0);
                $isExtreme = (abs($pctChange) >= ($pctThreshold * 2.0));

                $shifts[] = [
                    'period' => $period,
                    'index' => $i,
                    'current_value' => $curr,
                    'previous_value' => $prev,
                    'pct_change' => $pctChange,
                    'type' => $isDrop ? 'SUDDEN_DROP' : 'SUDDEN_SPIKE',
                    'severity' => $isExtreme ? 'critical' : 'warning',
                    'description' => ($isDrop ? 'Penurunan tajam' : 'Lonjakan tiba-tiba') . " sebesar {$pctChange}% dalam 1 periode",
                ];
            }
        }

        return $shifts;
    }

    /**
     * Run unified anomaly detection on a dataset metric.
     * Combines IQR, Z-Score, and Time Series shift methods.
     * 
     * @param int $datasetId Dataset ID
     * @param string $metricName Metric column name
     * @param array $options Configuration options
     * @return array Unified anomaly detection report
     */
    public function detectDatasetAnomalies(
        int $datasetId,
        string $metricName,
        array $options = []
    ): array {
        $interval = $options['interval'] ?? 'day';
        $zThreshold = (float) ($options['z_threshold'] ?? 2.5);
        $shiftThreshold = (float) ($options['shift_threshold'] ?? 40.0);
        $persist = (bool) ($options['persist'] ?? true);

        // 1. Identify primary date column
        $dateCol = $this->db->queryOne(
            "SELECT column_name FROM dataset_columns 
             WHERE dataset_id = :did AND is_primary_date = 1 LIMIT 1",
            [':did' => $datasetId]
        );
        $dateColumnName = $dateCol ? $dateCol['column_name'] : null;

        // 2. Fetch aggregated time series
        $series = $this->analyticsEngine->aggregateTimeSeries($datasetId, $metricName, $dateColumnName, $interval);
        if (count($series) < 3) {
            // If few aggregated intervals, fall back to individual records
            $series = $this->analyticsEngine->aggregateTimeSeries($datasetId, $metricName, $dateColumnName, 'day');
        }

        if (empty($series)) {
            return [
                'dataset_id' => $datasetId,
                'metric' => $metricName,
                'interval' => $interval,
                'total_points' => 0,
                'anomalies' => [],
                'stats' => null,
                'risk_level' => 'LOW',
            ];
        }

        $values = array_column($series, 'total');
        $periods = array_column($series, 'period');

        // 3. Execute Algorithms
        $iqrResult = $this->detectIqrOutliers($values);
        $zScoreResult = $this->detectZScoreOutliers($values, $zThreshold);
        $shiftResult = $this->detectTimeSeriesShifts($series, 'total', $shiftThreshold);

        // 4. Map & Harmonize detected anomalies into points
        $pointsMap = [];

        // Add IQR outliers
        foreach ($iqrResult['outliers'] as $out) {
            $idx = $out['index'];
            $period = $periods[$idx] ?? "P-{$idx}";
            $pointsMap[$period] = [
                'period' => $period,
                'index' => $idx,
                'value' => $out['value'],
                'iqr_type' => $out['type'],
                'iqr_severity' => $out['severity'],
                'z_score' => null,
                'shift_pct' => null,
                'methods' => ['iqr'],
                'severity' => $out['severity'],
                'reasons' => ["Nilai melampaui pagar IQR (" . ($out['direction'] === 'high' ? 'di atas pagar atas' : 'di bawah pagar bawah') . ")"],
            ];
        }

        // Merge Z-Score outliers
        foreach ($zScoreResult['outliers'] as $out) {
            $idx = $out['index'];
            $period = $periods[$idx] ?? "P-{$idx}";

            if (isset($pointsMap[$period])) {
                $pointsMap[$period]['z_score'] = $out['z_score'];
                $pointsMap[$period]['methods'][] = 'z_score';
                if ($out['severity'] === 'critical') {
                    $pointsMap[$period]['severity'] = 'critical';
                }
                $pointsMap[$period]['reasons'][] = "Z-Score tinggi: {$out['z_score']}σ (deviasi signifikan dari rata-rata)";
            } else {
                $pointsMap[$period] = [
                    'period' => $period,
                    'index' => $idx,
                    'value' => $out['value'],
                    'iqr_type' => null,
                    'iqr_severity' => null,
                    'z_score' => $out['z_score'],
                    'shift_pct' => null,
                    'methods' => ['z_score'],
                    'severity' => $out['severity'],
                    'reasons' => ["Z-Score ekstrim: {$out['z_score']}σ (deviasi {$out['deviation_from_mean']})"],
                ];
            }
        }

        // Merge Sequential Shifts
        foreach ($shiftResult as $shift) {
            $period = $shift['period'];
            if (isset($pointsMap[$period])) {
                $pointsMap[$period]['shift_pct'] = $shift['pct_change'];
                $pointsMap[$period]['methods'][] = 'shift';
                $pointsMap[$period]['reasons'][] = $shift['description'];
            } else {
                $pointsMap[$period] = [
                    'period' => $period,
                    'index' => $shift['index'],
                    'value' => $shift['current_value'],
                    'iqr_type' => null,
                    'iqr_severity' => null,
                    'z_score' => null,
                    'shift_pct' => $shift['pct_change'],
                    'methods' => ['shift'],
                    'severity' => $shift['severity'],
                    'reasons' => [$shift['description']],
                ];
            }
        }

        // Sort anomalies chronologically
        $anomaliesList = array_values($pointsMap);
        usort($anomaliesList, fn($a, $b) => $a['index'] <=> $b['index']);

        // Determine overall risk level
        $criticalCount = count(array_filter($anomaliesList, fn($a) => $a['severity'] === 'critical'));
        $totalAnomalies = count($anomaliesList);

        $riskLevel = 'LOW';
        if ($criticalCount >= 2 || $totalAnomalies >= 4) {
            $riskLevel = 'CRITICAL';
        } elseif ($criticalCount === 1 || $totalAnomalies >= 1) {
            $riskLevel = 'MODERATE';
        }

        $finalReport = [
            'dataset_id' => $datasetId,
            'metric' => $metricName,
            'interval' => $interval,
            'total_points' => count($series),
            'anomaly_count' => $totalAnomalies,
            'critical_count' => $criticalCount,
            'risk_level' => $riskLevel,
            'iqr_bounds' => [
                'q1' => $iqrResult['q1'],
                'median' => $iqrResult['median'],
                'q3' => $iqrResult['q3'],
                'iqr' => $iqrResult['iqr'],
                'lower_mild' => $iqrResult['lower_mild_fence'],
                'upper_mild' => $iqrResult['upper_mild_fence'],
            ],
            'zscore_stats' => [
                'mean' => $zScoreResult['mean'],
                'std_dev' => $zScoreResult['std_dev'],
                'threshold' => $zThreshold,
            ],
            'time_series_data' => $series,
            'anomalies' => $anomaliesList,
            'analyzed_at' => date('c'),
        ];

        // 5. Persist to analysis_results if requested
        if ($persist) {
            AnalysisResult::store(
                $datasetId,
                $metricName,
                'anomaly_detection',
                $finalReport
            );
        }

        return $finalReport;
    }

    /**
     * Linear interpolation percentile calculation.
     */
    private function calculatePercentile(array $sortedValues, float $percentile): float
    {
        $n = count($sortedValues);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return (float) $sortedValues[0];
        }

        // Rank index
        $index = ($percentile / 100.0) * ($n - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $weight = $index - $lower;

        return (float) ($sortedValues[$lower] * (1.0 - $weight) + $sortedValues[$upper] * $weight);
    }
}
