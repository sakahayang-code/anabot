<?php
declare(strict_types=1);

/**
 * AnaBot Master Dashboard Layout Template
 */
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#0b1120">
    <base href="<?= e(url('/')) ?>/">
    <title><?= e($pageTitle ?? 'AnaBot') ?> - AI Analytics Robot</title>
    
    <!-- AnaBot Modern SaaS Stylesheet -->
    <link rel="stylesheet" href="<?= e(url('assets/css/anabot.css?v=' . app_version())) ?>">

    <!-- Subdirectory-Aware Client Runtime & Fetch Interceptor -->
    <script>
        window.ANABOT_BASE_URL = <?= json_encode(rtrim(url('/'), '/')) ?>;
        (function() {
            const originalFetch = window.fetch;
            window.fetch = function(resource, init) {
                if (typeof resource === 'string') {
                    if (resource.startsWith('/')) {
                        const base = window.ANABOT_BASE_URL;
                        const basePath = new URL(base, window.location.origin).pathname.replace(/\/+$/, '');
                        if (basePath && !resource.startsWith(basePath + '/')) {
                            resource = basePath + resource;
                        }
                    }
                    resource = resource.replace(/([^:])\/{2,}/g, '$1/');
                }
                return originalFetch.call(this, resource, init);
            };
        })();
    </script>
</head>
<body>
    <div class="app-wrapper">
        <!-- Sidebar Navigation -->
        <?php require __DIR__ . '/sidebar.php'; ?>

        <!-- Main Content Area -->
        <main class="app-main">
            <!-- Top Header Navbar -->
            <?php require __DIR__ . '/header.php'; ?>

            <!-- Page Content Body -->
            <div class="app-content">
                <?= $content ?? '' ?>
            </div>

            <!-- Footer -->
            <footer class="app-footer">
                <div>&copy; <?= date('Y') ?> <strong>AnaBot</strong> &bull; AI Analytics Robot &bull; Personal Data Intelligence Platform</div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
                    SQLite 3 (WAL Mode) &bull; Single-User Security Architecture &bull; Pure PHP Native 8.4 Engine
                </div>
            </footer>
        </main>
    </div>

    <!-- Local Standalone Chart.js Bundle (Zero external CDN dependency) -->
    <script src="<?= e(url('assets/js/chart.min.js')) ?>"></script>
    <!-- AnaBot Interactive Framework -->
    <script src="<?= e(url('assets/js/anabot.js?v=' . app_version())) ?>"></script>
</body>
</html>
