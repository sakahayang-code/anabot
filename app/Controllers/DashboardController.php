<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\Core\Database;
use AnaBot\Core\Response;

/**
 * AnaBot Dashboard Controller
 * 
 * Aggregates live database metrics from SQLite and renders the modern SaaS UI.
 */
class DashboardController
{
    public function index(): void
    {
        $stats = $this->getDashboardMetrics();

        // Buffer content of dashboard/index.php
        $pageTitle = 'Dashboard Utama';
        ob_start();
        extract($stats, EXTR_SKIP);
        require dirname(__DIR__, 2) . '/resources/views/dashboard/index.php';
        $content = ob_get_clean();

        // Render inside master layout
        Response::view('layouts/main', [
            'pageTitle' => $pageTitle,
            'content' => $content,
        ]);
    }

    public function statsApi(): void
    {
        $stats = $this->getDashboardMetrics();
        Response::success($stats, 'Dashboard statistics fetched successfully.');
    }

    private function getDashboardMetrics(): array
    {
        $db = Database::getInstance();

        // 1. Live Database Counts (Actual Data)
        $connCount = (int) $db->queryOne("SELECT COUNT(*) as cnt FROM platform_connections")['cnt'];
        $datasetCount = (int) $db->queryOne("SELECT COUNT(*) as cnt FROM datasets")['cnt'];
        $recordSum = (int) $db->queryOne("SELECT COALESCE(SUM(record_count), 0) as cnt FROM datasets")['cnt'];
        if ($recordSum === 0) {
            $recordSum = (int) $db->queryOne("SELECT COUNT(*) as cnt FROM data_records")['cnt'];
        }

        $analysisCount = (int) $db->queryOne("SELECT COUNT(*) as cnt FROM analysis_sessions")['cnt'];
        $reportCount = (int) $db->queryOne("SELECT COUNT(*) as cnt FROM reports")['cnt'];

        // AI Usage Stats
        $aiUsage = $db->queryOne("SELECT COALESCE(SUM(total_tokens), 0) as tokens, COALESCE(SUM(estimated_cost_cents), 0) as cost FROM ai_usage_logs");
        $aiTokens = (int) ($aiUsage['tokens'] ?? 0);
        $aiCostCents = (int) ($aiUsage['cost'] ?? 0);
        $aiCostFormatted = '$' . number_format($aiCostCents / 100, 2);

        // Latest Sync Job
        $latestSync = $db->queryOne("SELECT * FROM sync_jobs ORDER BY created_at DESC LIMIT 1");

        // Recent Platform Connections
        $recentConnections = $db->query("SELECT pc.*, c.name as connector_name, c.category 
                                          FROM platform_connections pc 
                                          LEFT JOIN connectors c ON pc.connector_id = c.id 
                                          ORDER BY pc.created_at DESC LIMIT 5");

        // Recent Sync Activity
        $recentSyncJobs = $db->query("SELECT sj.*, pc.name as connection_name 
                                      FROM sync_jobs sj 
                                      LEFT JOIN platform_connections pc ON sj.connection_id = pc.id 
                                      ORDER BY sj.created_at DESC LIMIT 5");

        // Recent Notifications
        $recentNotifications = $db->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 5");

        // Chart Data Preparation (Actual data if available, or labeled DEMO if fresh database)
        $isGrowthDataDemo = $recordSum === 0;
        $isAiDataDemo = $aiTokens === 0;

        if ($isGrowthDataDemo) {
            $growthLabels = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
            $growthValues = [0, 0, 0, 0, 0, 0, 0];
        } else {
            // Real time series aggregation from data_records
            $dailyRecords = $db->query("SELECT SUBSTR(record_date, 1, 10) as day, COUNT(*) as cnt 
                                         FROM data_records 
                                         WHERE record_date IS NOT NULL 
                                         GROUP BY day 
                                         ORDER BY day ASC LIMIT 7");
            if (!empty($dailyRecords)) {
                $growthLabels = array_column($dailyRecords, 'day');
                $growthValues = array_map('intval', array_column($dailyRecords, 'cnt'));
            } else {
                $growthLabels = ['Tersimpan'];
                $growthValues = [$recordSum];
            }
        }

        if ($isAiDataDemo) {
            $donutLabels = ['Gemini', 'OpenAI', 'Claude'];
            $donutValues = [0, 0, 0];
        } else {
            $providerUsage = $db->query("SELECT p.name, COALESCE(SUM(l.total_tokens), 0) as tokens 
                                         FROM ai_usage_logs l 
                                         JOIN ai_providers p ON l.provider_id = p.id 
                                         GROUP BY p.name");
            $donutLabels = array_column($providerUsage, 'name');
            $donutValues = array_map('intval', array_column($providerUsage, 'tokens'));
        }

        return [
            'connectedPlatformsCount' => $connCount,
            'datasetsCount' => $datasetCount,
            'recordsCount' => $recordSum,
            'analysisCount' => $analysisCount,
            'reportCount' => $reportCount,
            'aiTokens' => $aiTokens,
            'aiCostFormatted' => $aiCostFormatted,
            'latestSync' => $latestSync,
            'recentConnections' => $recentConnections,
            'recentSyncJobs' => $recentSyncJobs,
            'recentNotifications' => $recentNotifications,
            'isGrowthDataDemo' => $isGrowthDataDemo,
            'isAiDataDemo' => $isAiDataDemo,
            'growthLabels' => $growthLabels,
            'growthValues' => $growthValues,
            'donutLabels' => $donutLabels,
            'donutValues' => $donutValues,
        ];
    }
}
