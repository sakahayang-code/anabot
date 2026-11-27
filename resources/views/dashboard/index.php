<?php
declare(strict_types=1);

/**
 * AnaBot Dashboard View
 * Bento Grid, Interactive Charts, and Live SQLite Metrics
 */
?>

<!-- Welcome Banner -->
<div class="notice-banner">
    <div class="notice-icon">🤖</div>
    <div style="flex: 1;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: gap; gap: 0.5rem; margin-bottom: 0.25rem;">
            <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                Selamat Datang di AnaBot &bull; Personal Data Intelligence
            </h2>
            <span class="badge badge-actual">FASE 2: UI AKTIF</span>
        </div>
        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.75rem; line-height: 1.5;">
            Pusat pemantauan, analisis statistik deterministik, dan interpretasi berbasis AI. Seluruh data disimpan secara aman dalam database SQLite terisolasi dan siap dianalisis.
        </p>
        <div style="display: flex; gap: 0.6rem; flex-wrap: wrap;">
            <a href="/connectors/new" class="btn btn-primary btn-sm">
                <span>➕</span> Hubungkan Platform
            </a>
            <a href="/ai/new" class="btn btn-outline btn-sm">
                <span>⚡</span> Mulai Analisis AI
            </a>
            <a href="/explorer/datasets" class="btn btn-outline btn-sm">
                <span>📁</span> Buka Data Explorer
            </a>
        </div>
    </div>
</div>

<!-- Bento KPI Metric Cards (8 Core Metrics from SQLite) -->
<div class="bento-grid">
    <!-- 1. Connected Platforms -->
    <div class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">Platform Terhubung</span>
            <div class="kpi-icon-wrapper icon-blue">🔌</div>
        </div>
        <div class="kpi-value num"><?= number_format($connectedPlatformsCount) ?></div>
        <div class="kpi-footer">
            <span class="badge badge-actual">Data Aktual</span>
            <a href="/connectors" style="color: var(--brand-primary); text-decoration: none; font-weight: 600;">Lihat &rarr;</a>
        </div>
    </div>

    <!-- 2. Total Datasets -->
    <div class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">Total Datasets</span>
            <div class="kpi-icon-wrapper icon-green">📁</div>
        </div>
        <div class="kpi-value num"><?= number_format($datasetsCount) ?></div>
        <div class="kpi-footer">
            <span class="badge badge-actual">Data Aktual</span>
            <a href="/explorer/datasets" style="color: var(--brand-primary); text-decoration: none; font-weight: 600;">Kelola &rarr;</a>
        </div>
    </div>

    <!-- 3. Total Data Records -->
    <div class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">Total Baris Record</span>
            <div class="kpi-icon-wrapper icon-amber">📑</div>
        </div>
        <div class="kpi-value num"><?= number_format($recordsCount) ?></div>
        <div class="kpi-footer">
            <span class="badge badge-actual">Data Aktual</span>
            <a href="/explorer/records" style="color: var(--brand-primary); text-decoration: none; font-weight: 600;">Telusuri &rarr;</a>
        </div>
    </div>

    <!-- 4. Latest Sync Status -->
    <div class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">Sinkronisasi Terakhir</span>
            <div class="kpi-icon-wrapper icon-purple">🔄</div>
        </div>
        <div class="kpi-value" style="font-size: 1.25rem; font-weight: 700;">
            <?= !empty($latestSync) ? e(strtoupper($latestSync['status'])) : 'BELUM ADA' ?>
        </div>
        <div class="kpi-footer">
            <span><?= !empty($latestSync) ? substr((string) $latestSync['created_at'], 0, 16) : 'Menunggu koneksi' ?></span>
            <a href="/connectors/history" style="color: var(--brand-primary); text-decoration: none; font-weight: 600;">Log &rarr;</a>
        </div>
    </div>

    <!-- 5. Total Analyses -->
    <div class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">Sesi Analisis Selesai</span>
            <div class="kpi-icon-wrapper icon-blue">📊</div>
        </div>
        <div class="kpi-value num"><?= number_format($analysisCount) ?></div>
        <div class="kpi-footer">
            <span class="badge badge-actual">Data Aktual</span>
            <a href="/ai/history" style="color: var(--brand-primary); text-decoration: none; font-weight: 600;">Riwayat &rarr;</a>
        </div>
    </div>

    <!-- 6. Total Reports -->
    <div class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">Laporan Dibuat</span>
            <div class="kpi-icon-wrapper icon-green">📜</div>
        </div>
        <div class="kpi-value num"><?= number_format($reportCount) ?></div>
        <div class="kpi-footer">
            <span class="badge badge-actual">Data Aktual</span>
            <a href="/reports" style="color: var(--brand-primary); text-decoration: none; font-weight: 600;">Semua &rarr;</a>
        </div>
    </div>

    <!-- 7. AI Token Usage -->
    <div class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">Penggunaan Token AI</span>
            <div class="kpi-icon-wrapper icon-amber">🤖</div>
        </div>
        <div class="kpi-value num"><?= number_format($aiTokens) ?></div>
        <div class="kpi-footer">
            <span class="badge badge-actual">Data Aktual</span>
            <a href="/ai/usage" style="color: var(--brand-primary); text-decoration: none; font-weight: 600;">Rincian &rarr;</a>
        </div>
    </div>

    <!-- 8. Estimated AI Cost -->
    <div class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">Estimasi Biaya AI</span>
            <div class="kpi-icon-wrapper icon-purple">💵</div>
        </div>
        <div class="kpi-value num" style="color: var(--success);"><?= e($aiCostFormatted) ?></div>
        <div class="kpi-footer">
            <span class="badge badge-actual">Data Aktual</span>
            <a href="/ai/usage" style="color: var(--brand-primary); text-decoration: none; font-weight: 600;">Anggaran &rarr;</a>
        </div>
    </div>
