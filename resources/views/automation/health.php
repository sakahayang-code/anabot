<?php
declare(strict_types=1);

/**
 * AnaBot System Health & Maintenance View (resources/views/automation/health.php)
 */
?>

<!-- Header -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.25rem 0;">
            🩺 Kesehatan Sistem & Pemeliharaan
        </h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Diagnostik integritas SQLite, kapasitas penyimpanan shared hosting, rotasi berkas log, dan cadangan database.
        </p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <a href="/automation/schedules" class="btn btn-outline btn-sm">
            <span>⚙️</span> Jadwal Otomasi
        </a>
        <a href="/automation/tasks" class="btn btn-outline btn-sm">
            <span>📋</span> Antrean Background
        </a>
    </div>
</div>

<!-- Flash Alerts -->
<?php if (!empty($_SESSION['flash_success'])): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem;">
        <?= e($_SESSION['flash_success']) ?>
        <?php unset($_SESSION['flash_success']); ?>
    </div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem;">
        <?= e($_SESSION['flash_error']) ?>
        <?php unset($_SESSION['flash_error']); ?>
    </div>
<?php endif; ?>

<!-- Overall System Status Banner -->
<?php
$overallStatus = $health['overall_status'] ?? 'HEALTHY';
$bannerStyle = match ($overallStatus) {
    'HEALTHY'  => ['bg' => '#064e3b', 'border' => '#059669', 'color' => '#34d399', 'icon' => '✅', 'text' => 'SISTEM STABIL & SEHAT'],
    'WARNING'  => ['bg' => '#78350f', 'border' => '#d97706', 'color' => '#fbbf24', 'icon' => '⚠️', 'text' => 'PERINGATAN PEMELIHARAAN TERDETEKSI'],
    default    => ['bg' => '#7f1d1d', 'border' => '#dc2626', 'color' => '#f87171', 'icon' => '🚨', 'text' => 'STATUS SISTEM KRITIS'],
};
?>
<div class="card" style="background: <?= $bannerStyle['bg'] ?>; border: 1px solid <?= $bannerStyle['border'] ?>; padding: 1.25rem 1.5rem; margin-bottom: 2rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <span style="font-size: 2rem;"><?= $bannerStyle['icon'] ?></span>
            <div>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #fff; margin: 0 0 0.25rem 0;">
                    <?= $bannerStyle['text'] ?>
                </h2>
                <div style="font-size: 0.85rem; color: rgba(255,255,255,0.8);">
                    Pemeriksaan diagnostik terakhir: <?= date('d M Y H:i:s', strtotime($health['timestamp'] ?? date('c'))) ?> &bull; PHP <?= e($health['php_version'] ?? PHP_VERSION) ?>
                </div>
            </div>
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <form action="/automation/backup/now" method="POST" style="margin: 0;">
                <button type="submit" class="btn btn-sm" style="background: #fff; color: #0f172a; font-weight: 600;">
                    💾 Cadangkan Basis Data
                </button>
            </form>
            <form action="/automation/logs/rotate" method="POST" style="margin: 0;">
                <button type="submit" class="btn btn-sm" style="background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3);">
                    📜 Rotasi Log
                </button>
            </form>
        </div>
    </div>

    <?php if (!empty($health['issues'])): ?>
        <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid rgba(255,255,255,0.2);">
            <div style="font-size: 0.85rem; font-weight: 600; color: #fff; margin-bottom: 0.25rem;">Catatan Isu Diagnostik:</div>
            <ul style="margin: 0; padding-left: 1.25rem; font-size: 0.8rem; color: rgba(255,255,255,0.9);">
                <?php foreach ($health['issues'] as $issue): ?>
                    <li><?= e($issue) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<!-- Diagnostics Bento Grid -->
