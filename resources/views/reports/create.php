<?php
declare(strict_types=1);

/**
 * AnaBot Report Builder Wizard (resources/views/reports/create.php)
 */
?>

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

<div style="max-width: 800px; margin: 0 auto;">
    <!-- Breadcrumb -->
    <div style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-muted);">
        <a href="/reports" style="color: var(--brand-primary); text-decoration: none;">Pusat Laporan</a>
        <span style="margin: 0 6px;">&rsaquo;</span>
        <span>Buat Laporan Baru</span>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title" style="font-size: 1.25rem; display: flex; align-items: center; gap: 8px;">
                <span>⚙️</span> Wizard Pembuat Laporan Eksekutif
            </h2>
            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 4px 0 0 0;">
                Konfigurasikan dataset, metrik, dan format dokumen laporan. Dokumen akan dihitung secara deterministik dan disimpan di folder privat.
            </p>
        </div>

        <div class="card-body">
            <form method="POST" action="/reports/generate" id="reportForm">
                <?= csrf_field() ?>

                <!-- 1. Dataset Target -->
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="dataset_id" style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-primary);">
                        1. Pilih Dataset Target <span style="color: #ef4444;">*</span>
                    </label>
                    <select name="dataset_id" id="dataset_id" class="form-control" style="width: 100%; padding: 0.75rem; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary);" required onchange="onDatasetChange(this.value)">
                        <?php if (empty($datasets)): ?>
                            <option value="">Belum ada dataset tersedia</option>
                        <?php else: ?>
                            <?php foreach ($datasets as $ds): ?>
                                <option value="<?= (int) $ds['id'] ?>" <?= $selectedDatasetId === (int) $ds['id'] ? 'selected' : '' ?>>
                                    <?= e($ds['name']) ?> (<?= e($ds['platform_name'] ?? 'Dataset') ?> &bull; <?= number_format((int) ($ds['total_rows'] ?? 0)) ?> baris)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- 2. Judul Laporan -->
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="title" style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-primary);">
                        2. Judul Laporan (Opsional)
                    </label>
                    <input type="text" name="title" id="title" class="form-control" placeholder="Contoh: Laporan Analisis Kinerja Penjualan Q4" style="width: 100%; padding: 0.75rem; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary);">
                    <small style="color: var(--text-muted); font-size: 0.8rem; display: block; margin-top: 4px;">
                        Jika dikosongkan, sistem akan otomatis membuat judul berdasarkan nama dataset.
                    </small>
                </div>

                <!-- 3. Metrik Analisis -->
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="metric_name" style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-primary);">
                        3. Metrik Analisis Utama
                    </label>
                    <select name="metric_name" id="metric_name" class="form-control" style="width: 100%; padding: 0.75rem; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary);">
                        <option value="">Deteksi Otomatis (Auto-Detect Primary Metric)</option>
                        <?php foreach ($columns as $col): ?>
                            <?php if (in_array($col['data_type'], ['INTEGER', 'NUMERIC', 'FLOAT', 'DECIMAL', 'REAL'])): ?>
                                <option value="<?= e($col['column_name']) ?>">
                                    <?= e($col['column_name']) ?> (<?= e($col['data_type']) ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 4. Format Laporan -->
                <div class="form-group" style="margin-bottom: 1.75rem;">
                    <label style="display: block; font-weight: 600; margin-bottom: 0.75rem; color: var(--text-primary);">
                        4. Format Dokumen yang Dihasilkan <span style="color: #ef4444;">*</span>
                    </label>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem;">
                        <!-- Option All -->
                        <label style="display: flex; align-items: flex-start; gap: 10px; background: rgba(2, 132, 199, 0.1); border: 1px solid #0284c7; padding: 0.85rem; border-radius: 8px; cursor: pointer;">
                            <input type="radio" name="format" value="all" checked style="margin-top: 3px;">
                            <div>
                                <strong style="color: #38bdf8; display: block; font-size: 0.9rem;">📦 Semua Format</strong>
                                <span style="font-size: 0.75rem; color: var(--text-secondary);">HTML + PDF + XLSX + CSV sekaligus</span>
                            </div>
                        </label>

                        <!-- Option HTML -->
                        <label style="display: flex; align-items: flex-start; gap: 10px; background: var(--bg-surface); border: 1px solid var(--border-color); padding: 0.85rem; border-radius: 8px; cursor: pointer;">
                            <input type="radio" name="format" value="html" style="margin-top: 3px;">
                            <div>
                                <strong style="color: var(--text-primary); display: block; font-size: 0.9rem;">🌐 Web HTML</strong>
                                <span style="font-size: 0.75rem; color: var(--text-secondary);">Interaktif, responsive & chart SVG</span>
                            </div>
                        </label>

                        <!-- Option PDF -->
                        <label style="display: flex; align-items: flex-start; gap: 10px; background: var(--bg-surface); border: 1px solid var(--border-color); padding: 0.85rem; border-radius: 8px; cursor: pointer;">
                            <input type="radio" name="format" value="pdf" style="margin-top: 3px;">
                            <div>
                                <strong style="color: var(--text-primary); display: block; font-size: 0.9rem;">📄 PDF Cetak</strong>
                                <span style="font-size: 0.75rem; color: var(--text-secondary);">Format resmi A4 eksekutif</span>
                            </div>
                        </label>

                        <!-- Option XLSX -->
                        <label style="display: flex; align-items: flex-start; gap: 10px; background: var(--bg-surface); border: 1px solid var(--border-color); padding: 0.85rem; border-radius: 8px; cursor: pointer;">
                            <input type="radio" name="format" value="xlsx" style="margin-top: 3px;">
                            <div>
                                <strong style="color: var(--text-primary); display: block; font-size: 0.9rem;">📊 Excel XLSX</strong>
                                <span style="font-size: 0.75rem; color: var(--text-secondary);">Spreadsheet multi-tab & formulas</span>
                            </div>
                        </label>

                        <!-- Option CSV -->
                        <label style="display: flex; align-items: flex-start; gap: 10px; background: var(--bg-surface); border: 1px solid var(--border-color); padding: 0.85rem; border-radius: 8px; cursor: pointer;">
                            <input type="radio" name="format" value="csv" style="margin-top: 3px;">
                            <div>
                                <strong style="color: var(--text-primary); display: block; font-size: 0.9rem;">📑 CSV Data</strong>
                                <span style="font-size: 0.75rem; color: var(--text-secondary);">Streaming cepat UTF-8 BOM</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- 5. Opsi Tambahan (AI & Rentang Waktu) -->
                <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 8px; padding: 1.25rem; margin-bottom: 2rem;">
                    <h4 style="margin: 0 0 1rem 0; font-size: 0.95rem; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                        <span>⚡</span> Opsi Analisis & Filter Tambahan
                    </h4>

                    <!-- AI Toggle -->
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 1.25rem;">
                        <input type="checkbox" name="include_ai_summary" id="include_ai_summary" value="1" checked style="width: 18px; height: 18px; cursor: pointer;">
                        <label for="include_ai_summary" style="font-size: 0.9rem; color: var(--text-primary); cursor: pointer;">
                            <strong>Sertakan Ringkasan Eksekutif & Rekomendasi AI</strong>
                            <div style="font-size: 0.78rem; color: var(--text-muted);">
                                Menggunakan inferensi AI berbasis fakta terverifikasi (jika offline, fallback otomatis ke ringkasan matematis deterministik).
                            </div>
                        </label>
                    </div>

                    <!-- Date Range Filter -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label for="date_start" style="display: block; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 4px;">
                                Tanggal Mulai (Opsional)
                            </label>
                            <input type="date" name="date_start" id="date_start" style="width: 100%; padding: 0.6rem; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary);">
                        </div>
                        <div>
                            <label for="date_end" style="display: block; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 4px;">
                                Tanggal Akhir (Opsional)
                            </label>
                            <input type="date" name="date_end" id="date_end" style="width: 100%; padding: 0.6rem; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary);">
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; align-items: center;">
                    <a href="/reports" class="btn btn-outline">Batal</a>
                    <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.75rem; font-weight: 600;">
                        <span>🚀</span> Generate Laporan Sekarang
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function onDatasetChange(datasetId) {
    if (!datasetId) return;
    window.location.href = '/reports/create?dataset_id=' + datasetId;
}
</script>
