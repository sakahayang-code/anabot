<?php
declare(strict_types=1);

/**
 * AnaBot - Google Sheets Import Studio & Preview
 */
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div class="google-sheets-import-page">
    <!-- Header -->
    <div style="margin-bottom: 24px;">
        <a href="/connectors/<?= $connection['id'] ?>" style="color: var(--text-muted); font-size: 0.8125rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 8px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
            Kembali ke Detail Koneksi
        </a>
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                    <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin: 0;">
                        Google Sheets Import Studio
                    </h1>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                        Auto-Schema Discovery
                    </span>
                </div>
                <p style="color: var(--text-muted); font-size: 0.875rem; margin: 0;">
                    Koneksi: <strong><?= e($connection['name']) ?></strong> &bull; Spreadsheet ID: <code style="background: var(--bg-surface-soft); padding: 2px 6px; border-radius: 4px; color: var(--brand-primary);"><?= e($spreadsheetId) ?></code>
                </p>
            </div>
        </div>
    </div>

    <?php if ($flashError): ?>
        <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; font-size: 0.875rem;">
            <?= e($flashError) ?>
        </div>
    <?php endif; ?>

    <!-- Step 1: Sheet Tab Selector & Preview Controls -->
    <div class="card" style="padding: 24px; margin-bottom: 24px;">
        <h2 style="font-size: 1.125rem; font-weight: 600; color: var(--text-main); margin: 0 0 16px 0;">
            1. Pilih Lembar Kerja (Sheet Tab) yang Ingin Diimpor
        </h2>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; align-items: end;">
            <div>
                <label style="display: block; font-size: 0.8125rem; font-weight: 500; color: var(--text-muted); margin-bottom: 6px;">
                    Daftar Tab Lembar Kerja Terdeteksi:
                </label>
                <select id="sheet-tab-select" class="form-control" style="width: 100%; padding: 10px 12px; background: var(--bg-surface-soft); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-size: 0.875rem;" onchange="loadSheetPreview()">
                    <?php if (empty($availableSheets)): ?>
                        <option value="Sheet1">Sheet1 (Default)</option>
                    <?php else: ?>
                        <?php foreach ($availableSheets as $sheet): ?>
                            <option value="<?= e($sheet['id']) ?>">
                                <?= e($sheet['name']) ?> <?= !empty($sheet['row_count']) ? "({$sheet['row_count']} baris)" : '' ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div>
                <label style="display: block; font-size: 0.8125rem; font-weight: 500; color: var(--text-muted); margin-bottom: 6px;">
                    Nama Dataset di SQLite (Label Analitik):
                </label>
                <input type="text" id="custom-dataset-name" class="form-control" placeholder="Contoh: Transaksi Penjualan 2026" value="<?= e($connection['name']) ?> - Dataset" style="width: 100%; padding: 10px 12px; background: var(--bg-surface-soft); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-size: 0.875rem;">
            </div>

            <div>
                <button type="button" class="btn btn-secondary" onclick="loadSheetPreview()" id="btn-refresh-preview" style="width: 100%; padding: 10px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <polyline points="1 20 1 14 7 14"></polyline>
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                    </svg>
                    Muat Ulang Pratinjau
                </button>
            </div>
        </div>
    </div>

    <!-- Step 2: Live Data Preview & Schema Discovery Table -->
    <div class="card" style="padding: 24px; margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div>
                <h2 style="font-size: 1.125rem; font-weight: 600; color: var(--text-main); margin: 0 0 4px 0;">
                    2. Pratinjau Data (5 Baris Pertama) & Inferensi Tipe Kolom
                </h2>
                <p style="font-size: 0.8125rem; color: var(--text-muted); margin: 0;">
                    AnaBot secara otomatis membaca baris header dan menentukan tipe data (DATETIME, NUMERIC, INTEGER, TEXT) untuk setiap kolom.
                </p>
            </div>
            <span class="badge badge-success" id="detected-columns-count">Skema Otomatis Siap</span>
        </div>

        <div id="preview-loading" style="display: none; padding: 40px; text-align: center; color: var(--text-muted);">
            <div style="font-size: 1rem; margin-bottom: 8px;">Mengambil data dari Google Sheets API v4...</div>
            <div style="font-size: 0.75rem;">Menghitung inferensi tipe data dan validasi skema...</div>
        </div>

        <div id="preview-container" style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem; text-align: left;" id="preview-table">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color); background: var(--bg-surface-soft);" id="preview-thead-row">
                        <th style="padding: 10px; color: var(--text-muted);">#</th>
                        <!-- Dynamic Columns -->
                    </tr>
                </thead>
                <tbody id="preview-tbody">
                    <!-- Dynamic Sample Rows -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Step 3: Action Toolbar -->
    <div class="card" style="padding: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="font-size: 0.9375rem; font-weight: 600; color: var(--text-main); margin-bottom: 4px;">
                Siap untuk Menyimpan Data ke SQLite Internal?
            </div>
            <div style="font-size: 0.8125rem; color: var(--text-muted);">
                Data akan dinormalisasi dan disimpan ke dalam tabel <code>data_records</code> secara atomik dan terproteksi dari duplikasi.
            </div>
        </div>

        <div style="display: flex; gap: 12px;">
            <a href="/connectors/<?= $connection['id'] ?>" class="btn btn-secondary" style="padding: 10px 20px; text-decoration: none;">
                Batal
            </a>
            <button type="button" class="btn btn-primary" onclick="executeImport()" id="btn-execute-import" style="padding: 10px 24px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Impor Sekarang ke Basis Data SQLite
            </button>
        </div>
    </div>
