<?php
declare(strict_types=1);

/**
 * AnaBot Web Routes
 */

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

    // 2. AI Analyst (Roadmap Phase 8)
    $auth->get('/ai/chat', function () {
        (new ModulePlaceholderController())->show(
            'AI Analyst - Chat Analyst',
            8,
            'Antarmuka tanya-jawab dan percakapan analitik dengan grounding data riil dari SQLite.',
            [
                ['title' => 'Data-Grounded QA', 'desc' => 'Menjawab pertanyaan berbasis konteks data aktual yang telah dihitung statistik.'],
                ['title' => 'Anti-Halusinasi', 'desc' => 'AI dilarang mengarang angka statistik di luar hasil komputasi PHP.'],
                ['title' => 'Multi-Turn Sessions', 'desc' => 'Menyimpan riwayat percakapan dan ringkasan insight ke dalam database.']
            ]
        );
    });

    $auth->get('/ai/new', function () {
        (new ModulePlaceholderController())->show(
            'AI Analyst - New Analysis',
            8,
            'Mulai sesi analisis baru untuk dataset terpilih dengan template analisis eksekutif.',
            [
                ['title' => 'Pilih Dataset', 'desc' => 'Pilih dataset sumber yang telah disinkronisasi.'],
                ['title' => 'Mode Analisis', 'desc' => 'Eksplorasi tren, perbandingan metrik, atau deteksi masalah bisnis.']
            ]
        );
    });

    $auth->get('/ai/history', function () {
        (new ModulePlaceholderController())->show(
            'AI Analyst - History',
            8,
            'Daftar riwayat sesi percakapan analitik dan arsip insight yang pernah dibuat.',
            [
                ['title' => 'Pencarian Sesi', 'desc' => 'Filter riwayat analisis berdasarkan tanggal atau dataset.'],
                ['title' => 'Buka Ulang Sesi', 'desc' => 'Lanjutkan diskusi analitis dari titik terakhir.']
            ]
        );
    });

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

    // Anomaly Detection (Scheduled for Phase 9)

    $auth->get('/analytics/anomalies', function () {
        (new ModulePlaceholderController())->show(
            'Analytics - Anomaly Detection',
            9,
            'Deteksi dini data tidak wajar menggunakan metode statistik IQR dan Z-score.',
            [
                ['title' => 'Metode Z-Score', 'desc' => 'Mendeteksi deviasi ekstrim dari rata-rata distribusi data.'],
                ['title' => 'Metode IQR (Tukey)', 'desc' => 'Deteksi outlier yang tahan terhadap nilai ekstrim (robust outlier detection).']
            ]
        );
    });

    // 6. Predictions (Roadmap Phase 9)
    $auth->get('/predictions', function () {
        (new ModulePlaceholderController())->show(
            'Predictive Analytics',
            9,
            'Peramalan tren masa depan berdasarkan time series dengan asumsi dan rentang kepercayaan eksplisit.',
            [
                ['title' => 'Exponential Smoothing', 'desc' => 'Proyeksi tren periode berikutnya berdasarkan data historis.'],
                ['title' => 'Indikator Kepercayaan', 'desc' => 'Transparansi keterbatasan model prediksi tanpa klaim kepastian mutlak.']
            ]
        );
    });

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

    // 9. AI Management (Roadmap Phase 7)
    $auth->get('/ai/providers', function () {
        (new ModulePlaceholderController())->show(
            'AI Management - Providers',
            7,
            'Pengaturan Multi-Provider AI (Gemini, OpenAI, Claude, dan Compatible Providers).',
            [
                ['title' => 'Enkripsi API Key', 'desc' => 'Kunci API disimpan terenkripsi AES-256-GCM.'],
                ['title' => 'Provider Fallback', 'desc' => 'Otomatis beralih ke provider cadangan jika provider utama mengalami limit.']
            ]
        );
    });

    $auth->get('/ai/models', function () {
        (new ModulePlaceholderController())->show(
            'AI Management - Models',
            7,
            'Katalog model AI, ukuran konteks window, dan penetapan model default per tugas.',
            [
                ['title' => 'Pemilihan Model', 'desc' => 'Pilih model cerdas untuk analisis mendalam atau model cepat untuk ringkasan.']
            ]
        );
    });

    $auth->get('/ai/usage', function () {
        (new ModulePlaceholderController())->show(
            'AI Management - Usage & Cost',
            7,
            'Pemantauan konsumsi token dan estimasi biaya per request secara transparan.',
            [
                ['title' => 'Audit Penggunaan Token', 'desc' => 'Pencatatan token input, token output, dan waktu eksekusi milidetik.'],
                ['title' => 'Batas Anggaran Bulanan', 'desc' => 'Mencegah pembengkakan biaya API di luar batas yang ditentukan.']
            ]
        );
    });

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
