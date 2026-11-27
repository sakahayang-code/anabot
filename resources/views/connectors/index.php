<?php
declare(strict_types=1);

/**
 * AnaBot Connectors & Integrations Index View
 */
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$totalConnections = count($connections ?? []);
$connectedCount = count(array_filter($connections ?? [], fn($c) => $c['status'] === 'connected'));
$errorCount = count(array_filter($connections ?? [], fn($c) => $c['status'] === 'error'));
$totalRecordsSynced = array_sum(array_column($connections ?? [], 'total_records'));
?>

<div class="connectors-page">
    <!-- Header Banner -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin: 0;">
                    Konektor & Integrasi Data
                </h1>
                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); font-weight: 600;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align: -1px; margin-right: 4px;">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    READ-ONLY ENFORCED
                </span>
            </div>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin: 0;">
                Kelola sambungan resmi ke platform eksternal. Seluruh integrasi bersifat hanya-baca (read-only) untuk menjamin keamanan 100% data eksternal Anda.
            </p>
        </div>

        <div style="display: flex; gap: 10px;">
            <a href="/connectors/create" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Tambah Koneksi Baru
            </a>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flashSuccess): ?>
        <div style="background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.875rem; display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <?= e($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.875rem; display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <?= e($flashError) ?>
        </div>
    <?php endif; ?>

    <!-- Summary KPI Row -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px;">
        <div class="card" style="padding: 18px;">
            <div style="font-size: 0.8125rem; color: var(--text-muted); font-weight: 500; margin-bottom: 6px;">Total Koneksi Terdaftar</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-main);"><?= number_format($totalConnections) ?></div>
        </div>
        <div class="card" style="padding: 18px;">
            <div style="font-size: 0.8125rem; color: var(--text-muted); font-weight: 500; margin-bottom: 6px;">Koneksi Aktif</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;"><?= number_format($connectedCount) ?></div>
        </div>
        <div class="card" style="padding: 18px;">
            <div style="font-size: 0.8125rem; color: var(--text-muted); font-weight: 500; margin-bottom: 6px;">Perlu Perhatian / Error</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: <?= $errorCount > 0 ? '#ef4444' : 'var(--text-muted)' ?>;"><?= number_format($errorCount) ?></div>
        </div>
        <div class="card" style="padding: 18px;">
            <div style="font-size: 0.8125rem; color: var(--text-muted); font-weight: 500; margin-bottom: 6px;">Total Record Tersinkronisasi</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--brand-primary);"><?= number_format($totalRecordsSynced) ?></div>
        </div>
    </div>

    <!-- Active Connections Section -->
    <div style="margin-bottom: 36px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h2 style="font-size: 1.125rem; font-weight: 600; color: var(--text-main); margin: 0;">
                Koneksi Platform Aktif
            </h2>
            <span style="font-size: 0.8125rem; color: var(--text-muted);"><?= count($connections) ?> platform terhubung</span>
        </div>

        <?php if (empty($connections)): ?>
            <div class="card" style="padding: 40px; text-align: center;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(59, 130, 246, 0.1); color: var(--brand-primary); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                    </svg>
                </div>
                <h3 style="font-size: 1.125rem; font-weight: 600; color: var(--text-main); margin-bottom: 8px;">Belum Ada Koneksi Platform</h3>
                <p style="color: var(--text-muted); font-size: 0.875rem; max-width: 420px; margin: 0 auto 20px;">
                    Hubungkan platform data Anda seperti Google Sheets, Analytics, atau REST API untuk memulai pengumpulan dan analisis data secara otomatis.
                </p>
                <a href="/connectors/create" class="btn btn-primary">Pilih Platform Sekarang</a>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px;">
                <?php foreach ($connections as $conn): ?>
                    <?php
                        $statusClass = 'badge-success';
                        $statusLabel = 'Terhubung';
                        if ($conn['status'] === 'error') {
                            $statusClass = 'badge-danger';
                            $statusLabel = 'Error';
                        } elseif ($conn['status'] === 'disconnected') {
                            $statusClass = 'badge-secondary';
                            $statusLabel = 'Terputus';
                        }
                    ?>
                    <div class="card" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(59, 130, 246, 0.12); color: var(--brand-primary); display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 style="font-size: 1rem; font-weight: 600; color: var(--text-main); margin: 0;">
                                            <?= e($conn['name']) ?>
                                        </h3>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                                            <?= e($conn['connector_name']) ?> &bull; <?= strtoupper(e($conn['auth_type'])) ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="badge <?= $statusClass ?>" id="status-badge-<?= $conn['id'] ?>"><?= $statusLabel ?></span>
                            </div>

                            <div style="background: var(--bg-surface-soft); border-radius: 6px; padding: 10px 12px; margin-bottom: 16px; font-size: 0.8125rem;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                    <span style="color: var(--text-muted);">Datasets:</span>
                                    <span style="font-weight: 600; color: var(--text-main);"><?= number_format((int)$conn['dataset_count']) ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                    <span style="color: var(--text-muted);">Record tersimpan:</span>
                                    <span style="font-weight: 600; color: var(--text-main);"><?= number_format((int)$conn['total_records']) ?> baris</span>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: var(--text-muted);">Sinkronisasi terakhir:</span>
                                    <span style="color: var(--text-muted);" id="last-sync-<?= $conn['id'] ?>">
                                        <?= !empty($conn['last_sync_at']) ? date('d M Y H:i', strtotime($conn['last_sync_at'])) : 'Belum pernah' ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; gap: 8px; flex-wrap: wrap; border-top: 1px solid var(--border-color); padding-top: 14px;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="testConnection(<?= $conn['id'] ?>)" id="btn-test-<?= $conn['id'] ?>" style="flex: 1; justify-content: center;">
                                Test
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" onclick="triggerSync(<?= $conn['id'] ?>)" id="btn-sync-<?= $conn['id'] ?>" style="flex: 1; justify-content: center;">
                                Sinkronkan
                            </button>
                            <a href="/connectors/<?= $conn['id'] ?>" class="btn btn-secondary btn-sm" style="flex: 1; justify-content: center; text-decoration: none;">
                                Kelola
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Supported Connectors Catalog Section -->
    <div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div>
                <h2 style="font-size: 1.125rem; font-weight: 600; color: var(--text-main); margin: 0 0 4px 0;">
                    Katalog Platform yang Didukung
                </h2>
                <p style="font-size: 0.8125rem; color: var(--text-muted); margin: 0;">
                    10 platform resmi yang dapat dihubungkan ke mesin analitik AnaBot.
                </p>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
            <?php foreach ($catalog as $code => $cat): ?>
                <div class="card" style="padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                            <span class="badge" style="background: rgba(59, 130, 246, 0.1); color: <?= e($cat['color'] ?? '#3b82f6') ?>; border: 1px solid rgba(59, 130, 246, 0.2); font-size: 0.6875rem;">
                                <?= e($cat['badge'] ?? 'Platform') ?>
                            </span>
                            <span style="font-size: 0.6875rem; color: var(--text-muted); text-transform: uppercase;">
                                <?= e($cat['auth_type']) ?>
                            </span>
                        </div>

                        <h3 style="font-size: 1rem; font-weight: 600; color: var(--text-main); margin: 0 0 6px 0;">
                            <?= e($cat['name']) ?>
                        </h3>

                        <p style="font-size: 0.8125rem; color: var(--text-muted); line-height: 1.4; margin: 0 0 16px 0; min-height: 48px;">
                            <?= e($cat['description']) ?>
                        </p>
                    </div>

                    <div style="border-top: 1px solid var(--border-color); padding-top: 12px; display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 0.75rem; color: #10b981; display: inline-flex; align-items: center; gap: 4px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            Read-Only
                        </span>

                        <a href="/connectors/create?code=<?= urlencode($code) ?>" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 4px 10px;">
                            + Hubungkan
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
// AJAX Connection Test Handler
function testConnection(connId) {
    const btn = document.getElementById('btn-test-' + connId);
    const badge = document.getElementById('status-badge-' + connId);
    if (!btn) return;

    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menguji...';
    btn.disabled = true;

    fetch('/api/v1/connections/' + connId + '/test', {
        method: 'POST',
        headers: { 'Accept': 'application/json' }
    })
    .then(res => res.json())
    .then(data => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        if (data.success) {
            badge.className = 'badge badge-success';
            badge.innerText = 'Terhubung';
            alert('✓ Tes Koneksi Berhasil: ' + (data.message || 'Koneksi aktif dan terverifikasi.'));
        } else {
            badge.className = 'badge badge-danger';
            badge.innerText = 'Error';
            alert('✗ Tes Koneksi Gagal: ' + (data.message || 'Terjadi kesalahan komunikasi.'));
        }
    })
    .catch(err => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert('Gagal menghubungi server: ' + err.message);
    });
}

// AJAX Trigger Sync Handler
function triggerSync(connId) {
    const btn = document.getElementById('btn-sync-' + connId);
    if (!btn) return;

    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sync...';
    btn.disabled = true;

    fetch('/api/v1/connections/' + connId + '/sync', {
        method: 'POST',
        headers: { 'Accept': 'application/json' }
    })
    .then(res => res.json())
    .then(data => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        if (data.success) {
            const lastSync = document.getElementById('last-sync-' + connId);
            if (lastSync) lastSync.innerText = 'Baru saja';
            alert('✓ Sinkronisasi Selesai: ' + data.records_processed + ' record diproses (' + data.records_added + ' ditambah, ' + data.records_updated + ' diperbarui) dalam ' + data.duration_seconds + 's.');
            window.location.reload();
        } else {
            alert('✗ Sinkronisasi Gagal: ' + (data.error || 'Terjadi kesalahan saat sinkronisasi data.'));
        }
    })
    .catch(err => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert('Gagal menghubungi server: ' + err.message);
    });
}
</script>
