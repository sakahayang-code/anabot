<?php
declare(strict_types=1);

/**
 * AnaBot REST API Routes (/api/v1/)
 */

use AnaBot\Controllers\AuthController;
use AnaBot\Controllers\HomeController;
use AnaBot\Core\Router;

/** @var Router $router */

$router->group(['prefix' => '/api/v1'], function (Router $api) {
    // Public Health Check
    $api->get('/health', [HomeController::class, 'health']);

    // Authentication Endpoints
    $api->post('/auth/login', [AuthController::class, 'apiLogin']);

    // Protected API Endpoints
    $api->group(['middleware' => ['auth']], function (Router $authApi) {
        $authApi->post('/auth/logout', [AuthController::class, 'apiLogout']);
        $authApi->get('/auth/me', [AuthController::class, 'me']);
        $authApi->post('/auth/change-password', [AuthController::class, 'changePassword']);

        // Dashboard Live Statistics
        $authApi->get('/dashboard/stats', [\AnaBot\Controllers\DashboardController::class, 'statsApi']);

        // Data Explorer Endpoints (Phase 3)
        $authApi->get('/data/overview', [\AnaBot\Controllers\DataExplorerController::class, 'apiOverview']);
        $authApi->get('/datasets', [\AnaBot\Controllers\DataExplorerController::class, 'apiListDatasets']);
        $authApi->get('/datasets/{id}', [\AnaBot\Controllers\DataExplorerController::class, 'apiGetDataset']);
        $authApi->get('/datasets/{id}/records', [\AnaBot\Controllers\DataExplorerController::class, 'apiGetRecords']);
        $authApi->get('/datasets/{id}/schema', [\AnaBot\Controllers\DataExplorerController::class, 'apiGetSchema']);
        $authApi->get('/datasets/{id}/export', [\AnaBot\Controllers\DataExplorerController::class, 'exportCsv']);

        // Connector & Integration Endpoints (Phase 4)
        $authApi->get('/connectors', function () {
            $manager = new \AnaBot\Services\ConnectorManager();
            return \AnaBot\Core\Response::json(['success' => true, 'data' => $manager->listCatalog()]);
        });
        $authApi->get('/connections', function () {
            $model = new \AnaBot\Models\PlatformConnection();
            return \AnaBot\Core\Response::json(['success' => true, 'data' => $model->getAllWithConnectors()]);
        });
        $authApi->post('/connections', [\AnaBot\Controllers\ConnectorController::class, 'store']);
        $authApi->get('/connections/logs/{job_id}', function (int $jobId) {
            $queue = new \AnaBot\Services\SyncQueueManager();
            return \AnaBot\Core\Response::json(['success' => true, 'data' => $queue->getJobLogs($jobId)]);
        });
        $authApi->get('/connections/{id}', function (int $id) {
            $model = new \AnaBot\Models\PlatformConnection();
            $conn = $model->findById($id);
            if (!$conn) return \AnaBot\Core\Response::json(['success' => false, 'error' => 'Not found'], 404);
            return \AnaBot\Core\Response::json(['success' => true, 'data' => $conn]);
        });
        $authApi->post('/connections/{id}/test', [\AnaBot\Controllers\ConnectorController::class, 'test']);
        $authApi->post('/connections/{id}/sync', [\AnaBot\Controllers\ConnectorController::class, 'sync']);
        $authApi->post('/connections/{id}/disconnect', [\AnaBot\Controllers\ConnectorController::class, 'disconnect']);
        $authApi->get('/connections/{id}/history', [\AnaBot\Controllers\ConnectorController::class, 'history']);

        // Google Sheets Integration Endpoints (Phase 5)
        $authApi->get('/connectors/google-sheets/{id}/preview', [\AnaBot\Controllers\ConnectorController::class, 'googleSheetsPreviewApi']);
        $authApi->post('/connectors/google-sheets/{id}/import', [\AnaBot\Controllers\ConnectorController::class, 'googleSheetsImportApi']);

        // Analytics Engine Endpoints (Phase 6)
        $authApi->get('/analytics/datasets/{id}/summary', [\AnaBot\Controllers\AnalyticsController::class, 'summaryApi']);
        $authApi->get('/analytics/datasets/{id}/timeseries', [\AnaBot\Controllers\AnalyticsController::class, 'timeseriesApi']);
        $authApi->get('/analytics/datasets/{id}/correlation', [\AnaBot\Controllers\AnalyticsController::class, 'correlationApi']);
        $authApi->get('/analytics/datasets/{id}/breakdown', [\AnaBot\Controllers\AnalyticsController::class, 'breakdownApi']);
        $authApi->post('/analytics/datasets/{id}/recalculate', [\AnaBot\Controllers\AnalyticsController::class, 'recalculateApi']);

        // Predictive Analytics & Anomaly Endpoints (Phase 9)
        $authApi->get('/analytics/datasets/{id}/anomalies', [\AnaBot\Controllers\PredictiveController::class, 'anomaliesApi']);
        $authApi->get('/analytics/datasets/{id}/forecast', [\AnaBot\Controllers\PredictiveController::class, 'forecastApi']);
        $authApi->post('/analytics/datasets/{id}/interpret-predictive', [\AnaBot\Controllers\PredictiveController::class, 'interpretPredictiveApi']);

        // Multi-Provider AI Framework Endpoints (Phase 7)
        $authApi->get('/ai/providers', [\AnaBot\Controllers\AIController::class, 'providersApi']);
        $authApi->post('/ai/providers/{id}', [\AnaBot\Controllers\AIController::class, 'updateProviderApi']);
        $authApi->post('/ai/test/{code}', [\AnaBot\Controllers\AIController::class, 'testProviderApi']);
        $authApi->post('/ai/generate', [\AnaBot\Controllers\AIController::class, 'generateApi']);
        $authApi->get('/ai/usage', [\AnaBot\Controllers\AIController::class, 'usageApi']);

        // AI Analyst Endpoints (Phase 8)
        $authApi->get('/ai/sessions', [\AnaBot\Controllers\AIAnalystController::class, 'getSessions']);
        $authApi->post('/ai/sessions', [\AnaBot\Controllers\AIAnalystController::class, 'createSession']);
        $authApi->get('/ai/sessions/{id}', [\AnaBot\Controllers\AIAnalystController::class, 'getSession']);
        $authApi->put('/ai/sessions/{id}', [\AnaBot\Controllers\AIAnalystController::class, 'updateSession']);
        $authApi->delete('/ai/sessions/{id}', [\AnaBot\Controllers\AIAnalystController::class, 'deleteSession']);
        $authApi->post('/ai/sessions/{id}/messages', [\AnaBot\Controllers\AIAnalystController::class, 'sendMessage']);
        $authApi->post('/ai/sessions/{id}/quick-action', [\AnaBot\Controllers\AIAnalystController::class, 'quickAction']);
        $authApi->get('/ai/sessions/{id}/context', [\AnaBot\Controllers\AIAnalystController::class, 'getContextPreview']);
        $authApi->post('/ai/sessions/{id}/clear', [\AnaBot\Controllers\AIAnalystController::class, 'clearMessages']);

        // Report Engine Endpoints (Phase 10)
        $authApi->get('/reports', [\AnaBot\Controllers\ReportController::class, 'apiIndex']);
        $authApi->post('/reports', [\AnaBot\Controllers\ReportController::class, 'apiCreate']);
        $authApi->get('/reports/{id}', [\AnaBot\Controllers\ReportController::class, 'apiShow']);
        $authApi->delete('/reports/{id}', [\AnaBot\Controllers\ReportController::class, 'delete']);
        $authApi->get('/reports/{id}/download', [\AnaBot\Controllers\ReportController::class, 'download']);

        // Automation & Monitoring Endpoints (Phase 11)
        $authApi->get('/automation/health', [\AnaBot\Controllers\AutomationController::class, 'apiHealth']);
        $authApi->get('/automation/schedules', [\AnaBot\Controllers\AutomationController::class, 'apiSchedules']);
        $authApi->post('/automation/schedules', [\AnaBot\Controllers\AutomationController::class, 'apiCreateSchedule']);
        $authApi->post('/automation/schedules/{id}/run', [\AnaBot\Controllers\AutomationController::class, 'apiRunSchedule']);
        $authApi->get('/automation/jobs', [\AnaBot\Controllers\AutomationController::class, 'apiJobs']);
        $authApi->get('/automation/notifications', [\AnaBot\Controllers\AutomationController::class, 'notifications']);
        $authApi->post('/automation/notifications/{id}/read', [\AnaBot\Controllers\AutomationController::class, 'readNotification']);
        $authApi->post('/automation/notifications/read-all', [\AnaBot\Controllers\AutomationController::class, 'readAllNotifications']);
    });
});

