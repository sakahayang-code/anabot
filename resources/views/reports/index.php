<?php
declare(strict_types=1);

/**
 * AnaBot Report Center Dashboard (resources/views/reports/index.php)
 */
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #34d399; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span>✅</span>
            <span><?= e($_SESSION['flash_success']) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; color: #34d399; cursor: pointer; font-size: 1.2rem;">&times;</button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #f87171; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span>⚠️</span>
            <span><?= e($_SESSION['flash_error']) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; color: #f87171; cursor: pointer; font-size: 1.2rem;">&times;</button>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- Welcome & Action Banner -->
<div class="notice-banner" style="margin-bottom: 1.5rem;">
    <div class="notice-icon">📑</div>
    <div style="flex: 1;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 0.5rem;">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                Pusat Laporan Eksekutif (Report Center)
            </h2>
            <div style="display: flex; gap: 0.5rem;">
                <a href="/reports/create" class="btn btn-primary btn-sm">
                    <span>➕</span> Buat Laporan Baru
                </a>
            </div>
        </div>
        <p style="font-size: 0.875rem; color: var(--text-secondary); margin: 0; line-height: 1.5;">
            Generator dokumen multi-format profesional (HTML interaktif, PDF siap cetak, XLSX spreadsheet, dan CSV streaming). Disimpan di direktori privat terproteksi dan dapat diunduh kapan saja.
        </p>
    </div>
</div>

<!-- Storage & Metrics Bento Grid -->
<div class="bento-grid" style="margin-bottom: 1.75rem;">
    <!-- 1. Total Reports -->
    <div class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">Total Dokumen Laporan</span>
            <div class="kpi-icon-wrapper icon-blue">📑</div>
        </div>
        <div class="kpi-value num"><?= number_format((int) ($storage['total_reports'] ?? 0)) ?></div>
        <div class="kpi-footer">
            <span class="badge badge-actual">Tersimpan di Storage</span>
        </div>
    </div>

    <!-- 2. Storage Used -->
    <div class="kpi-card">
        <div class="kpi-top">
            <span class="kpi-label">Kapasitas Penyimpanan</span>
            <div class="kpi-icon-wrapper icon-green">💾</div>
        </div>
        <div class="kpi-value num"><?= e($storage['total_formatted'] ?? '0 B') ?></div>
        <div class="kpi-footer">
            <span class="badge badge-actual">Folder: storage/reports</span>
        </div>
    </div>

    <!-- 3. Formats Breakdown -->
    <div class="kpi-card" style="grid-column: span 2;">
        <div class="kpi-top">
            <span class="kpi-label">Distribusi Format Dokumen</span>
            <div class="kpi-icon-wrapper icon-purple">📊</div>
        </div>
        <div style="display: flex; gap: 1.25rem; align-items: center; margin-top: 0.5rem; flex-wrap: wrap;">
            <?php
            $formatMap = ['html' => 'HTML', 'pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'];
            $typeCounts = [];
            foreach ($storage['by_type'] as $t) {
                $typeCounts[$t['report_type']] = $t['count'];
            }
            ?>
            <div style="display: flex; align-items: center; gap: 6px;">
                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #0284c7;"></span>
                <span style="font-size: 0.875rem; color: var(--text-secondary);">HTML: <strong><?= (int) ($typeCounts['html'] ?? 0) ?></strong></span>
            </div>
            <div style="display: flex; align-items: center; gap: 6px;">
                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #ef4444;"></span>
                <span style="font-size: 0.875rem; color: var(--text-secondary);">PDF: <strong><?= (int) ($typeCounts['pdf'] ?? 0) ?></strong></span>
            </div>
            <div style="display: flex; align-items: center; gap: 6px;">
                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #10b981;"></span>
                <span style="font-size: 0.875rem; color: var(--text-secondary);">XLSX: <strong><?= (int) ($typeCounts['xlsx'] ?? 0) ?></strong></span>
            </div>
            <div style="display: flex; align-items: center; gap: 6px;">
                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #f59e0b;"></span>
                <span style="font-size: 0.875rem; color: var(--text-secondary);">CSV: <strong><?= (int) ($typeCounts['csv'] ?? 0) ?></strong></span>
            </div>
        </div>
        <div class="kpi-footer">
            <span style="font-size: 0.75rem; color: var(--text-muted);">Mendukung unduhan privat & streaming memory-safe</span>
        </div>
    </div>
</div>

