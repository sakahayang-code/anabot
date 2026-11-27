<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Database;
use AnaBot\Core\Logger;
use AnaBot\Models\AnalysisResult;
use RuntimeException;

/**
 * AnaBot Analytics Engine (Pure PHP 8.4 Native)
 * 
 * Implements deterministic mathematical and statistical calculations:
 * - Descriptive Statistics (Mean, Median, Mode, Min, Max, Range, Variance, StdDev, IQR)
 * - Time Series & Growth Calculations (DoD, WoW, MoM, YoY, Moving Averages, Trend Slope)
 * - Correlation Analysis (Pearson r, Correlation Matrix, Categorical Strength)
 * - Dimension Breakdown & Pareto 80/20 Analysis
 * - Verifiable Calculation Audit Trail (Zero Hallucination Ground Truth)
 */
class AnalyticsEngine
{
    private Database $db;
    private Logger $logger;

    public function __construct(?Database $db = null, ?Logger $logger = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Compute comprehensive descriptive statistics on an array of numbers.
     *
     * @param float[]|int[] $values Array of numeric values
     * @return array Descriptive statistics metrics
     */
    public function computeDescriptiveStats(array $values): array
    {
        // 1. Filter and clean numeric values
        $clean = [];
        foreach ($values as $v) {
            if (is_numeric($v)) {
                $clean[] = (float) $v;
            }
        }

        $n = count($clean);
        if ($n === 0) {
            return [
                'count' => 0,
                'sum' => 0.0,
                'mean' => 0.0,
                'median' => 0.0,
                'mode' => 0.0,
                'min' => 0.0,
                'max' => 0.0,
                'range' => 0.0,
                'variance' => 0.0,
                'std_dev' => 0.0,
                'q1' => 0.0,
                'q3' => 0.0,
                'iqr' => 0.0,
                'formula_notes' => 'No numeric values provided.',
            ];
        }

        // Sort ascending for median and quantiles
        sort($clean, SORT_NUMERIC);

        // Sum and Mean
        $sum = array_sum($clean);
        $mean = $sum / $n;

        // Min, Max, Range
        $min = $clean[0];
        $max = $clean[$n - 1];
        $range = $max - $min;

        // Median
        $middle = (int) floor($n / 2);
        if ($n % 2 === 1) {
            $median = $clean[$middle];
        } else {
            $median = ($clean[$middle - 1] + $clean[$middle]) / 2.0;
        }

        // Mode
        $frequencies = [];
        foreach ($clean as $val) {
            $key = (string) round($val, 4);
            $frequencies[$key] = ($frequencies[$key] ?? 0) + 1;
        }
        arsort($frequencies);
        $modeKey = array_key_first($frequencies);
        $mode = (float) $modeKey;

        // Sample Variance & Standard Deviation
        $variance = 0.0;
        if ($n > 1) {
            $sumSquaredDiff = 0.0;
            foreach ($clean as $x) {
                $sumSquaredDiff += ($x - $mean) ** 2;
            }
            $variance = $sumSquaredDiff / ($n - 1);
        }
        $stdDev = sqrt($variance);

        // Quantiles: Q1 (25th percentile) and Q3 (75th percentile)
        $q1 = $this->calculatePercentile($clean, 0.25);
        $q3 = $this->calculatePercentile($clean, 0.75);
        $iqr = $q3 - $q1;

        return [
            'count' => $n,
            'sum' => round($sum, 4),
            'mean' => round($mean, 4),
            'median' => round($median, 4),
            'mode' => round($mode, 4),
            'min' => round($min, 4),
            'max' => round($max, 4),
            'range' => round($range, 4),
            'variance' => round($variance, 4),
            'std_dev' => round($stdDev, 4),
            'q1' => round($q1, 4),
            'q3' => round($q3, 4),
            'iqr' => round($iqr, 4),
            'formula_notes' => "Calculated on {$n} sample records using Bessel's correction (n-1) for sample variance.",
        ];
    }

    /**
     * Compute descriptive statistics for a specific column in a dataset directly from SQLite.
     */
    public function getColumnStats(int $datasetId, string $columnName, bool $persist = false): array
    {
        $rows = $this->db->queryAll(
            "SELECT json_extract(data_json, '$.' || :col) as val 
             FROM data_records 
             WHERE dataset_id = :did AND json_extract(data_json, '$.' || :col) IS NOT NULL",
            [':col' => $columnName, ':did' => $datasetId]
        );

        $values = array_column($rows, 'val');
        $stats = $this->computeDescriptiveStats($values);
        $stats['dataset_id'] = $datasetId;
        $stats['column_name'] = $columnName;

        if ($persist) {
            AnalysisResult::store($datasetId, $columnName, 'descriptive_stats', $stats);
        }

        return $stats;
    }

    /**
     * Aggregate time series data from SQLite records by time interval.
     *
     * @param int $datasetId Dataset ID
     * @param string $metricColumn Column containing numeric metric
     * @param string|null $dateColumn Column containing date (null = auto-detect)
     * @param string $interval Interval: 'day', 'week', 'month', 'year'
     * @return array Ordered array of time series periods
     */
    public function aggregateTimeSeries(
        int $datasetId,
        string $metricColumn,
        ?string $dateColumn = null,
        string $interval = 'day'
    ): array {
        // Auto-detect date column if not provided
        if (empty($dateColumn)) {
            $col = $this->db->queryOne(
                "SELECT column_name FROM dataset_columns 
                 WHERE dataset_id = :did AND is_primary_date = 1 LIMIT 1",
                [':did' => $datasetId]
            );
            $dateColumn = $col['column_name'] ?? 'record_date';
        }

        // SQLite strftime pattern for grouping
        $dateExtractor = "COALESCE(NULLIF(json_extract(data_json, '$.' || '{$dateColumn}'), ''), record_date)";
        $periodExpr = match (strtolower($interval)) {
            'year' => "strftime('%Y', {$dateExtractor})",
            'month' => "strftime('%Y-%m', {$dateExtractor})",
            'week' => "strftime('%Y-W%W', {$dateExtractor})",
            default => "strftime('%Y-%m-%d', {$dateExtractor})",
        };

        $sql = "SELECT 
                    {$periodExpr} as period,
                    COUNT(*) as record_count,
                    SUM(CAST(json_extract(data_json, '$.' || :metric) AS REAL)) as total,
                    AVG(CAST(json_extract(data_json, '$.' || :metric) AS REAL)) as average,
                    MIN(CAST(json_extract(data_json, '$.' || :metric) AS REAL)) as min_val,
                    MAX(CAST(json_extract(data_json, '$.' || :metric) AS REAL)) as max_val
                FROM data_records
                WHERE dataset_id = :did 
                  AND json_extract(data_json, '$.' || :metric) IS NOT NULL
                  AND json_extract(data_json, '$.' || :metric) != ''
                GROUP BY period
                HAVING period IS NOT NULL AND period != ''
                ORDER BY period ASC";

        $rows = $this->db->queryAll($sql, [
            ':did' => $datasetId,
            ':metric' => $metricColumn,
        ]);

        $series = [];
        foreach ($rows as $row) {
            $series[] = [
                'period' => (string) $row['period'],
                'record_count' => (int) $row['record_count'],
                'total' => round((float) $row['total'], 4),
                'average' => round((float) $row['average'], 4),
                'min' => round((float) $row['min_val'], 4),
                'max' => round((float) $row['max_val'], 4),
            ];
        }

        return $series;
    }

    /**
     * Compute period-over-period growth rates on time series data.
     *
     * @param array $timeSeries Output from aggregateTimeSeries
     * @param string $valueKey Key to compute growth on (e.g. 'total' or 'average')
     * @return array Enhanced time series with growth metrics and overall summary
     */
    public function computeGrowth(array $timeSeries, string $valueKey = 'total'): array
    {
        $count = count($timeSeries);
        if ($count === 0) {
            return [
                'periods' => [],
                'summary' => [
                    'total_periods' => 0,
                    'overall_growth_pct' => 0.0,
                    'average_growth_pct' => 0.0,
                    'compound_growth_rate' => 0.0,
                    'direction' => 'flat',
                ],
            ];
        }

        $enhanced = [];
        $growthPercentages = [];

        for ($i = 0; $i < $count; $i++) {
            $curr = (float) ($timeSeries[$i][$valueKey] ?? 0);
            $item = $timeSeries[$i];

            if ($i === 0) {
                $item['previous_value'] = null;
                $item['absolute_change'] = 0.0;
                $item['percentage_change'] = 0.0;
                $item['direction'] = 'start';
            } else {
                $prev = (float) ($timeSeries[$i - 1][$valueKey] ?? 0);
                $change = $curr - $prev;

                if ($prev == 0.0) {
                    if ($curr == 0.0) {
                        $pct = 0.0;
                    } elseif ($curr > 0) {
                        $pct = 100.0;
                    } else {
                        $pct = -100.0;
                    }
                } else {
                    $pct = ($change / abs($prev)) * 100.0;
                }

                $pct = round($pct, 2);
                $growthPercentages[] = $pct;

                $direction = 'flat';
                if ($pct > 0.5) {
                    $direction = 'up';
                } elseif ($pct < -0.5) {
                    $direction = 'down';
                }

                $item['previous_value'] = $prev;
                $item['absolute_change'] = round($change, 4);
                $item['percentage_change'] = $pct;
                $item['direction'] = $direction;
            }

            $enhanced[] = $item;
        }

        // Summary calculations
        $firstVal = (float) ($timeSeries[0][$valueKey] ?? 0);
        $lastVal = (float) ($timeSeries[$count - 1][$valueKey] ?? 0);
        $overallChange = $lastVal - $firstVal;

        if ($firstVal == 0.0) {
            $overallGrowthPct = $lastVal > 0 ? 100.0 : ($lastVal < 0 ? -100.0 : 0.0);
        } else {
            $overallGrowthPct = ($overallChange / abs($firstVal)) * 100.0;
        }

        $avgGrowthPct = !empty($growthPercentages) ? array_sum($growthPercentages) / count($growthPercentages) : 0.0;

        // Compound Growth Rate (CAGR / CMGR)
        $cagr = 0.0;
        if ($count > 1 && $firstVal > 0 && $lastVal > 0) {
            $periodsSpan = $count - 1;
            $cagr = ((($lastVal / $firstVal) ** (1.0 / $periodsSpan)) - 1.0) * 100.0;
        }

        $overallDirection = 'flat';
        if ($overallGrowthPct > 1.0) {
            $overallDirection = 'up';
        } elseif ($overallGrowthPct < -1.0) {
            $overallDirection = 'down';
        }

        return [
            'periods' => $enhanced,
            'summary' => [
                'total_periods' => $count,
                'first_value' => round($firstVal, 4),
                'latest_value' => round($lastVal, 4),
                'absolute_change' => round($overallChange, 4),
                'overall_growth_pct' => round($overallGrowthPct, 2),
                'average_growth_pct' => round($avgGrowthPct, 2),
                'compound_growth_rate' => round($cagr, 2),
                'direction' => $overallDirection,
            ],
        ];
    }

    /**
     * Compute Simple Moving Average (SMA) and Exponential Moving Average (EMA).
     *
     * @param array $timeSeries Array of period data
     * @param int $window Window size (e.g. 7 for 7-day moving average)
     * @param string $valueKey Metric key
     * @return array Enhanced series with sma and ema fields
     */
    public function computeMovingAverages(array $timeSeries, int $window = 7, string $valueKey = 'total'): array
    {
        $count = count($timeSeries);
        if ($count === 0) {
            return [];
        }

        $window = max(2, min($window, $count));
        $alpha = 2.0 / ($window + 1); // Multiplier for EMA

        $enhanced = [];
        $runningSum = 0.0;
        $windowValues = [];
        $prevEma = null;

        for ($i = 0; $i < $count; $i++) {
            $val = (float) ($timeSeries[$i][$valueKey] ?? 0);
            $item = $timeSeries[$i];

            // 1. SMA calculation
            $windowValues[] = $val;
            $runningSum += $val;
            if (count($windowValues) > $window) {
                $removed = array_shift($windowValues);
                $runningSum -= $removed;
            }
            $sma = $runningSum / count($windowValues);
            $item['sma'] = round($sma, 4);

            // 2. EMA calculation
            if ($prevEma === null) {
                $ema = $val;
            } else {
                $ema = ($val * $alpha) + ($prevEma * (1.0 - $alpha));
            }
            $prevEma = $ema;
            $item['ema'] = round($ema, 4);

            $enhanced[] = $item;
        }

        return $enhanced;
    }

    /**
     * Detect trend direction, slope, and fit using Ordinary Least Squares linear regression.
     *
     * @param array $timeSeries Time series array
     * @param string $valueKey Metric key
     * @return array Trend analysis metrics
     */
    public function detectTrend(array $timeSeries, string $valueKey = 'total'): array
    {
        $n = count($timeSeries);
        if ($n < 2) {
            return [
                'direction' => 'insufficient_data',
                'slope' => 0.0,
                'normalized_slope' => 0.0,
                'intercept' => 0.0,
                'r_squared' => 0.0,
                'description' => 'Membutuhkan minimal 2 titik data untuk menentukan arah tren.',
            ];
        }

        $xSum = 0.0;
        $ySum = 0.0;
        $xxSum = 0.0;
        $xySum = 0.0;
        $yValues = [];

        for ($i = 0; $i < $n; $i++) {
            $x = (float) $i;
            $y = (float) ($timeSeries[$i][$valueKey] ?? 0);
            $yValues[] = $y;

            $xSum += $x;
            $ySum += $y;
            $xxSum += $x * $x;
            $xySum += $x * $y;
        }

        $denom = ($n * $xxSum) - ($xSum * $xSum);
        if ($denom == 0.0) {
            $slope = 0.0;
            $intercept = $ySum / $n;
        } else {
            $slope = (($n * $xySum) - ($xSum * $ySum)) / $denom;
            $intercept = ($ySum - ($slope * $xSum)) / $n;
        }

        // R-squared calculation
        $yMean = $ySum / $n;
        $ssTot = 0.0;
        $ssRes = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $y = $yValues[$i];
            $yPred = ($slope * $i) + $intercept;
            $ssTot += ($y - $yMean) ** 2;
            $ssRes += ($y - $yPred) ** 2;
        }

        $rSquared = $ssTot > 0.0 ? max(0.0, min(1.0, 1.0 - ($ssRes / $ssTot))) : 1.0;

        // Normalized slope (relative change per period compared to mean)
        $normSlope = $yMean != 0.0 ? ($slope / abs($yMean)) * 100.0 : $slope;

        // Direction classification
        $direction = 'sideways';
        if ($normSlope > 1.5) {
            $direction = 'uptrend';
            $desc = "Tren meningkat dengan rata-rata kenaikan " . round($normSlope, 1) . "% per periode (R² = " . round($rSquared * 100, 1) . "%).";
        } elseif ($normSlope < -1.5) {
            $direction = 'downtrend';
            $desc = "Tren menurun dengan rata-rata penurunan " . round(abs($normSlope), 1) . "% per periode (R² = " . round($rSquared * 100, 1) . "%).";
        } else {
            $direction = 'sideways';
            $desc = "Tren relatif stabil / mendatar (fluktuasi berada di bawah ambang batas 1.5%).";
        }

        return [
            'direction' => $direction,
            'slope' => round($slope, 4),
            'normalized_slope_pct' => round($normSlope, 2),
            'intercept' => round($intercept, 4),
            'r_squared' => round($rSquared, 4),
            'description' => $desc,
        ];
    }

