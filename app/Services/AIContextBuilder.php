<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Database;
use AnaBot\Models\AnalysisResult;
use RuntimeException;

/**
 * AnaBot AI Context Builder
 * 
 * Bridges deterministic mathematical computations from the SQLite Database
 * and AnalyticsEngine into clean, verified, grounded facts for the AI Analyst.
 * 
 * Enforces Rule 1.14 & Rule 2.3: Zero Hallucination Ground Truth.
 */
class AIContextBuilder
{
    private Database $db;
    private AnalyticsEngine $analyticsEngine;

    public function __construct(?Database $db = null, ?AnalyticsEngine $analyticsEngine = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->analyticsEngine = $analyticsEngine ?? new AnalyticsEngine($this->db);
    }

    /**
     * Build comprehensive verified factual context for one or more datasets.
     *
     * @param int[] $datasetIds Array of dataset IDs
     * @param array $options Optional configuration
     * @return array Structured facts, formatted markdown, and summary KPI cards
     */
    public function buildContext(array $datasetIds, array $options = []): array
    {
        $datasetsContext = [];
        $allCards = [];
        $markdownParts = [];

        foreach ($datasetIds as $did) {
            $did = (int) $did;
            if ($did <= 0) {
                continue;
            }

            $ctx = $this->buildDatasetContext($did, $options);
            if ($ctx) {
                $datasetsContext[$did] = $ctx;
                $allCards = array_merge($allCards, $ctx['kpi_cards'] ?? []);
                $markdownParts[] = $ctx['markdown_facts'];
            }
        }

        $fullMarkdown = implode("\n\n---\n\n", $markdownParts);

        return [
            'dataset_count' => count($datasetsContext),
            'datasets' => $datasetsContext,
            'markdown_facts' => $fullMarkdown,
            'summary_cards' => $allCards,
            'compiled_at' => date('c'),
        ];
    }

    /**
     * Build context for a single dataset.
     */
    public function buildDatasetContext(int $datasetId, array $options = []): ?array
    {
        $dataset = $this->db->queryOne(
            "SELECT d.*, ds.source_name, p.name AS platform_name, p.connector_code AS platform_code
             FROM datasets d
             LEFT JOIN data_sources ds ON d.data_source_id = ds.id
             LEFT JOIN platform_connections pc ON ds.connection_id = pc.id
             LEFT JOIN connectors p ON pc.connector_id = p.id
             WHERE d.id = :id",
            [':id' => $datasetId]
        );

        if (!$dataset) {
            return null;
        }

        // 1. Run or retrieve deterministic analysis
        $analysis = $this->analyticsEngine->runFullAnalysis($datasetId);

        // 2. Extract column metadata
        $columns = $this->db->query(
            "SELECT column_name, data_type, is_primary_date, is_metric, is_dimension
             FROM dataset_columns WHERE dataset_id = :did ORDER BY column_order ASC",
            [':did' => $datasetId]
        );

        // 3. Extract anomalies and highlights (peaks, valleys, major growth shifts)
        $highlights = $this->extractHighlights($analysis);

        // 4. Generate structured KPI cards for dashboard/chat UI
        $kpiCards = $this->generateKpiCards($dataset, $analysis);

        // 5. Generate formatted Markdown factual knowledge
        $markdownFacts = $this->formatFactsToMarkdown($dataset, $columns, $analysis, $highlights);

        return [
            'dataset_id' => $datasetId,
            'dataset_name' => $dataset['name'],
            'platform' => $dataset['platform_name'] ?? 'Custom Data',
            'record_count' => (int) $dataset['record_count'],
            'date_range' => [
                'start' => $dataset['first_record_at'],
                'end' => $dataset['latest_record_at'],
            ],
            'columns' => $columns,
            'analysis' => $analysis,
            'highlights' => $highlights,
            'kpi_cards' => $kpiCards,
            'markdown_facts' => $markdownFacts,
        ];
    }