</div>

<!-- Interactive Charts Section -->
<div class="charts-grid">
    <!-- Chart 1: Record Growth Time-Series -->
    <div class="chart-card">
        <div class="card-header-flex">
            <div>
                <h3 class="card-title">Pertumbuhan Volume Record Data</h3>
                <p class="card-subtitle">Akumulasi baris data ternormalisasi yang tersimpan di SQLite</p>
            </div>
            <?php if ($isGrowthDataDemo): ?>
                <span class="badge badge-demo" title="Data riil akan muncul setelah sinkronisasi konektor">DEMO: Belum Ada Data</span>
            <?php else: ?>
                <span class="badge badge-actual">DATA AKTUAL</span>
            <?php endif; ?>
        </div>
        <div class="chart-container-box">
            <canvas id="growthChartCanvas"></canvas>
        </div>
    </div>

    <!-- Chart 2: AI Token Distribution Donut -->
    <div class="chart-card">
        <div class="card-header-flex">
            <div>
                <h3 class="card-title">Distribusi Penggunaan AI</h3>
                <p class="card-subtitle">Porsi konsumsi token per provider</p>
            </div>
            <?php if ($isAiDataDemo): ?>
                <span class="badge badge-demo" title="Data riil akan tercatat setelah AI Orchestrator aktif">DEMO: 0 Token</span>
            <?php else: ?>
                <span class="badge badge-actual">DATA AKTUAL</span>
            <?php endif; ?>
        </div>
        <div class="chart-container-box">
            <canvas id="aiUsageDonutCanvas"></canvas>
        </div>
    </div>
</div>

<!-- Recent Connected Apps & Activity -->
<div class="data-table-wrapper">
    <div class="table-header-bar">
        <div>
            <h3 class="card-title">Platform Eksternal Terhubung</h3>
            <p class="card-subtitle">Konektor sumber data terdaftar di AnaBot</p>
        </div>
        <a href="/connectors/new" class="btn btn-primary btn-sm">
            <span>➕</span> Tambah Koneksi
        </a>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama Koneksi</th>
                    <th>Platform</th>
                    <th>Status</th>
                    <th>Interval Auto-Sync</th>
                    <th>Sinkronisasi Terakhir</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentConnections)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">🔌</div>
                            <div style="font-weight: 600; color: var(--text-secondary); margin-bottom: 0.25rem;">Belum ada platform yang terhubung</div>
                            <p style="font-size: 0.8rem; margin-bottom: 1rem;">Hubungkan Google Sheets, Database MySQL eksternal, atau Custom REST API untuk mulai mengumpulkan data.</p>
                            <a href="/connectors/new" class="btn btn-primary btn-sm">Hubungkan Sekarang</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentConnections as $conn): ?>
                        <tr>
                            <td><strong><?= e($conn['name']) ?></strong></td>
                            <td><?= e($conn['connector_name'] ?? 'Custom API') ?></td>
                            <td>
                                <span class="badge <?= $conn['status'] === 'connected' ? 'badge-actual' : 'badge-demo' ?>">
                                    <?= e(strtoupper($conn['status'])) ?>
                                </span>
                            </td>
                            <td><?= (int) $conn['sync_interval_minutes'] ?> Menit</td>
                            <td><?= e($conn['last_sync_at'] ?? 'Belum pernah') ?></td>
                            <td>
                                <a href="/connectors/<?= (int) $conn['id'] ?>" class="btn btn-outline btn-sm">Detail</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Injected Chart Data (Safe json_encode) -->
<script>
window.anabotChartData = {
    growthLabels: <?= json_encode($growthLabels, JSON_UNESCAPED_UNICODE) ?>,
    growthValues: <?= json_encode($growthValues) ?>,
    donutLabels: <?= json_encode($donutLabels, JSON_UNESCAPED_UNICODE) ?>,
    donutValues: <?= json_encode($donutValues) ?>
};
</script>