    /**
     * Compute Pearson Correlation Coefficient (r) between two numeric arrays.
     * Formula: r = Σ((x - x̄)(y - ȳ)) / sqrt(Σ(x - x̄)² * Σ(y - ȳ)²)
     *
     * @param float[] $x First variable array
     * @param float[] $y Second variable array
     * @return float|null Pearson correlation r between -1.0 and 1.0 (or null if insufficient data)
     */
    public function computePearsonCorrelation(array $x, array $y): ?float
    {
        $n = min(count($x), count($y));
        if ($n < 2) {
            return null;
        }

        // Align arrays to same length
        $x = array_slice($x, 0, $n);
        $y = array_slice($y, 0, $n);

        $xMean = array_sum($x) / $n;
        $yMean = array_sum($y) / $n;

        $sumDiffProd = 0.0;
        $xDiffSqSum = 0.0;
        $yDiffSqSum = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $xDiff = (float) $x[$i] - $xMean;
            $yDiff = (float) $y[$i] - $yMean;

            $sumDiffProd += $xDiff * $yDiff;
            $xDiffSqSum += $xDiff ** 2;
            $yDiffSqSum += $yDiff ** 2;
        }

        $denom = sqrt($xDiffSqSum * $yDiffSqSum);
        if ($denom == 0.0) {
            return 0.0; // Zero variance in at least one variable
        }