    /**
     * Extract extreme events, peaks, valleys, and notable shifts.
     */
    private function extractHighlights(array $analysis): array
    {
        $highlights = [
            'peaks' => [],
            'valleys' => [],
            'largest_gain' => null,
            'largest_drop' => null,
            'strongest_correlation' => null,
            'dominant_category' => null,
        ];

        // Time series points
        if (!empty($analysis['time_series']['data'])) {
            $ts = $analysis['time_series']['data'];
            $metricName = $analysis['time_series']['metric'] ?? 'Total';

            $maxItem = null;
            $minItem = null;
            $maxGain = null;
            $maxDrop = null;

            foreach ($ts as $pt) {
                $val = (float) ($pt['total'] ?? 0);
                if ($maxItem === null || $val > $maxItem['total']) {
                    $maxItem = $pt;
                }
                if ($minItem === null || $val < $minItem['total']) {
                    $minItem = $pt;
                }

                // Growth checks
                if (isset($pt['pct_change']) && $pt['pct_change'] !== null) {
                    $pct = (float) $pt['pct_change'];
                    if ($maxGain === null || $pct > $maxGain['pct_change']) {
                        $maxGain = $pt;
                    }
                    if ($maxDrop === null || $pct < $maxDrop['pct_change']) {
                        $maxDrop = $pt;
                    }
                }
            }

            if ($maxItem) {
                $highlights['peaks'][] = [
                    'date' => $maxItem['period'],
                    'value' => (float) $maxItem['total'],
                    'metric' => $metricName,
                    'label' => 'Puncak Tertinggi',
                ];
            }
            if ($minItem) {
                $highlights['valleys'][] = [
                    'date' => $minItem['period'],
                    'value' => (float) $minItem['total'],
                    'metric' => $metricName,
                    'label' => 'Titik Terendah',
                ];
            }
            if ($maxGain && (float)$maxGain['pct_change'] > 0) {
                $highlights['largest_gain'] = [
                    'date' => $maxGain['period'],
                    'pct_change' => (float) $maxGain['pct_change'],
                    'value' => (float) $maxGain['total'],
                ];
            }
            if ($maxDrop && (float)$maxDrop['pct_change'] < 0) {
                $highlights['largest_drop'] = [
                    'date' => $maxDrop['period'],
                    'pct_change' => (float) $maxDrop['pct_change'],
                    'value' => (float) $maxDrop['total'],
                ];
            }
        }

        // Strongest Pearson correlation
        if (!empty($analysis['correlation']['top_pairs'])) {
            $highlights['strongest_correlation'] = $analysis['correlation']['top_pairs'][0];
        }

        // Dominant category from Pareto
        if (!empty($analysis['breakdowns'][0]['items'])) {
            $top = $analysis['breakdowns'][0]['items'][0];
            $highlights['dominant_category'] = [
                'dimension' => $analysis['breakdowns'][0]['dimension'],
                'category' => $top['category'],
                'total' => $top['total'],
                'share_pct' => $top['percentage'],
            ];
        }

        return $highlights;
    }

    /**
     * Generate high-level KPI cards.
     */
    private function generateKpiCards(array $dataset, array $analysis): array
    {
        $cards = [];

        // 1. Records count card
        $cards[] = [
            'title' => 'Total Observasi Data',
            'value' => number_format((int) $dataset['record_count']) . ' Baris',
            'badge' => $dataset['platform_name'] ?? 'Dataset',
            'type' => 'info',
        ];

        // 2. Primary metric total or average
        if (!empty($analysis['descriptive_stats'])) {
            $firstMetric = array_key_first($analysis['descriptive_stats']);
            $stats = $analysis['descriptive_stats'][$firstMetric];

            $cards[] = [
                'title' => 'Akumulasi ' . ucwords(str_replace('_', ' ', $firstMetric)),
                'value' => number_format((float) $stats['sum'], 2),
                'subtext' => 'Rata-rata: ' . number_format((float) $stats['mean'], 2) . ' | Median: ' . number_format((float) $stats['median'], 2),
                'type' => 'primary',
            ];
        }

        // 3. Trend direction & Growth
        if (!empty($analysis['time_series']['growth_summary'])) {
            $growth = $analysis['time_series']['growth_summary'];
            $trend = $analysis['time_series']['trend'] ?? [];
            $dir = strtoupper($trend['direction'] ?? 'SIDEWAYS');

            $cards[] = [
                'title' => 'Pertumbuhan & Arah Tren',
                'value' => ($growth['overall_growth_pct'] >= 0 ? '+' : '') . $growth['overall_growth_pct'] . '%',
                'subtext' => 'Tren OLS: ' . $dir . ' (R² = ' . ($trend['r_squared'] ?? 0) . ')',
                'badge' => $dir,
                'type' => $dir === 'UPTREND' ? 'success' : ($dir === 'DOWNTREND' ? 'danger' : 'warning'),
            ];
        }

        // 4. Dominant contributor
        if (!empty($analysis['breakdowns'][0]['items'])) {
            $top = $analysis['breakdowns'][0]['items'][0];
            $cards[] = [
                'title' => 'Kontributor Terbesar (' . $analysis['breakdowns'][0]['dimension'] . ')',
                'value' => $top['category'],
                'subtext' => $top['percentage'] . '% dari total volume (' . number_format($top['total'], 2) . ')',
                'type' => 'success',
            ];
        }

        return $cards;
    }

