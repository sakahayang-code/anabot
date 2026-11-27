<?php
declare(strict_types=1);

/**
 * AnaBot Data Explorer - Datasets List View
 */
?>

<div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <div>
        <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin: 0;">Katalog Datasets Lokal</h2>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 2px 0 0 0;">Dataset yang telah diambil melalui konektor dan dinormalisasi ke SQLite</p>
    </div>
    <div style="display: flex; gap: 0.5rem;">
        <a href="/connectors/new" class="btn btn-primary btn-sm">
            <span>➕</span> Tambah Sumber Data
        </a>
    </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div style="background: var(--success-bg); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); padding: 0.75rem 1rem; border-radius: var(--radius-md); font-size: 0.85rem; margin-bottom: 1.25rem;">
        <?= e($_SESSION['flash_success']) ?>
        <?php unset($_SESSION['flash_success']); ?>
    </div>
<?php endif; ?>

<div class="data-table-wrapper">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama Dataset</th>
                    <th>Platform Sumber</th>
                    <th>Total Records</th>
                    <th>Rentang Waktu Data</th>
                    <th>Terakhir Diperbarui</th>
                    <th style="text-align: right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($datasets)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">📁</div>
                            <div style="font-weight: 600; color: var(--text-secondary); margin-bottom: 0.25rem;">Belum ada dataset yang tersimpan</div>
                            <p style="font-size: 0.85rem; margin-bottom: 1rem;">Hubungkan platform digital seperti Google Sheets atau REST API untuk mulai mengumpulkan data secara otomatis.</p>
                            <a href="/connectors/new" class="btn btn-primary btn-sm">Hubungkan Platform Baru</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($datasets as $d): ?>
                        <tr>
                            <td><span style="color: var(--text-muted);">#<?= (int) $d['id'] ?></span></td>
                            <td>
                                <a href="/explorer/datasets/<?= (int) $d['id'] ?>/records" style="font-weight: 700; color: var(--brand-primary); text-decoration: none;">
                                    <?= e($d['name']) ?>
                                </a>
                                <?php if (!empty($d['description'])): ?>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($d['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-info"><?= e($d['platform_name'] ?? 'Custom Source') ?></span>
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;"><?= e($d['source_name'] ?? '') ?></div>
                            </td>
                            <td>
                                <strong class="num" style="color: var(--text-primary); font-size: 1rem;"><?= number_format((int) $d['record_count']) ?></strong> baris
                            </td>
                            <td>
                                <div style="font-size: 0.75rem;">
                                    <?php if (!empty($d['first_record_at']) && !empty($d['latest_record_at'])): ?>
                                        <?= substr($d['first_record_at'], 0, 10) ?> s/d <?= substr($d['latest_record_at'], 0, 10) ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="font-size: 0.8rem; color: var(--text-muted);">
                                <?= substr((string) $d['updated_at'], 0, 16) ?>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 0.4rem;">
                                    <a href="/explorer/datasets/<?= (int) $d['id'] ?>/records" class="btn btn-primary btn-sm" title="Telusuri Baris Data">
                                        Data
                                    </a>
                                    <a href="/explorer/datasets/<?= (int) $d['id'] ?>/schema" class="btn btn-outline btn-sm" title="Struktur Kolom">
                                        Skema
                                    </a>
                                    <a href="/explorer/datasets/<?= (int) $d['id'] ?>/export" class="btn btn-outline btn-sm" title="Ekspor CSV">
                                        CSV
                                    </a>
                                    <form method="POST" action="/explorer/datasets/<?= (int) $d['id'] ?>/delete" onsubmit="return confirm('Apakah Anda yakin ingin menghapus dataset ini dari database lokal? Data di platform asli tidak akan terhapus.');" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger); border-color: rgba(239, 68, 68, 0.3);" title="Hapus Dataset Lokal">
                                            ✕
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls -->
    <?php if (!empty($pagination) && $pagination['total_pages'] > 1): ?>
        <div style="padding: 1rem 1.5rem; display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--border-color); font-size: 0.85rem;">
            <div style="color: var(--text-muted);">
                Halaman <?= (int) $pagination['current_page'] ?> dari <?= (int) $pagination['total_pages'] ?> (Total <?= number_format($pagination['total_records']) ?> dataset)
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <?php if ($pagination['current_page'] > 1): ?>
                    <a href="?page=<?= (int) ($pagination['current_page'] - 1) ?>" class="btn btn-outline btn-sm">&larr; Sebelumnya</a>
                <?php endif; ?>
                <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
                    <a href="?page=<?= (int) ($pagination['current_page'] + 1) ?>" class="btn btn-outline btn-sm">Berikutnya &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