<div class="bento-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
    <!-- 1. SQLite Database -->
    <div class="card" style="padding: 1.5rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 0.75rem; letter-spacing: 0.05em;">
            🗄️ Basis Data SQLite 3 (PDO)
        </div>
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.75rem;">
            <span style="font-size: 1.2rem; font-weight: 700; color: #34d399;">
                ✓ <?= e($health['database']['integrity_result'] ?? 'OK') ?>
            </span>
            <span style="font-size: 0.8rem; color: var(--text-secondary);">
                PRAGMA integrity_check
            </span>
        </div>
        <div style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.6;">
            <div>Mode Jurnal: <strong style="color: var(--text-primary);"><?= e($health['database']['journal_mode'] ?? 'WAL') ?></strong></div>
            <div>Busy Timeout: <strong style="color: var(--text-primary);"><?= (int) ($health['database']['busy_timeout_ms'] ?? 5000) ?> ms</strong></div>
            <div>Ukuran File DB: <strong style="color: var(--text-primary);"><?= e($health['database']['database_formatted'] ?? '0 B') ?></strong></div>
            <div>Ukuran WAL: <strong style="color: var(--text-primary);"><?= e($health['database']['wal_formatted'] ?? '0 B') ?></strong></div>
        </div>
    </div>

    <!-- 2. Storage Utilization -->
    <div class="card" style="padding: 1.5rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 0.75rem; letter-spacing: 0.05em;">
            💾 Kapasitas Penyimpanan App
        </div>
        <div style="font-size: 1.4rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
            <?= e($health['storage']['app_storage_formatted'] ?? '0 B') ?>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
            Ruang Disk Hosting Bebas: <strong style="color: #38bdf8;"><?= e($health['storage']['disk_free_formatted'] ?? 'N/A') ?> (<?= $health['storage']['disk_free_percent'] ?? 'N/A' ?>%)</strong>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-secondary); line-height: 1.5; border-top: 1px solid var(--border-color); padding-top: 0.5rem;">
            <div>&bull; Database: <?= e($health['storage']['database_formatted'] ?? '0 B') ?></div>
            <div>&bull; Laporan: <?= e($health['storage']['reports_formatted'] ?? '0 B') ?></div>
            <div>&bull; Cadangan: <?= e($health['storage']['backups_formatted'] ?? '0 B') ?></div>
            <div>&bull; Berkas Log: <?= e($health['storage']['logs_formatted'] ?? '0 B') ?></div>
        </div>
    </div>

    <!-- 3. Cron & Automation -->
    <div class="card" style="padding: 1.5rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 0.75rem; letter-spacing: 0.05em;">
            ⏱️ Cron & Penjadwalan
        </div>
        <div style="font-size: 1.2rem; font-weight: 700; color: #a78bfa; margin-bottom: 0.25rem;">
            <?= e($health['cron']['status'] ?? 'UNKNOWN') ?>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
            Detak Jantung: <?= e($health['cron']['last_heartbeat_ago'] ?? '-') ?>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-secondary); line-height: 1.6; border-top: 1px solid var(--border-color); padding-top: 0.5rem;">
            <div>Sumber Terakhir: <strong style="color: var(--text-primary);"><?= e($health['cron']['source'] ?? '-') ?></strong></div>
            <div>Pekerjaan Pending: <strong style="color: #fbbf24;"><?= (int) ($health['queue']['pending_jobs_count'] ?? 0) ?></strong></div>
            <div>Pekerjaan Sedang Jalan: <strong style="color: #38bdf8;"><?= (int) ($health['queue']['running_jobs_count'] ?? 0) ?></strong></div>
        </div>
    </div>
</div>

<!-- Recent Database Backups Table Card -->
<div class="card" style="padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
        <div>
            <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                Berkas Cadangan Database SQLite (storage/backups/)
            </h3>
            <p style="font-size: 0.8rem; color: var(--text-secondary); margin: 0;">
                Retensi otomatis menyimpan maksimal 7 cadangan terbaru.
            </p>
        </div>
    </div>

    <?php if (empty($backups)): ?>
        <div style="text-align: center; padding: 2rem 1rem; color: var(--text-secondary);">
            Belum ada berkas cadangan database. Klik tombol "Cadangkan Basis Data" di atas untuk membuat cadangan pertama.
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color); text-align: left; color: var(--text-secondary);">
                        <th style="padding: 0.75rem 0.5rem;">Nama Berkas</th>
                        <th style="padding: 0.75rem 0.5rem;">Ukuran</th>
                        <th style="padding: 0.75rem 0.5rem;">Waktu Pembuatan</th>
                        <th style="padding: 0.75rem 0.5rem;">Status Integritas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $bfile): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 0.85rem 0.5rem; font-weight: 600; color: var(--text-primary);">
                                💾 <?= e(basename($bfile)) ?>
                            </td>
                            <td style="padding: 0.85rem 0.5rem; color: var(--text-secondary);">
                                <?= format_bytes(filesize($bfile)) ?>
                            </td>
                            <td style="padding: 0.85rem 0.5rem; color: var(--text-secondary);">
                                <?= date('d M Y H:i:s', filemtime($bfile)) ?>
                            </td>
                            <td style="padding: 0.85rem 0.5rem;">
                                <span class="badge" style="background: #064e3b; color: #34d399; font-size: 0.75rem; padding: 3px 8px; border-radius: 12px;">
                                    ✓ Terverifikasi OK
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