</div>

<script>
let activeColumns = [];

function loadSheetPreview() {
    const sheetSelect = document.getElementById('sheet-tab-select');
    const sheetName = sheetSelect.value;
    const loading = document.getElementById('preview-loading');
    const container = document.getElementById('preview-container');
    const connId = <?= $connection['id'] ?>;

    loading.style.display = 'block';
    container.style.opacity = '0.3';

    fetch('/api/v1/connectors/google-sheets/' + connId + '/preview?sheet=' + encodeURIComponent(sheetName))
    .then(r => r.json())
    .then(res => {
        loading.style.display = 'none';
        container.style.opacity = '1';

        if (!res.success) {
            alert('Gagal mengambil pratinjau lembar kerja: ' + (res.error || 'Terjadi kesalahan'));
            return;
        }

        const data = res.data;
        activeColumns = data.columns || [];
        renderPreviewTable(data.columns, data.sample_rows);

        document.getElementById('detected-columns-count').innerText = activeColumns.length + ' Kolom Terinferensi';
    })
    .catch(err => {
        loading.style.display = 'none';
        container.style.opacity = '1';
        alert('Gagal menghubungi server: ' + err.message);
    });
}

function renderPreviewTable(columns, sampleRows) {
    const theadRow = document.getElementById('preview-thead-row');
    const tbody = document.getElementById('preview-tbody');

    // Build headers with inferred type badges
    let theadHtml = '<th style="padding: 10px; color: var(--text-muted); width: 40px;">#</th>';
    columns.forEach(col => {
        let typeBadgeColor = '#64748b';
        if (col.data_type === 'DATETIME') typeBadgeColor = '#3b82f6';
        else if (col.data_type === 'NUMERIC' || col.data_type === 'INTEGER') typeBadgeColor = '#10b981';
        else if (col.data_type === 'BOOLEAN') typeBadgeColor = '#f59e0b';

        theadHtml += '<th style="padding: 10px 12px; color: var(--text-main); font-weight: 600;">' +
            '<div>' + escapeHtml(col.label || col.column_name) + '</div>' +
            '<div style="margin-top: 4px;">' +
            '<span class="badge" style="background: rgba(255,255,255,0.06); color: ' + typeBadgeColor + '; font-size: 0.65rem; border: 1px solid rgba(255,255,255,0.1);">' + col.data_type + '</span>' +
            (col.is_primary_date ? ' <span class="badge" style="background: rgba(59,130,246,0.15); color: #3b82f6; font-size: 0.65rem;">PRIMARY DATE</span>' : '') +
            (col.is_metric ? ' <span class="badge" style="background: rgba(16,185,129,0.15); color: #10b981; font-size: 0.65rem;">METRIC</span>' : '') +
            '</div>' +
            '</th>';
    });
    theadRow.innerHTML = theadHtml;

    // Build sample rows
    let tbodyHtml = '';
    if (!sampleRows || sampleRows.length === 0) {
        tbodyHtml = '<tr><td colspan="' + (columns.length + 1) + '" style="padding: 24px; text-align: center; color: var(--text-muted);">Tidak ada baris data sampel ditemukan.</td></tr>';
    } else {
        sampleRows.forEach((row, rIdx) => {
            tbodyHtml += '<tr style="border-bottom: 1px solid var(--border-color);">';
            tbodyHtml += '<td style="padding: 10px; color: var(--text-muted); font-family: monospace;">' + (rIdx + 1) + '</td>';
            columns.forEach(col => {
                const cellVal = row[col.column_name] !== undefined ? row[col.column_name] : '';
                tbodyHtml += '<td style="padding: 10px 12px; color: var(--text-main); font-family: ' + (col.data_type === 'NUMERIC' || col.data_type === 'INTEGER' ? 'monospace' : 'inherit') + ';">' + escapeHtml(cellVal) + '</td>';
            });
            tbodyHtml += '</tr>';
        });
    }
    tbody.innerHTML = tbodyHtml;
}

function executeImport() {
    const btn = document.getElementById('btn-execute-import');
    const sheetSelect = document.getElementById('sheet-tab-select');
    const sheetName = sheetSelect.value;
    const datasetNameInput = document.getElementById('custom-dataset-name');
    const datasetName = datasetNameInput.value.trim() || (sheetName + ' Dataset');
    const connId = <?= $connection['id'] ?>;

    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Mengimpor Data...';
    btn.disabled = true;

    fetch('/api/v1/connectors/google-sheets/' + connId + '/import', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            sheet_name: sheetName,
            dataset_name: datasetName,
            columns: activeColumns
        })
    })
    .then(r => r.json())
    .then(res => {
        btn.innerHTML = originalText;
        btn.disabled = false;

        if (res.success) {
            alert('✓ Sukses! ' + res.records_processed + ' baris berhasil diimpor ke dataset "' + res.dataset_name + '" (' + res.duration_seconds + ' detik).');
            window.location.href = '/explorer/datasets/' + res.dataset_id;
        } else {
            alert('✗ Gagal mengimpor data: ' + (res.error || 'Terjadi kesalahan sistem.'));
        }
    })
    .catch(err => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert('Gagal menghubungi server: ' + err.message);
    });
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Initial load on page ready
document.addEventListener('DOMContentLoaded', function() {
    loadSheetPreview();
});
</script>
