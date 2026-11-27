<?php
declare(strict_types=1);

/**
 * AnaBot Sidebar Navigation Component
 * Covers all 10 core modules defined in Section 10 of Architecture Document
 */

$rawUri = $_SERVER['REQUEST_URI'] ?? '/dashboard';
$currentUri = parse_url($rawUri, PHP_URL_PATH) ?: '/dashboard';

// Strip subfolder prefix if present (e.g. /anabot)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptDir = trim(str_replace('\\', '/', dirname($scriptName)), '/');
if (!empty($scriptDir) && $scriptDir !== '.') {
    if (str_starts_with($currentUri, '/' . $scriptDir)) {
        $currentUri = substr($currentUri, strlen('/' . $scriptDir));
    } elseif (str_ends_with($scriptDir, '/public')) {
        $parentDir = substr($scriptDir, 0, -7);
        if (!empty($parentDir) && str_starts_with($currentUri, '/' . $parentDir)) {
            $currentUri = substr($currentUri, strlen('/' . $parentDir));
        }
    }
}
$currentUri = '/' . ltrim($currentUri, '/');

if (!function_exists('isNavActive')) {
    function isNavActive(string $path, string $currentUri): string {
        return str_starts_with($currentUri, $path) ? 'active' : '';
    }
}
?>
<aside class="app-sidebar">
    <div class="sidebar-header">
        <a href="<?= e(url('/dashboard')) ?>" class="sidebar-logo">
            <div class="logo-badge">A</div>
            <div>
                <div>AnaBot</div>
                <div style="font-size: 0.65rem; color: #94a3b8; font-weight: 400; line-height: 1;">AI Analytics Robot</div>
            </div>
        </a>
    </div>

    <nav class="sidebar-nav">
        <!-- 1. Dashboard -->
        <div class="nav-section">
            <a href="<?= e(url('/dashboard')) ?>" class="nav-link <?= $currentUri === '/' || $currentUri === '/dashboard' ? 'active' : '' ?>">
                <span class="nav-icon">📊</span>
                <span>Dashboard</span>
            </a>
        </div>

        <!-- 2. AI Analyst -->
        <div class="nav-section">
            <div class="nav-section-title">AI Analyst</div>
            <a href="<?= e(url('/ai/chat')) ?>" class="nav-link <?= isNavActive('/ai/chat', $currentUri) ?>">
                <span class="nav-icon">💬</span>
                <span>Chat Analyst</span>
            </a>
            <a href="<?= e(url('/ai/new')) ?>" class="nav-link <?= isNavActive('/ai/new', $currentUri) ?>">
                <span class="nav-icon">⚡</span>
                <span>New Analysis</span>
            </a>
            <a href="<?= e(url('/ai/history')) ?>" class="nav-link <?= isNavActive('/ai/history', $currentUri) ?>">
                <span class="nav-icon">📜</span>
                <span>Analysis History</span>
            </a>
        </div>

        <!-- 3. Data Sources -->
        <div class="nav-section">
            <div class="nav-section-title">Data Sources</div>
            <a href="<?= e(url('/connectors')) ?>" class="nav-link <?= isNavActive('/connectors', $currentUri) && !str_contains($currentUri, 'new') && !str_contains($currentUri, 'history') ? 'active' : '' ?>">
                <span class="nav-icon">🔌</span>
                <span>Connected Apps</span>
            </a>
            <a href="<?= e(url('/connectors/new')) ?>" class="nav-link <?= isNavActive('/connectors/new', $currentUri) ?>">
                <span class="nav-icon">➕</span>
                <span>Add Connection</span>
            </a>
            <a href="<?= e(url('/connectors/history')) ?>" class="nav-link <?= isNavActive('/connectors/history', $currentUri) ?>">
                <span class="nav-icon">🔄</span>
                <span>Sync History</span>
            </a>
        </div>

        <!-- 4. Data Explorer -->
        <div class="nav-section">
            <div class="nav-section-title">Data Explorer</div>
            <a href="<?= e(url('/explorer/datasets')) ?>" class="nav-link <?= isNavActive('/explorer/datasets', $currentUri) ?>">
                <span class="nav-icon">📁</span>
                <span>Datasets</span>
            </a>
            <a href="<?= e(url('/explorer/records')) ?>" class="nav-link <?= isNavActive('/explorer/records', $currentUri) ?>">
                <span class="nav-icon">📑</span>
                <span>Records Browser</span>
            </a>
            <a href="<?= e(url('/explorer/schema')) ?>" class="nav-link <?= isNavActive('/explorer/schema', $currentUri) ?>">
                <span class="nav-icon">🔍</span>
                <span>Schema Discovery</span>
            </a>
        </div>

        <!-- 5. Analytics -->
        <div class="nav-section">
            <div class="nav-section-title">Analytics</div>
            <a href="<?= e(url('/analytics/trends')) ?>" class="nav-link <?= isNavActive('/analytics/trends', $currentUri) ?>">
                <span class="nav-icon">📈</span>
                <span>Trends & Growth</span>
            </a>
            <a href="<?= e(url('/analytics/comparison')) ?>" class="nav-link <?= isNavActive('/analytics/comparison', $currentUri) ?>">
                <span class="nav-icon">⚖️</span>
                <span>Comparison</span>
            </a>
            <a href="<?= e(url('/analytics/anomalies')) ?>" class="nav-link <?= isNavActive('/analytics/anomalies', $currentUri) ?>">
                <span class="nav-icon">🚨</span>
                <span>Anomaly Detection</span>
            </a>
        </div>

        <!-- 6. Predictions -->
        <div class="nav-section">
            <a href="<?= e(url('/predictions')) ?>" class="nav-link <?= isNavActive('/predictions', $currentUri) ?>">
                <span class="nav-icon">🔮</span>
                <span>Predictions</span>
            </a>
        </div>

        <!-- 7. Reports -->
        <div class="nav-section">
            <div class="nav-section-title">Reports</div>
            <a href="<?= e(url('/reports')) ?>" class="nav-link <?= $currentUri === '/reports' ? 'active' : '' ?>">
                <span class="nav-icon">📑</span>
                <span>All Reports</span>
            </a>
            <a href="<?= e(url('/reports/scheduled')) ?>" class="nav-link <?= isNavActive('/reports/scheduled', $currentUri) ?>">
                <span class="nav-icon">⏰</span>
                <span>Scheduled Reports</span>
            </a>
        </div>

        <!-- 8. Automation -->
        <div class="nav-section">
            <div class="nav-section-title">Automation</div>
            <a href="<?= e(url('/automation/schedules')) ?>" class="nav-link <?= isNavActive('/automation/schedules', $currentUri) ?>">
                <span class="nav-icon">⏱️</span>
                <span>Sync Schedule</span>
            </a>
            <a href="<?= e(url('/automation/tasks')) ?>" class="nav-link <?= isNavActive('/automation/tasks', $currentUri) ?>">
                <span class="nav-icon">⚙️</span>
                <span>Tasks & Queue</span>
            </a>
        </div>

        <!-- 9. AI Management -->
        <div class="nav-section">
            <div class="nav-section-title">AI Management</div>
            <a href="<?= e(url('/ai/providers')) ?>" class="nav-link <?= isNavActive('/ai/providers', $currentUri) ?>">
                <span class="nav-icon">🤖</span>
                <span>Providers</span>
            </a>
            <a href="<?= e(url('/ai/models')) ?>" class="nav-link <?= isNavActive('/ai/models', $currentUri) ?>">
                <span class="nav-icon">🧠</span>
                <span>Models & Tokens</span>
            </a>
            <a href="<?= e(url('/ai/usage')) ?>" class="nav-link <?= isNavActive('/ai/usage', $currentUri) ?>">
                <span class="nav-icon">💰</span>
                <span>Usage & Cost</span>
            </a>
        </div>

        <!-- 10. Settings -->
        <div class="nav-section">
            <div class="nav-section-title">Settings</div>
            <a href="<?= e(url('/settings')) ?>" class="nav-link <?= isNavActive('/settings', $currentUri) ?>">
                <span class="nav-icon">🛠️</span>
                <span>System & Backups</span>
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="system-status-box">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                <span><span class="status-dot"></span>SQLite 3 (WAL)</span>
                <span style="color: #38bdf8; font-weight: 700;">OK</span>
            </div>
            <div style="color: #64748b; font-size: 0.7rem;">Single-User Isolation Mode</div>
        </div>
    </div>
</aside>
<div id="sidebarBackdrop" class="sidebar-backdrop"></div>
