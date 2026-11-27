<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\Core\Auth;
use AnaBot\Core\Database;
use AnaBot\Core\Response;

/**
 * AnaBot Home & Health Check Controller
 */
class HomeController
{
    public function index(): void
    {
        if (Auth::check()) {
            Response::redirect('/dashboard');
        } else {
            Response::redirect('/login');
        }
    }

    public function dashboard(): void
    {
        $user = Auth::user();
        $db = Database::getInstance();
        $migrationCount = (int) $db->queryOne("SELECT COUNT(*) as cnt FROM schema_migrations")['cnt'];

        // Core Phase 1 transitional dashboard
        $html = '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - AnaBot</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 2rem; }
        .container { max-width: 900px; margin: 0 auto; background: #1e293b; padding: 2rem; border-radius: 12px; border: 1px solid #334155; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #334155; padding-bottom: 1rem; margin-bottom: 1.5rem; }
        h1 { margin: 0; color: #38bdf8; font-size: 1.5rem; }
        .user-badge { font-size: 0.875rem; color: #94a3b8; }
        .logout-btn { background: #ef4444; color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; text-decoration: none; font-size: 0.875rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .card { background: #0f172a; padding: 1.25rem; border-radius: 8px; border: 1px solid #334155; }
        .card-title { color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .card-value { font-size: 1.5rem; font-weight: 700; color: #38bdf8; margin-top: 0.5rem; }
        .notice { background: #0369a1; color: #e0f2fe; padding: 1rem; border-radius: 8px; font-size: 0.875rem; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>AnaBot Platform</h1>
                <div class="user-badge">Login sebagai: <strong>' . e($user['username'] ?? 'User') . '</strong> (' . e($user['email'] ?? '') . ')</div>
            </div>
            <form method="POST" action="/logout">
                ' . csrf_field() . '
                <button type="submit" class="logout-btn">Keluar (Logout)</button>
            </form>
        </div>

        <div class="grid">
            <div class="card">
                <div class="card-title">Status Sistem Core</div>
                <div class="card-value" style="color: #4ade80;">AKTIF (FASE 1)</div>
            </div>
            <div class="card">
                <div class="card-title">Migrasi Terpasang</div>
                <div class="card-value">' . $migrationCount . ' Batch</div>
            </div>
            <div class="card">
                <div class="card-title">Database Engine</div>
                <div class="card-value">SQLite 3 (WAL)</div>
            </div>
        </div>

        <div class="notice">
            <strong>FASE 1 Selesai:</strong> Core System (Router, Database, Session Auth, CSRF, Throttling, Migration System, Logger, dan Encryption) telah berhasil diuji dan berjalan normal. Antarmuka dashboard modern lengkap bergaya SaaS akan dibangun pada <strong>FASE 2</strong>.
        </div>
    </div>
</body>
</html>';

        echo $html;
        exit;
    }

    public function health(): void
    {
        $db = Database::getInstance();
        $integrity = $db->integrityCheck();

        Response::success([
            'status' => 'healthy',
            'app_version' => app_version(),
            'php_version' => PHP_VERSION,
            'sqlite_version' => \SQLite3::version()['versionString'],
            'database_integrity' => $integrity ? 'ok' : 'corrupted',
            'environment' => config('app.env', 'development'),
            'timestamp' => date('c'),
        ], 'AnaBot Core System is operational.');
    }
}
