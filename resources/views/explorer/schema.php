<?php
declare(strict_types=1);

/**
 * AnaBot Data Explorer - Schema Discovery View
 * Column Structure Inspection & Metric/Dimension Role Classification
 */

$datasetId = $dataset['id'] ?? 0;
?>

<div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
    <div>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                Skema Struktur Kolom: <?= e($dataset['name'] ?? 'Pilih Dataset') ?>
            </h2>
            <?php if (!empty($columns)): ?>
                <span class="badge badge-actual"><?= count($columns) ?> Kolom Terdeteksi</span>
            <?php endif; ?>
        </div>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 2px 0 0 0;">
            Klasifikasi tipe data, metrik numerik, dan dimensi agregasi untuk digunakan oleh Analytics Engine.
        </p>
    </div>

    <!-- Actions & Switcher -->
    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
        <?php if (!empty($allDatasets)): ?>
            <select onchange="window.location.href='/explorer/datasets/' + this.value + '/schema'" style="padding: 0.45rem 0.75rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); background: var(--bg-surface); color: var(--text-primary); font-size: 0.85rem;">
                <?php foreach ($allDatasets as $ds): ?>
                    <option value="<?= (int) $ds['id'] ?>" <?= (int) $ds['id'] === (int) $datasetId ? 'selected' : '' ?>>
                        Dataset: <?= e($ds['name']) ?> (<?= number_format((int) $ds['record_count']) ?> baris)
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if (!empty($dataset)): ?>
            <a href="/explorer/datasets/<?= (int) $datasetId ?>/records" class="btn btn-outline btn-sm">
                <span>📑</span> Telusuri Records
            </a>
            <a href="/explorer/datasets/<?= (int) $datasetId ?>/export" class="btn btn-outline btn-sm">
                <span>📥</span> Ekspor CSV
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($dataset)): ?>
    <div class="data-table-wrapper" style="text-align: center; padding: 3rem; color: var(--text-muted);">
        <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">📁</div>
        <div style="font-weight: 600; color: var(--text-secondary); margin-bottom: 0.25rem;">Tidak ada dataset yang dipilih</div>
        <p style="font-size: 0.85rem; margin-bottom: 1rem;">Silakan pilih dataset dari daftar untuk memeriksa struktur skema kolom.</p>
        <a href="/explorer/datasets" class="btn btn-primary btn-sm">Buka Daftar Dataset</a>
    </div>
<?php else: ?>
    <div class="notice-banner" style="margin-bottom: 1.25rem; padding: 1rem;">
        <div class="notice-icon">💡</div>
        <div style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.5;">
            <strong>Panduan Peran Kolom (Column Roles):</strong>
            <ul style="margin: 0.4rem 0 0 1.25rem;">
                <li><strong>Metrik (Metric):</strong> Kolom angka kuantitatif (omset, unit terjual, pengunjung) yang akan dihitung total, rata-rata, tren, dan anomalinya.</li>
                <li><strong>Dimensi (Dimension):</strong> Kolom kategorikal (kategori produk, wilayah, nama sales) untuk pengelompokan dan segmentasi perbandingan.</li>
                <li><strong>Tanggal Utama (Primary Date):</strong> Kolom acuan linimasa untuk analisis pertumbuhan beruntun waktu (time-series).</li>
            </ul>
        </div>
    </div>

    <div class="data-table-wrapper">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">Urutan</th>
                        <th>Nama Kolom</th>
                        <th>Tipe Data Kolom</th>
                        <th style="text-align: center;">Peran Metrik</th>
                        <th style="text-align: center;">Peran Dimensi</th>
                        <th style="text-align: center;">Tanggal Utama</th>
                        <th style="text-align: right;">Aksi Simpan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($columns)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                Belum ada kolom terdeteksi pada dataset ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($columns as $idx => $c): ?>
                            <tr>
                                <td style="color: var(--text-muted); font-size: 0.8rem;">#<?= (int) ($c['column_order'] ?: $idx + 1) ?></td>
                                <td>
                                    <strong style="color: var(--text-primary); font-size: 0.95rem;"><?= e($c['column_name']) ?></strong>
                                </td>
                                <td>
                                    <form method="POST" action="/explorer/columns/<?= (int) $c['id'] ?>/update" id="form-col-<?= (int) $c['id'] ?>" style="display: flex; gap: 0.5rem; align-items: center; margin: 0;">
                                        <?= csrf_field() ?>
                                        <select name="data_type" style="padding: 0.35rem 0.6rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-canvas); color: var(--text-primary); font-size: 0.8rem;">
                                            <option value="TEXT" <?= $c['data_type'] === 'TEXT' ? 'selected' : '' ?>>TEXT (Teks / String)</option>
                                            <option value="INTEGER" <?= $c['data_type'] === 'INTEGER' ? 'selected' : '' ?>>INTEGER (Bilangan Bulat)</option>
                                            <option value="NUMERIC" <?= $c['data_type'] === 'NUMERIC' ? 'selected' : '' ?>>NUMERIC (Desimal / Finansial)</option>
                                            <option value="DATETIME" <?= $c['data_type'] === 'DATETIME' ? 'selected' : '' ?>>DATETIME (Waktu / Tanggal)</option>
                                            <option value="BOOLEAN" <?= $c['data_type'] === 'BOOLEAN' ? 'selected' : '' ?>>BOOLEAN (Benar / Salah)</option>
                                        </select>
                                </td>
                                <td style="text-align: center;">
                                    <input type="hidden" name="is_metric" value="0">
                                    <input type="checkbox" name="is_metric" value="1" <?= (int) $c['is_metric'] === 1 ? 'checked' : '' ?> style="transform: scale(1.15);">
                                </td>
                                <td style="text-align: center;">
                                    <input type="hidden" name="is_dimension" value="0">
                                    <input type="checkbox" name="is_dimension" value="1" <?= (int) $c['is_dimension'] === 1 ? 'checked' : '' ?> style="transform: scale(1.15);">
                                </td>
                                <td style="text-align: center;">
                                    <input type="hidden" name="is_primary_date" value="0">
                                    <input type="checkbox" name="is_primary_date" value="1" <?= (int) $c['is_primary_date'] === 1 ? 'checked' : '' ?> style="transform: scale(1.15);">
                                </td>
                                <td style="text-align: right;">
                                        <button type="submit" class="btn btn-outline btn-sm">
                                            Perbarui
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