        $r = $sumDiffProd / $denom;

        // Clamp to [-1.0, 1.0] to protect against floating point precision drift
        return max(-1.0, min(1.0, round($r, 4)));
    }

    /**
     * Classify Pearson correlation strength into human-understandable categories.
     */
    public function classifyCorrelationStrength(?float $r): array
    {
        if ($r === null) {
            return [
                'strength_code' => 'insufficient_data',
                'label' => 'Data Tidak Cukup',
                'color' => '#888888',
            ];
        }

        if ($r >= 0.7) {
            return [
                'strength_code' => 'strong_positive',
                'label' => 'Sangat Kuat Positif',
                'color' => '#10b981', // green
            ];
        } elseif ($r >= 0.3) {
            return [
                'strength_code' => 'moderate_positive',
                'label' => 'Moderat Positif',
                'color' => '#3b82f6', // blue
            ];
        } elseif ($r > -0.3) {
            return [
                'strength_code' => 'weak_or_none',
                'label' => 'Lemah / Tidak Berkorelasi',
                'color' => '#6b7280', // gray
            ];
        } elseif ($r > -0.7) {
            return [
                'strength_code' => 'moderate_negative',
                'label' => 'Moderat Negatif',
                'color' => '#f59e0b', // amber
            ];
        } else {
            return [
                'strength_code' => 'strong_negative',
                'label' => 'Sangat Kuat Negatif',
                'color' => '#ef4444', // red
            ];
        }
    }

    /**
     * Compute correlation matrix across all numeric columns in a dataset.
     */
    public function computeCorrelationMatrix(int $datasetId, ?array $metricColumns = null): array
    {
        // 1. Resolve metric columns
        if (empty($metricColumns)) {
            $cols = $this->db->queryAll(
                "SELECT column_name FROM dataset_columns 
                 WHERE dataset_id = :did AND (is_metric = 1 OR data_type IN ('NUMERIC', 'INTEGER'))
                 ORDER BY column_order ASC",
                [':did' => $datasetId]
            );
            $metricColumns = array_column($cols, 'column_name');
        }

        $colCount = count($metricColumns);
        if ($colCount < 2) {
            return [
                'columns' => $metricColumns,
                'matrix' => [],
                'top_pairs' => [],
                'message' => 'Dibutuhkan minimal 2 kolom numerik untuk analisis korelasi.',
            ];
        }

        // 2. Fetch records data efficiently in single query
        $records = $this->db->queryAll(
            "SELECT data_json FROM data_records WHERE dataset_id = :did",
            [':did' => $datasetId]
        );

        $columnValues = [];
        foreach ($metricColumns as $col) {
            $columnValues[$col] = [];
        }

        foreach ($records as $rec) {
            $data = json_decode((string) $rec['data_json'], true) ?: [];
            foreach ($metricColumns as $col) {
                $val = $data[$col] ?? null;
                $columnValues[$col][] = is_numeric($val) ? (float) $val : 0.0;
            }
        }

        // 3. Compute pairwise correlations
        $matrix = [];
        $pairs = [];

        foreach ($metricColumns as $colA) {
            $matrix[$colA] = [];
            foreach ($metricColumns as $colB) {
                if ($colA === $colB) {
                    $matrix[$colA][$colB] = 1.0;
                } else {
                    $r = $this->computePearsonCorrelation($columnValues[$colA], $columnValues[$colB]);
                    $matrix[$colA][$colB] = $r;

                    // Collect upper triangle for ranking
                    if (strcmp($colA, $colB) < 0 && $r !== null) {
                        $classification = $this->classifyCorrelationStrength($r);
                        $pairs[] = [
                            'var_a' => $colA,
                            'var_b' => $colB,
                            'r' => $r,
                            'abs_r' => abs($r),
                            'strength' => $classification['label'],
                            'strength_code' => $classification['strength_code'],
                        ];
                    }
                }
            }
        }

        // Sort pairs by absolute correlation strength descending
        usort($pairs, fn($a, $b) => $b['abs_r'] <=> $a['abs_r']);

        return [
            'columns' => $metricColumns,
            'matrix' => $matrix,
            'top_pairs' => $pairs,
        ];
    }

    /**
     * Compute dimension breakdown with percentage contribution and Pareto 80/20 indicator.
     */
    public function breakdownByDimension(
        int $datasetId,
        string $dimensionColumn,
        string $metricColumn,
        int $limit = 10
    ): array {
        $sql = "SELECT 
                    COALESCE(NULLIF(json_extract(data_json, '$.' || :dim), ''), 'Tidak Diketahui') as category,
                    COUNT(*) as count,
                    SUM(CAST(json_extract(data_json, '$.' || :metric) AS REAL)) as total,
                    AVG(CAST(json_extract(data_json, '$.' || :metric) AS REAL)) as average
                FROM data_records
                WHERE dataset_id = :did
                GROUP BY category
                ORDER BY total DESC";

        $rows = $this->db->queryAll($sql, [
            ':did' => $datasetId,
            ':dim' => $dimensionColumn,
            ':metric' => $metricColumn,
        ]);

        $grandTotal = 0.0;
        foreach ($rows as $r) {
            $grandTotal += (float) $r['total'];
        }

        $items = [];
        $cumulativePct = 0.0;

        foreach ($rows as $index => $r) {
            $total = (float) $r['total'];
            $pct = $grandTotal > 0.0 ? ($total / $grandTotal) * 100.0 : 0.0;
            $cumulativePct += $pct;

            $items[] = [
                'rank' => $index + 1,
                'category' => (string) $r['category'],
                'count' => (int) $r['count'],
                'total' => round($total, 2),
                'average' => round((float) $r['average'], 2),
                'percentage' => round($pct, 2),
                'cumulative_percentage' => round(min(100.0, $cumulativePct), 2),
                'is_pareto_80' => $cumulativePct <= 80.0,
            ];
        }

        return [
            'dimension' => $dimensionColumn,
            'metric' => $metricColumn,
            'grand_total' => round($grandTotal, 2),
            'total_categories' => count($items),
            'items' => array_slice($items, 0, $limit),
        ];
    }

    /**
     * Run full automated analytics suite on a dataset and persist to analysis_results.
     */
    public function runFullAnalysis(int $datasetId): array
    {
        $startTime = microtime(true);

        // 1. Identify columns
        $columns = $this->db->queryAll(
            "SELECT * FROM dataset_columns WHERE dataset_id = :did ORDER BY column_order ASC",
            [':did' => $datasetId]
        );

        $metrics = [];
        $dimensions = [];
        $primaryDate = null;

        foreach ($columns as $c) {
            if ($c['is_primary_date'] && $primaryDate === null) {
                $primaryDate = $c['column_name'];
            }
            if ($c['is_metric']) {
                $metrics[] = $c['column_name'];
            }
            if ($c['is_dimension']) {
                $dimensions[] = $c['column_name'];
            }
        }

        // Fallback for metric if none explicitly flagged
        if (empty($metrics)) {
            foreach ($columns as $c) {
                if (in_array($c['data_type'], ['NUMERIC', 'INTEGER'], true)) {
                    $metrics[] = $c['column_name'];
                }
            }
        }

        $results = [
            'dataset_id' => $datasetId,
            'primary_date' => $primaryDate,
            'metrics_count' => count($metrics),
            'dimensions_count' => count($dimensions),
            'descriptive_stats' => [],
            'time_series' => null,
            'correlation' => null,
            'breakdowns' => [],
        ];

        // 2. Compute descriptive stats for all metrics
        foreach ($metrics as $metric) {
            $stats = $this->getColumnStats($datasetId, $metric, true);
            $results['descriptive_stats'][$metric] = $stats;
        }

        // 3. Time series & growth on primary metric
        if (!empty($metrics)) {
            $primaryMetric = $metrics[0];
            $series = $this->aggregateTimeSeries($datasetId, $primaryMetric, $primaryDate, 'day');
            if (count($series) < 3) {
                // Try month interval if daily is too sparse
                $series = $this->aggregateTimeSeries($datasetId, $primaryMetric, $primaryDate, 'month');
            }

            $growth = $this->computeGrowth($series, 'total');
            $withMa = $this->computeMovingAverages($growth['periods'], 7, 'total');
            $trend = $this->detectTrend($series, 'total');

            $timeSeriesResult = [
                'metric' => $primaryMetric,
                'series' => $withMa,
                'growth_summary' => $growth['summary'],
                'trend' => $trend,
            ];

            AnalysisResult::store($datasetId, $primaryMetric, 'time_series_growth', $timeSeriesResult);
            $results['time_series'] = $timeSeriesResult;
        }

        // 4. Correlation matrix
        if (count($metrics) >= 2) {
            $corr = $this->computeCorrelationMatrix($datasetId, $metrics);
            AnalysisResult::store($datasetId, 'all_metrics', 'correlation_matrix', $corr);
            $results['correlation'] = $corr;
        }

        // 5. Dimension breakdowns
        if (!empty($dimensions) && !empty($metrics)) {
            $primaryDim = $dimensions[0];
            $primaryMet = $metrics[0];
            $breakdown = $this->breakdownByDimension($datasetId, $primaryDim, $primaryMet, 10);
            AnalysisResult::store($datasetId, "{$primaryDim}_{$primaryMet}", 'dimension_breakdown', $breakdown);
            $results['breakdowns'][] = $breakdown;
        }

        $results['execution_time_seconds'] = round(microtime(true) - $startTime, 4);

        return $results;
    }

    /**
     * Helper to calculate percentile from a sorted array of floats.
     */
    private function calculatePercentile(array $sorted, float $percentile): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return $sorted[0];
        }

        $index = ($n - 1) * $percentile;
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $weight = $index - $lower;

        return $sorted[$lower] + ($weight * ($sorted[$upper] - $sorted[$lower]));
    }
}