    /**
     * Format all deterministic analytics into strict factual Markdown.
     */
    private function formatFactsToMarkdown(
        array $dataset,
        array $columns,
        array $analysis,
        array $highlights
    ): string {
        $md = "### FAKTA DATA TERVERIFIKASI (MESIN ANALITIK PHP ANABOT)\n\n";

        // Dataset Overview
        $md .= sprintf(
            "- **Dataset:** %s (ID: %d)\n- **Platform Sumber:** %s\n- **Jumlah Baris Data:** %s baris\n- **Rentang Tanggal:** %s s/d %s\n\n",
            $dataset['name'],
            $dataset['id'],
            $dataset['platform_name'] ?? 'Konektor Mandiri',
            number_format((int) $dataset['record_count']),
            $dataset['first_record_at'] ?? 'N/A',
            $dataset['latest_record_at'] ?? 'N/A'
        );

        // 1. Descriptive Statistics Table
        if (!empty($analysis['descriptive_stats'])) {
            $md .= "#### 1. Statistik Deskriptif Deterministik Metrik:\n";
            $md .= "| Metrik | N | Total | Mean (Rata-rata) | Median | Min | Max | Std Dev | IQR |\n";
            $md .= "| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |\n";

            foreach ($analysis['descriptive_stats'] as $metric => $stats) {
                $md .= sprintf(
                    "| %s | %s | %s | %s | %s | %s | %s | %s | %s |\n",
                    $metric,
                    number_format($stats['count'] ?? 0),
                    number_format($stats['sum'] ?? 0, 2),
                    number_format($stats['mean'] ?? 0, 2),
                    number_format($stats['median'] ?? 0, 2),
                    number_format($stats['min'] ?? 0, 2),
                    number_format($stats['max'] ?? 0, 2),
                    number_format($stats['std_dev'] ?? 0, 2),
                    number_format($stats['iqr'] ?? 0, 2)
                );
            }
            $md .= "\n";
        }

        // 2. Time Series & Growth
        if (!empty($analysis['time_series']['growth_summary'])) {
            $growth = $analysis['time_series']['growth_summary'];
            $trend = $analysis['time_series']['trend'] ?? [];
            $metricName = $analysis['time_series']['metric'] ?? 'Metrik Utama';

            $md .= "#### 2. Pertumbuhan & Arah Tren Waktu ({$metricName}):\n";
            $md .= sprintf(
                "- **Jumlah Periode Terhitung:** %d periode (%s)\n" .
                "- **Nilai Periode Awal:** %s | **Nilai Periode Akhir:** %s\n" .
                "- **Pertumbuhan Kumulatif:** %s%%\n" .
                "- **Rata-rata Pertumbuhan per Periode:** %s%%\n" .
                "- **Compound Growth Rate (CAGR/CMGR):** %s%%\n" .
                "- **Klasifikasi Tren OLS:** %s (Kemiringan/Slope: %s per periode, Slope Ternormalisasi: %s%%, R²: %s)\n\n",
                $growth['total_periods'] ?? 0,
                $analysis['time_series']['interval'] ?? 'day',
                number_format($growth['first_value'] ?? 0, 2),
                number_format($growth['latest_value'] ?? 0, 2),
                ($growth['overall_growth_pct'] >= 0 ? '+' : '') . ($growth['overall_growth_pct'] ?? 0),
                ($growth['average_growth_pct'] >= 0 ? '+' : '') . ($growth['average_growth_pct'] ?? 0),
                $growth['compound_growth_rate'] ?? '0.00',
                strtoupper($trend['direction'] ?? 'SIDEWAYS'),
                number_format($trend['slope'] ?? 0, 4),
                $trend['slope_normalized_pct'] ?? 0,
                $trend['r_squared'] ?? 0
            );
        }

        // 3. Highlights & Extreme Shifts
        $md .= "#### 3. Sorotan Fluktuasi & Peristiwa Ekstrem:\n";
        if (!empty($highlights['peaks'])) {
            foreach ($highlights['peaks'] as $pk) {
                $md .= sprintf("- **Puncak Tertinggi:** Periode %s dengan nilai %s (%s)\n", $pk['date'], number_format($pk['value'], 2), $pk['metric']);
            }
        }
        if (!empty($highlights['valleys'])) {
            foreach ($highlights['valleys'] as $vl) {
                $md .= sprintf("- **Titik Terendah:** Periode %s dengan nilai %s (%s)\n", $vl['date'], number_format($vl['value'], 2), $vl['metric']);
            }
        }
        if (!empty($highlights['largest_gain'])) {
            $md .= sprintf("- **Lonjakan Pertumbuhan Terbesar:** Periode %s sebesar +%s%% (Nilai: %s)\n", $highlights['largest_gain']['date'], $highlights['largest_gain']['pct_change'], number_format($highlights['largest_gain']['value'], 2));
        }
        if (!empty($highlights['largest_drop'])) {
            $md .= sprintf("- **Penurunan Tercuram:** Periode %s sebesar %s%% (Nilai: %s)\n", $highlights['largest_drop']['date'], $highlights['largest_drop']['pct_change'], number_format($highlights['largest_drop']['value'], 2));
        }
        $md .= "\n";

        // 4. Pearson Correlation Matrix
        if (!empty($analysis['correlation']['top_pairs'])) {
            $md .= "#### 4. Korelasi Pearson Antar-Metrik:\n";
            $md .= "| Variabel A | Variabel B | Nilai r | Hubungan & Kekuatan Korelasi |\n";
            $md .= "| :--- | :--- | :---: | :--- |\n";

            foreach ($analysis['correlation']['top_pairs'] as $pair) {
                $md .= sprintf(
                    "| %s | %s | %s | %s |\n",
                    $pair['var_a'],
                    $pair['var_b'],
                    number_format($pair['r'], 3),
                    $pair['strength']
                );
            }
            $md .= "\n";
        }

        // 5. Dimension Breakdown & Pareto 80/20
        if (!empty($analysis['breakdowns'])) {
            foreach ($analysis['breakdowns'] as $bd) {
                $md .= sprintf("#### 5. Kontribusi Dimensi (%s vs %s):\n", $bd['dimension'], $bd['metric']);
                $md .= "| Peringkat | Kategori | Total Nilai | Porsi Kontribusi | Porsi Kumulatif | Status Pareto 80/20 |\n";
                $md .= "| :---: | :--- | :---: | :---: | :---: | :---: |\n";

                foreach (array_slice($bd['items'] ?? [], 0, 7) as $item) {
                    $md .= sprintf(
                        "| %d | %s | %s | %s%% | %s%% | %s |\n",
                        $item['rank'],
                        $item['category'],
                        number_format($item['total'], 2),
                        $item['percentage'],
                        $item['cumulative_percentage'],
                        $item['is_pareto_80'] ? '**Kontributor Utama (Top 80%)**' : 'Long Tail (Bottom 20%)'
                    );
                }
                $md .= "\n";
            }
        }

        return $md;
    }
}
