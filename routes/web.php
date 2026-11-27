<?php
declare(strict_types=1);

/**
 * AnaBot Web Routes
 */

use AnaBot\Controllers\AIAnalystController;
use AnaBot\Controllers\AuthController;
use AnaBot\Controllers\DashboardController;
use AnaBot\Controllers\DataExplorerController;
use AnaBot\Controllers\HomeController;
use AnaBot\Controllers\ModulePlaceholderController;
use AnaBot\Core\Router;

/** @var Router $router */

$router->get('/', [HomeController::class, 'index']);

// Guest Authentication Routes
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);

// Protected Web Routes
$router->group(['middleware' => ['auth']], function (Router $auth) {
    $auth->post('/logout', [AuthController::class, 'logout'], ['csrf']);
    $auth->get('/dashboard', [DashboardController::class, 'index']);

    // 2. AI Analyst (Active Phase 8)
    $auth->get('/ai', [AIAnalystController::class, 'chatPage']);
    $auth->get('/ai/chat', [AIAnalystController::class, 'chatPage']);
    $auth->get('/ai/chat/{id}', [AIAnalystController::class, 'chatPage']);
    $auth->post('/ai/new', [AIAnalystController::class, 'newSession'], ['csrf']);
    $auth->get('/ai/history', [AIAnalystController::class, 'chatPage']);

    // 3. Data Sources (Active Phase 4)
    $auth->get('/connectors', [\AnaBot\Controllers\ConnectorController::class, 'index']);
    $auth->get('/connectors/create', [\AnaBot\Controllers\ConnectorController::class, 'create']);
    $auth->get('/connectors/new', [\AnaBot\Controllers\ConnectorController::class, 'create']);
    $auth->get('/connectors/history', [\AnaBot\Controllers\ConnectorController::class, 'historyPage']);
    $auth->get('/connectors/oauth/authorize/{code}', [\AnaBot\Controllers\ConnectorController::class, 'oauthAuthorize']);
    $auth->get('/connectors/oauth/callback', [\AnaBot\Controllers\ConnectorController::class, 'oauthCallback']);
    $auth->post('/connectors', [\AnaBot\Controllers\ConnectorController::class, 'store']);
    $auth->get('/connectors/{id}/google-sheets', [\AnaBot\Controllers\ConnectorController::class, 'googleSheetsImportPage']);
    $auth->get('/connectors/{id}/sheets', [\AnaBot\Controllers\ConnectorController::class, 'googleSheetsImportPage']);
    $auth->get('/connectors/{id}', [\AnaBot\Controllers\ConnectorController::class, 'show']);
    $auth->post('/connectors/{id}/settings', [\AnaBot\Controllers\ConnectorController::class, 'updateSettings']);
    $auth->post('/connectors/{id}/disconnect', [\AnaBot\Controllers\ConnectorController::class, 'disconnect']);

    // 4. Data Explorer (Active Phase 3)
    $auth->get('/explorer/datasets', [DataExplorerController::class, 'datasets']);
    $auth->get('/explorer/datasets/{id}', [DataExplorerController::class, 'records']);
    $auth->get('/explorer/datasets/{id}/records', [DataExplorerController::class, 'records']);
    $auth->get('/explorer/records', [DataExplorerController::class, 'records']);
    $auth->get('/explorer/datasets/{id}/schema', [DataExplorerController::class, 'schema']);
    $auth->get('/explorer/schema', [DataExplorerController::class, 'schema']);
    $auth->get('/explorer/datasets/{id}/export', [DataExplorerController::class, 'exportCsv']);
    $auth->post('/explorer/datasets/{id}/delete', [DataExplorerController::class, 'deleteDataset']);
    $auth->post('/explorer/columns/{id}/update', [DataExplorerController::class, 'updateColumn']);

    // 5. Analytics (Active Phase 6: Analytics Engine)
    $auth->get('/analytics', [\AnaBot\Controllers\AnalyticsController::class, 'trendsPage']);
    $auth->get('/analytics/trends', [\AnaBot\Controllers\AnalyticsController::class, 'trendsPage']);
    $auth->get('/analytics/trends/{id}', [\AnaBot\Controllers\AnalyticsController::class, 'trendsPage']);
    $auth->get('/analytics/dataset/{id}', [\AnaBot\Controllers\AnalyticsController::class, 'trendsPage']);
    $auth->get('/analytics/comparison', [\AnaBot\Controllers\AnalyticsController::class, 'comparisonPage']);
    $auth->get('/analytics/comparison/{id}', [\AnaBot\Controllers\AnalyticsController::class, 'comparisonPage']);

    // 5. Predictive Analytics & Anomaly Detection (Active Phase 9)
    $auth->get('/analytics/predictive', [\AnaBot\Controllers\PredictiveController::class, 'predictivePage']);
    $auth->get('/analytics/forecast', [\AnaBot\Controllers\PredictiveController::class, 'predictivePage']);
    $auth->get('/analytics/anomalies', [\AnaBot\Controllers\PredictiveController::class, 'predictivePage']);
    $auth->get('/predictions', [\AnaBot\Controllers\PredictiveController::class, 'predictivePage']);

    // 7. Reports (Roadmap Phase 10)
    $auth->get('/reports', function () {
        (new ModulePlaceholderController())->show(
            'Report Engine',
            10,
            'Generator dokumen laporan profesional dalam format PDF, XLSX, CSV, dan HTML mandiri.',
            [
                ['title' => 'Ekspor Multi-Format', 'desc' => 'Pembuatan dokumen PDF/XLSX/CSV secara otomatis dari database.'],
                ['title' => 'Storage Privat', 'desc' => 'Laporan tersimpan di storage terproteksi session autentikasi.']
            ]
        );
    });

    $auth->get('/reports/scheduled', function () {
        (new ModulePlaceholderController())->show(
            'Scheduled Reports',
            10,
            'Pengaturan pembuatan laporan berkala otomatis (harian, mingguan, bulanan).',
            [
                ['title' => 'Penjadwalan Otomatis', 'desc' => 'Terintegrasi langsung dengan CLI Cron Jobs hosting.']
            ]
        );
    });

    // 8. Automation (Roadmap Phase 11)
    $auth->get('/automation/schedules', function () {
        (new ModulePlaceholderController())->show(
            'Automation - Schedules',
            11,
            'Konfigurasi jadwal sinkronisasi dan backup database SQLite via CLI Cron.',
            [
                ['title' => 'Cron Job Runner', 'desc' => 'Eksekusi batch sinkronisasi pada interval yang telah ditentukan.']
            ]
        );
    });

    $auth->get('/automation/tasks', function () {
        (new ModulePlaceholderController())->show(
            'Automation - Tasks & Queue',
            11,
            'Monitoring antrean pekerjaan latar belakang (background queue).',
            [
                ['title' => 'Status Antrean', 'desc' => 'Pantau status tugas pending, running, completed, atau failed.']
            ]
        );
    });

    // 9. AI Management (Active Phase 7: Multi-Provider AI Framework)
    $auth->get('/ai/providers', [\AnaBot\Controllers\AIController::class, 'settingsPage']);
    $auth->get('/ai/models', [\AnaBot\Controllers\AIController::class, 'settingsPage']);
    $auth->get('/ai/usage', [\AnaBot\Controllers\AIController::class, 'settingsPage']);
    $auth->get('/settings/ai', [\AnaBot\Controllers\AIController::class, 'settingsPage']);

    // 10. Settings (Settings, Database, Backups)
    $auth->get('/settings', function () {
        (new ModulePlaceholderController())->show(
            'Settings & Database Protection',
            1,
            'Konfigurasi profil pengguna, pengaturan keamanan akun, dan alat pemeliharaan database SQLite.',
            [
                ['title' => 'Integritas Database SQLite', 'desc' => 'Jalankan PRAGMA integrity_check dan verifikasi mode WAL.'],
                ['title' => 'Backup Manual', 'desc' => 'Buat snapshot cadangan database instan melalui tombol dashboard.'],
                ['title' => 'Audit Log Keamanan', 'desc' => 'Pantau riwayat login dan perubahan parameter konfigurasi.']
            ]
        );
    });
});
