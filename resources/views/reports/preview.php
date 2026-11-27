<?php
declare(strict_types=1);

/**
 * AnaBot Report Preview View (resources/views/reports/preview.php)
 */
?>

<!-- Toolbar -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.75rem;">
    <div style="display: flex; align-items: center; gap: 10px;">
        <a href="/reports" class="btn btn-outline btn-sm">&larr; Kembali ke Pusat Laporan</a>
        <h2 style="font-size: 1.15rem; font-weight: 700; margin: 0; color: var(--text-primary);">
            Pratinjau: <?= e($report['title']) ?>
        </h2>
    </div>

    <div style="display: flex; gap: 0.5rem;">
        <?php if ($report['report_type'] === 'html'): ?>
            <button onclick="printIframe()" class="btn btn-outline btn-sm">
                <span>🖨️</span> Cetak / Print
            </button>
        <?php endif; ?>
        <a href="/reports/<?= (int) $report['id'] ?>/download" class="btn btn-primary btn-sm">
            <span>📥</span> Unduh Dokumen (<?= format_bytes((int) $report['file_size_bytes']) ?>)
        </a>
    </div>
</div>

<?php if ($report['report_type'] === 'html' && !empty($htmlContent)): ?>
    <!-- Embedded HTML Viewer -->
    <div class="card" style="padding: 0; overflow: hidden; border-radius: 8px;">
        <iframe id="reportFrame" srcdoc="<?= e($htmlContent) ?>" style="width: 100%; height: 85vh; border: none; background: #fff;"></iframe>
    </div>

    <script>
    function printIframe() {
        const frame = document.getElementById('reportFrame');
        if (frame && frame.contentWindow) {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        }
    }
    </script>
<?php else: ?>
    <!-- Binary Document Summary Card -->
    <div class="card" style="max-width: 800px; margin: 0 auto; padding: 2.5rem; text-align: center;">
        <?php
        $icon = match ($report['report_type']) {
            'pdf' => '📄',
            'xlsx' => '📊',
            'csv' => '📑',
            default => '📁',
        };
        ?>
        <div style="font-size: 4rem; margin-bottom: 1rem;"><?= $icon ?></div>
        <h3 style="font-size: 1.4rem; color: var(--text-primary); margin: 0 0 0.5rem 0;"><?= e($report['title']) ?></h3>
        <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
            Format Dokumen: <strong><?= strtoupper(e($report['report_type'])) ?></strong> &bull; Ukuran: <strong><?= format_bytes((int) $report['file_size_bytes']) ?></strong> &bull; Dibuat: <?= date('d M Y H:i:s', strtotime($report['created_at'])) ?>
        </p>

        <?php if (!empty($report['summary_content'])): ?>
            <div style="text-align: left; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 8px; padding: 1.25rem; margin-bottom: 2rem; font-size: 0.9rem; line-height: 1.6; color: var(--text-primary);">
                <h4 style="margin: 0 0 0.75rem 0; font-size: 0.95rem; color: var(--brand-primary); display: flex; align-items: center; gap: 6px;">
                    <span>🤖</span> Ringkasan Eksekutif Terlampir
                </h4>
                <?= nl2br(e($report['summary_content'])) ?>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: center; gap: 1rem;">
            <a href="/reports" class="btn btn-outline">Kembali</a>
            <a href="/reports/<?= (int) $report['id'] ?>/download" class="btn btn-primary" style="padding: 0.75rem 2rem; font-weight: 600;">
                <span>📥</span> Unduh Dokumen Sekarang
            </a>
        </div>
    </div>
<?php endif; ?>