<!-- Filter Tabs -->
<div style="display: flex; gap: 0.5rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; overflow-x: auto;">
    <a href="/reports" class="btn <?= empty($currentType) ? 'btn-primary' : 'btn-outline' ?> btn-sm">
        Semua Format (<?= (int) ($storage['total_reports'] ?? 0) ?>)
    </a>
    <a href="/reports?type=html" class="btn <?= $currentType === 'html' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
        🌐 HTML (<?= (int) ($typeCounts['html'] ?? 0) ?>)
    </a>
    <a href="/reports?type=pdf" class="btn <?= $currentType === 'pdf' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
        📄 PDF (<?= (int) ($typeCounts['pdf'] ?? 0) ?>)
    </a>
    <a href="/reports?type=xlsx" class="btn <?= $currentType === 'xlsx' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
        📊 Excel (XLSX) (<?= (int) ($typeCounts['xlsx'] ?? 0) ?>)
    </a>
    <a href="/reports?type=csv" class="btn <?= $currentType === 'csv' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
        📑 CSV (<?= (int) ($typeCounts['csv'] ?? 0) ?>)
    </a>
</div>

<!-- Reports Table Card -->
<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3 class="card-title">Daftar Dokumen Laporan</h3>
        <span style="font-size: 0.8rem; color: var(--text-muted);">Menampilkan <?= count($reports) ?> dari <?= $totalReports ?> dokumen</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($reports)): ?>
            <div style="padding: 3.5rem 1.5rem; text-align: center;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">📑</div>
                <h4 style="font-size: 1.1rem; color: var(--text-primary); margin-bottom: 0.5rem;">Belum Ada Laporan</h4>
                <p style="font-size: 0.875rem; color: var(--text-secondary); max-width: 450px; margin: 0 auto 1.5rem auto;">
                    Anda belum membuat dokumen laporan. Buat laporan komprehensif sekarang untuk menganalisis statistik, anomali, dan proyeksi masa depan.
                </p>
                <a href="/reports/create" class="btn btn-primary">
                    <span>➕</span> Buat Laporan Pertama
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Format</th>
                            <th>Judul Laporan</th>
                            <th>Periode Data</th>
                            <th>Ukuran</th>
                            <th>Waktu Pembuatan</th>
                            <th style="text-align: right; width: 220px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                            <?php
                            $typeBadge = match ($r['report_type']) {
                                'pdf' => '<span class="badge" style="background: rgba(239, 68, 68, 0.2); color: #f87171; font-weight: 700;">PDF</span>',
                                'xlsx' => '<span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #34d399; font-weight: 700;">XLSX</span>',
                                'csv' => '<span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24; font-weight: 700;">CSV</span>',
                                default => '<span class="badge" style="background: rgba(2, 132, 199, 0.2); color: #38bdf8; font-weight: 700;">HTML</span>',
                            };
                            ?>
                            <tr>
                                <td><?= $typeBadge ?></td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-primary);"><?= e($r['title']) ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace;">
                                        <?= e(basename($r['file_path'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-size: 0.85rem; color: var(--text-secondary);">
                                        <?= e($r['date_range_start'] ?? '-') ?> s/d <?= e($r['date_range_end'] ?? '-') ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size: 0.85rem; font-family: monospace; color: var(--text-secondary);">
                                        <?= format_bytes((int) $r['file_size_bytes']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size: 0.85rem; color: var(--text-secondary);">
                                        <?= date('d M Y H:i', strtotime($r['created_at'])) ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <a href="/reports/<?= (int) $r['id'] ?>/preview" class="btn btn-outline btn-xs" title="Pratinjau Dokumen">
                                            <span>👁️</span> Pratinjau
                                        </a>
                                        <a href="/reports/<?= (int) $r['id'] ?>/download" class="btn btn-primary btn-xs" title="Unduh Berkas">
                                            <span>📥</span> Unduh
                                        </a>
                                        <form method="POST" action="/reports/<?= (int) $r['id'] ?>/delete" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus laporan ini? Berkas fisik akan dihapus dari penyimpanan.');">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-outline btn-xs" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.4);" title="Hapus Laporan">
                                                <span>🗑️</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Controls -->
            <?php if ($totalPages > 1): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-top: 1px solid var(--border-color);">
                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        Halaman <?= $currentPage ?> dari <?= $totalPages ?>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <?php if ($currentPage > 1): ?>
                            <a href="/reports?page=<?= $currentPage - 1 ?><?= $currentType ? '&type=' . urlencode($currentType) : '' ?>" class="btn btn-outline btn-sm">&larr; Sebelumnya</a>
                        <?php endif; ?>
                        <?php if ($currentPage < $totalPages): ?>
                            <a href="/reports?page=<?= $currentPage + 1 ?><?= $currentType ? '&type=' . urlencode($currentType) : '' ?>" class="btn btn-outline btn-sm">Berikutnya &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
