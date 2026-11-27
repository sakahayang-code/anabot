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
    <title><?= e($pageTitle ?? 'AnaBot') ?> - AI Analytics Robot</title>
    
    <!-- AnaBot Modern SaaS Stylesheet -->
    <link rel="stylesheet" href="/assets/css/anabot.css?v=<?= app_version() ?>">
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
    <script src="/assets/js/chart.min.js"></script>
    <!-- AnaBot Interactive Framework -->
    <script src="/assets/js/anabot.js?v=<?= app_version() ?>"></script>
</body>
</html>
