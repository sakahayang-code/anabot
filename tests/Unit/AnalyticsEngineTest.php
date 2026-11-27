<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Core\Database;
use AnaBot\Core\MigrationRunner;
use AnaBot\Models\AnalysisResult;
use AnaBot\Services\AnalyticsEngine;

/**
 * Phase 6: Analytics Engine Automated Test Suite
 * 
 * Verifies pure PHP 8.4 mathematical and statistical ground truth calculations:
 * - Descriptive statistics (Mean, Median, Mode, Variance, StdDev, IQR)
 * - Time series aggregation & interval grouping
 * - Period-over-period growth & CAGR
 * - Moving averages (SMA & EMA)
 * - Trend slope & direction classification
 * - Pearson correlation coefficient (r = 1.0, r = -1.0, r = 0.0)
 * - Correlation matrix across multi-column dataset
 * - Dimension breakdown & Pareto 80/20 analysis
 * - Deterministic persistence into analysis_results
 */
class AnalyticsEngineTest
{
    private Database $db;
    private AnalyticsEngine $engine;

    public function run(): void
    {
        echo "Testing Analytics Engine (Pure PHP 8.4 Isolated Test DB)..." . PHP_EOL;

        // Use isolated testing database
        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $this->db = new Database($testDbPath);
        Database::setInstance($this->db);

        $runner = new MigrationRunner($this->db);
        $runner->run();

        // Seed base user and connector for foreign keys
        $now = date('c');
        $this->db->execute("INSERT INTO users (id, username, email, password_hash, status, created_at, updated_at) VALUES (1, 'tester', 'tester@anabot.local', 'h', 'active', :n, :n)", [':n' => $now]);
        $this->db->execute("INSERT INTO connectors (id, connector_code, name, category, auth_type, created_at) VALUES (1, 'google_sheets', 'Google Sheets', 'spreadsheet', 'oauth2', :n)", [':n' => $now]);

        $this->engine = new AnalyticsEngine($this->db);

        $this->testDescriptiveStatistics();
        $this->testGrowthCalculations();
        $this->testMovingAverages();
        $this->testTrendDetection();
        $this->testPearsonCorrelation();
        $this->testTimeSeriesAggregation();
        $this->testCorrelationMatrix();
        $this->testDimensionBreakdownAndPareto();
        $this->testAnalysisResultPersistence();

        echo "  [PASS] All 9 Analytics Engine test cases passed successfully." . PHP_EOL;

        // Cleanup
        unset($this->db);
        Database::setInstance(null);
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }
    }

    private function testDescriptiveStatistics(): void
    {
        // 1. Known distribution: [10, 20, 20, 40, 50, 60, 80] (N = 7)
        $data = [10, 20, 20, 40, 50, 60, 80];
        $stats = $this->engine->computeDescriptiveStats($data);

        assert($stats['count'] === 7, "Count must be 7.");
        assert($stats['sum'] === 280.0, "Sum must be 280.0.");
        assert($stats['mean'] === 40.0, "Mean must be 40.0.");
        assert($stats['median'] === 40.0, "Median must be 40.0.");
        assert($stats['mode'] === 20.0, "Mode must be 20.0.");
        assert($stats['min'] === 10.0, "Min must be 10.0.");
        assert($stats['max'] === 80.0, "Max must be 80.0.");
        assert($stats['range'] === 70.0, "Range must be 70.0.");
        assert(abs($stats['variance'] - 633.3333) < 0.01, "Sample variance must be ~633.33.");
        assert(abs($stats['std_dev'] - 25.1661) < 0.01, "Standard deviation must be ~25.17.");
        assert($stats['q1'] === 20.0, "Q1 must be 20.0.");
        assert($stats['q3'] === 60.0, "Q3 must be 60.0.");
        assert($stats['iqr'] === 40.0, "IQR must be 40.0.");

        // 2. Even count: [2, 4, 6, 8] (N = 4)
        $evenStats = $this->engine->computeDescriptiveStats([2, 4, 6, 8]);
        assert($evenStats['median'] === 5.0, "Median of [2,4,6,8] must be 5.0.");

        // 3. Single element edge case
        $single = $this->engine->computeDescriptiveStats([42]);
        assert($single['count'] === 1, "Count must be 1.");
        assert($single['mean'] === 42.0, "Mean must be 42.0.");
        assert($single['variance'] === 0.0, "Variance of single element must be 0.0.");
        assert($single['std_dev'] === 0.0, "StdDev of single element must be 0.0.");

        // 4. Empty array edge case
        $empty = $this->engine->computeDescriptiveStats([]);
        assert($empty['count'] === 0, "Count of empty array must be 0.");
        assert($empty['mean'] === 0.0, "Mean of empty array must be 0.0.");

        echo "  ✓ Descriptive statistics (Mean, Median, Mode, Variance, StdDev, IQR) verified." . PHP_EOL;
    }

    private function testGrowthCalculations(): void
    {
        $series = [
            ['period' => '2026-01', 'total' => 100.0],
            ['period' => '2026-02', 'total' => 150.0],
            ['period' => '2026-03', 'total' => 120.0],
            ['period' => '2026-04', 'total' => 180.0],
        ];

        $growth = $this->engine->computeGrowth($series, 'total');
        $periods = $growth['periods'];

        // Period 0: start
        assert($periods[0]['direction'] === 'start', "Period 0 direction must be start.");
        assert($periods[0]['percentage_change'] === 0.0, "Period 0 growth must be 0%.");

        // Period 1: 100 -> 150 (+50%)
        assert($periods[1]['absolute_change'] === 50.0, "Period 1 change must be +50.0.");
        assert($periods[1]['percentage_change'] === 50.0, "Period 1 pct must be +50.0%.");
        assert($periods[1]['direction'] === 'up', "Period 1 direction must be up.");

        // Period 2: 150 -> 120 (-20%)
        assert($periods[2]['absolute_change'] === -30.0, "Period 2 change must be -30.0.");
        assert($periods[2]['percentage_change'] === -20.0, "Period 2 pct must be -20.0%.");
        assert($periods[2]['direction'] === 'down', "Period 2 direction must be down.");

        // Period 3: 120 -> 180 (+50%)
        assert($periods[3]['absolute_change'] === 60.0, "Period 3 change must be +60.0.");
        assert($periods[3]['percentage_change'] === 50.0, "Period 3 pct must be +50.0%.");
        assert($periods[3]['direction'] === 'up', "Period 3 direction must be up.");

        // Summary
        $sum = $growth['summary'];
        assert($sum['total_periods'] === 4, "Total periods must be 4.");
        assert($sum['overall_growth_pct'] === 80.0, "Overall growth 100->180 must be +80.0%.");
        assert($sum['direction'] === 'up', "Overall direction must be up.");
        // CAGR: (180/100)^(1/3) - 1 = 1.8^(0.3333) - 1 = 1.2164 - 1 = ~21.64%
        assert(abs($sum['compound_growth_rate'] - 21.64) < 0.1, "Compound growth rate must be ~21.64%.");

        // Zero base test
        $zeroBase = [
            ['period' => '2026-01', 'total' => 0.0],
            ['period' => '2026-02', 'total' => 50.0],
        ];
        $zeroGrowth = $this->engine->computeGrowth($zeroBase, 'total');
        assert($zeroGrowth['periods'][1]['percentage_change'] === 100.0, "Zero-base growth must be handled safely as 100%.");

        echo "  ✓ Period-over-period growth rates and compound growth (CAGR) verified." . PHP_EOL;
    }

    private function testMovingAverages(): void
    {
        $series = [
            ['period' => 'P1', 'total' => 10.0],
            ['period' => 'P2', 'total' => 20.0],
            ['period' => 'P3', 'total' => 30.0],
            ['period' => 'P4', 'total' => 40.0],
            ['period' => 'P5', 'total' => 50.0],
        ];

        $withMa = $this->engine->computeMovingAverages($series, 3, 'total');

        // Window = 3
        // P1: 10
        assert($withMa[0]['sma'] === 10.0, "P1 SMA must be 10.0.");
        // P2: (10+20)/2 = 15.0
        assert($withMa[1]['sma'] === 15.0, "P2 SMA must be 15.0.");
        // P3: (10+20+30)/3 = 20.0
        assert($withMa[2]['sma'] === 20.0, "P3 SMA must be 20.0.");
        // P4: (20+30+40)/3 = 30.0
        assert($withMa[3]['sma'] === 30.0, "P4 SMA must be 30.0.");
        // P5: (30+40+50)/3 = 40.0
        assert($withMa[4]['sma'] === 40.0, "P5 SMA must be 40.0.");

        // EMA checks
        assert($withMa[0]['ema'] === 10.0, "Initial EMA must equal first value.");
        assert($withMa[4]['ema'] > 0, "EMA must be computed for all points.");

        echo "  ✓ Moving averages (SMA & EMA) verified." . PHP_EOL;
    }

    private function testTrendDetection(): void
    {
        // 1. Monotonically increasing -> uptrend
        $up = [
            ['period' => 'P1', 'total' => 10.0],
            ['period' => 'P2', 'total' => 20.0],
            ['period' => 'P3', 'total' => 30.0],
            ['period' => 'P4', 'total' => 40.0],
        ];
        $trendUp = $this->engine->detectTrend($up, 'total');
        assert($trendUp['direction'] === 'uptrend', "Monotonically increasing must be uptrend.");
        assert($trendUp['slope'] === 10.0, "Slope must be 10.0.");
        assert($trendUp['r_squared'] === 1.0, "R-squared must be 1.0 for perfect line.");

        // 2. Monotonically decreasing -> downtrend
        $down = [
            ['period' => 'P1', 'total' => 100.0],
            ['period' => 'P2', 'total' => 80.0],
            ['period' => 'P3', 'total' => 60.0],
            ['period' => 'P4', 'total' => 40.0],
        ];
        $trendDown = $this->engine->detectTrend($down, 'total');
        assert($trendDown['direction'] === 'downtrend', "Monotonically decreasing must be downtrend.");
        assert($trendDown['slope'] === -20.0, "Slope must be -20.0.");

        // 3. Flat / Sideways
        $flat = [
            ['period' => 'P1', 'total' => 50.0],
            ['period' => 'P2', 'total' => 50.1],
            ['period' => 'P3', 'total' => 49.9],
            ['period' => 'P4', 'total' => 50.0],
        ];
        $trendFlat = $this->engine->detectTrend($flat, 'total');
        assert($trendFlat['direction'] === 'sideways', "Flat series must be classified as sideways.");

        echo "  ✓ Trend slope, R², and direction classification (Uptrend/Downtrend/Sideways) verified." . PHP_EOL;
    }

    private function testPearsonCorrelation(): void
    {
        // 1. Perfect positive correlation (r = 1.0)
        $x = [1.0, 2.0, 3.0, 4.0, 5.0];
        $y = [2.0, 4.0, 6.0, 8.0, 10.0];
        $rPos = $this->engine->computePearsonCorrelation($x, $y);
        assert($rPos === 1.0, "Perfect positive correlation must be exactly 1.0.");

        $classPos = $this->engine->classifyCorrelationStrength($rPos);
        assert($classPos['strength_code'] === 'strong_positive', "r=1.0 must be classified as strong_positive.");

        // 2. Perfect negative correlation (r = -1.0)
        $x = [1.0, 2.0, 3.0, 4.0, 5.0];
        $y = [10.0, 8.0, 6.0, 4.0, 2.0];
        $rNeg = $this->engine->computePearsonCorrelation($x, $y);
        assert($rNeg === -1.0, "Perfect negative correlation must be exactly -1.0.");

        $classNeg = $this->engine->classifyCorrelationStrength($rNeg);
        assert($classNeg['strength_code'] === 'strong_negative', "r=-1.0 must be classified as strong_negative.");

        // 3. Uncorrelated / orthogonal data (r ≈ 0.0)
        $x = [1.0, 2.0, 3.0, 4.0, 5.0];
        $y = [4.0, 1.0, 5.0, 2.0, 4.0];
        $rZero = $this->engine->computePearsonCorrelation($x, $y);
        assert(abs($rZero) < 0.3, "Uncorrelated data must have |r| < 0.3.");

        // 4. Constant variable (zero variance)
        $const = [5.0, 5.0, 5.0, 5.0];
        $rConst = $this->engine->computePearsonCorrelation($x, $const);
        assert($rConst === 0.0, "Constant variable with zero variance must safely return 0.0.");

        echo "  ✓ Pearson correlation coefficient (r = 1.0, r = -1.0, r ≈ 0.0) and classification verified." . PHP_EOL;
    }

    private function testTimeSeriesAggregation(): void
    {
        $now = date('c');
        $this->db->execute(
            "INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at)
             VALUES (1, 1, 'Analytics Test Conn', 'connected', :now, :now)",
            [':now' => $now]
        );
        $connId = (int) $this->db->lastInsertId();

        $this->db->execute(
            "INSERT INTO data_sources (connection_id, external_source_id, source_name, created_at, updated_at)
             VALUES (:cid, 'src_1', 'Analytics Source', :now, :now)",
            [':cid' => $connId, ':now' => $now]
        );
        $sourceId = (int) $this->db->lastInsertId();

        $this->db->execute(
            "INSERT INTO datasets (data_source_id, name, description, record_count, created_at, updated_at)
             VALUES (:sid, 'Sales Dataset', 'Test analytics', 0, :now, :now)",
            [':sid' => $sourceId, ':now' => $now]
        );
        $datasetId = (int) $this->db->lastInsertId();

        $this->db->execute(
            "INSERT INTO dataset_columns (dataset_id, column_name, data_type, is_primary_date, is_metric, is_dimension, column_order, created_at)
             VALUES (:did, 'trans_date', 'DATETIME', 1, 0, 0, 0, :now),
                    (:did, 'amount', 'NUMERIC', 0, 1, 0, 1, :now),
                    (:did, 'category', 'TEXT', 0, 0, 1, 2, :now)",
            [':did' => $datasetId, ':now' => $now]
        );

        // Insert sample records across 3 days
        $sampleRecords = [
            ['trans_date' => '2026-05-01', 'amount' => 100.0, 'category' => 'Electronics'],
            ['trans_date' => '2026-05-01', 'amount' => 150.0, 'category' => 'Clothing'],
            ['trans_date' => '2026-05-02', 'amount' => 200.0, 'category' => 'Electronics'],
            ['trans_date' => '2026-05-03', 'amount' => 300.0, 'category' => 'Books'],
        ];

        foreach ($sampleRecords as $idx => $rec) {
            $extId = "row_" . ($idx + 1);
            $hash = hash('sha256', $extId);
            $json = json_encode($rec);
            $this->db->execute(
                "INSERT INTO data_records (dataset_id, external_id, record_date, record_hash, data_json, created_at, updated_at)
                 VALUES (:did, :eid, :d, :hash, :j, :now, :now)",
                [
                    ':did' => $datasetId,
                    ':eid' => $extId,
                    ':d' => $rec['trans_date'],
                    ':hash' => $hash,
                    ':j' => $json,
                    ':now' => $now,
                ]
            );
        }

        // Test aggregation
        $series = $this->engine->aggregateTimeSeries($datasetId, 'amount', 'trans_date', 'day');
        assert(count($series) === 3, "Must produce 3 distinct day periods.");

        // Day 1: 100 + 150 = 250
        assert($series[0]['period'] === '2026-05-01', "Day 1 period must be 2026-05-01.");
        assert($series[0]['record_count'] === 2, "Day 1 must have 2 records.");
        assert($series[0]['total'] === 250.0, "Day 1 total must be 250.0.");

        // Day 2: 200
        assert($series[1]['period'] === '2026-05-02', "Day 2 period must be 2026-05-02.");
        assert($series[1]['total'] === 200.0, "Day 2 total must be 200.0.");

        // Day 3: 300
        assert($series[2]['period'] === '2026-05-03', "Day 3 period must be 2026-05-03.");
        assert($series[2]['total'] === 300.0, "Day 3 total must be 300.0.");

        echo "  ✓ Time series aggregation from SQLite records (intervals, counts, sums) verified." . PHP_EOL;
    }

    private function testCorrelationMatrix(): void
    {
        $now = date('c');
        $dataset = $this->db->queryOne("SELECT id FROM datasets LIMIT 1");
        $datasetId = (int) $dataset['id'];

        // Add a second metric column 'quantity' to test correlation
        $this->db->execute(
            "INSERT INTO dataset_columns (dataset_id, column_name, data_type, is_primary_date, is_metric, is_dimension, column_order, created_at)
             VALUES (:did, 'quantity', 'INTEGER', 0, 1, 0, 3, :now)",
            [':did' => $datasetId, ':now' => $now]
        );

        // Update data_records to include 'quantity' (positively correlated with 'amount')
        // Record 1: amount 100, qty 1
        // Record 2: amount 150, qty 2
        // Record 3: amount 200, qty 3
        // Record 4: amount 300, qty 5
        $quantities = [1, 2, 3, 5];
        $records = $this->db->queryAll("SELECT id, data_json FROM data_records WHERE dataset_id = :did ORDER BY id ASC", [':did' => $datasetId]);
        foreach ($records as $i => $rec) {
            $data = json_decode($rec['data_json'], true);
            $data['quantity'] = $quantities[$i];
            $this->db->execute(
                "UPDATE data_records SET data_json = :j WHERE id = :id",
                [':j' => json_encode($data), ':id' => $rec['id']]
            );
        }

        $matrix = $this->engine->computeCorrelationMatrix($datasetId, ['amount', 'quantity']);
        assert(count($matrix['columns']) === 2, "Matrix must have 2 columns.");
        assert($matrix['matrix']['amount']['amount'] === 1.0, "Diagonal correlation must be 1.0.");
        assert($matrix['matrix']['quantity']['quantity'] === 1.0, "Diagonal correlation must be 1.0.");

        $r = $matrix['matrix']['amount']['quantity'];
        assert($r > 0.9, "Correlation between amount and quantity must be strongly positive (> 0.9).");

        assert(count($matrix['top_pairs']) === 1, "Must find 1 metric pair.");
        assert($matrix['top_pairs'][0]['strength_code'] === 'strong_positive', "Pair must be classified as strong_positive.");

        echo "  ✓ Correlation matrix across multiple dataset columns verified." . PHP_EOL;
    }

    private function testDimensionBreakdownAndPareto(): void
    {
        $dataset = $this->db->queryOne("SELECT id FROM datasets LIMIT 1");
        $datasetId = (int) $dataset['id'];

        $breakdown = $this->engine->breakdownByDimension($datasetId, 'category', 'amount', 10);
        assert($breakdown['dimension'] === 'category', "Dimension must be category.");
        assert($breakdown['metric'] === 'amount', "Metric must be amount.");
        assert($breakdown['grand_total'] === 750.0, "Grand total must be 750.0 (100+150+200+300).");

        // Top category: Books (300) or Electronics (300 total from 2 records: 100+200 = 300)
        $items = $breakdown['items'];
        assert(count($items) === 3, "Must aggregate into 3 distinct categories (Electronics, Books, Clothing).");
        assert($items[0]['total'] === 300.0, "Rank 1 total must be 300.0.");
        assert($items[0]['percentage'] === 40.0, "Rank 1 percentage must be 40.0% (300/750).");
        assert($items[0]['is_pareto_80'] === true, "Rank 1 must be marked as is_pareto_80 = true.");

        echo "  ✓ Dimension breakdown and Pareto 80/20 contribution analysis verified." . PHP_EOL;
    }

    private function testAnalysisResultPersistence(): void
    {
        $dataset = $this->db->queryOne("SELECT id FROM datasets LIMIT 1");
        $datasetId = (int) $dataset['id'];

        $testPayload = [
            'mean' => 187.5,
            'sum' => 750.0,
            'trend' => 'uptrend',
            'verified' => true,
        ];

        // Store result
        $resultId = AnalysisResult::store($datasetId, 'amount', 'test_summary', $testPayload);
        assert($resultId > 0, "Stored result ID must be greater than 0.");

        // Retrieve result
        $retrieved = AnalysisResult::find($datasetId, 'amount', 'test_summary');
        assert($retrieved !== null, "Stored result must be retrievable.");
        assert($retrieved['calculated_value']['mean'] === 187.5, "Retrieved mean must match.");
        assert($retrieved['calculated_value']['trend'] === 'uptrend', "Retrieved trend must match.");

        // Idempotent replace verification (Update without duplicate rows)
        $updatedPayload = ['mean' => 200.0, 'sum' => 800.0];
        $newResultId = AnalysisResult::store($datasetId, 'amount', 'test_summary', $updatedPayload);
        $all = AnalysisResult::getByDataset($datasetId);
        $matching = array_filter($all, fn($r) => $r['analysis_type'] === 'test_summary');
        assert(count($matching) === 1, "Must maintain exactly 1 canonical record per dataset/metric/type.");

        echo "  ✓ Deterministic analysis result storage, JSON encoding, and canonical replacement verified." . PHP_EOL;
    }
}
