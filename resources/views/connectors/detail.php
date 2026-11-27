<?php
declare(strict_types=1);

/**
 * AnaBot Connectors - Connection Detail View
 */
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$statusBadge = match ($connection['status']) {
    'connected' => '<span class="badge badge-success" id="main-status-badge">Terhubung</span>',
    'error' => '<span class="badge badge-danger" id="main-status-badge">Error</span>',
    default => '<span class="badge badge-secondary" id="main-status-badge">Terputus</span>',
};
?>

<div class="connection-detail-page">
    <!-- Breadcrumbs -->
    <div style="margin-bottom: 20px;">
        <a href="/connectors" style="color: var(--text-muted); font-size: 0.8125rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 8px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
            Kembali ke Daftar Konektor
        </a>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                    <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin: 0;">
                        <?= e($connection['name']) ?>
                    </h1>
                    <?= $statusBadge ?>
                    <span class="badge" style="background: rgba(59, 130, 246, 0.12); color: var(--brand-primary); font-size: 0.75rem;">
                        <?= e($connection['connector_name']) ?>
                    </span>
                </div>
                <div style="color: var(--text-muted); font-size: 0.8125rem;">
                    ID Koneksi: #<?= $connection['id'] ?> &bull; Dibuat pada: <?= date('d M Y H:i', strtotime($connection['created_at'])) ?> &bull;
                    Sinkronisasi Terakhir: <span id="detail-last-sync"><?= !empty($connection['last_sync_at']) ? date('d M Y H:i:s', strtotime($connection['last_sync_at'])) : 'Belum pernah' ?></span>
                </div>
            </div>

            <!-- Action Buttons Toolbar -->
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <button type="button" class="btn btn-secondary" onclick="testConnection(<?= $connection['id'] ?>)" id="btn-test">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 4px;">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    Uji Koneksi
                </button>
                <?php if ($connection['connector_code'] === 'google_sheets'): ?>
                    <a href="/connectors/<?= $connection['id'] ?>/google-sheets" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 4px; text-decoration: none; border-color: #10b981; color: #10b981;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="3" y1="9" x2="21" y2="9"></line>
                            <line x1="9" y1="21" x2="9" y2="9"></line>
                        </svg>
                        Import Studio
                    </a>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" onclick="triggerSync(<?= $connection['id'] ?>)" id="btn-sync">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 4px;">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <polyline points="1 20 1 14 7 14"></polyline>
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                    </svg>
                    Sinkronisasi Sekarang
                </button>
                <?php if ($connection['status'] !== 'disconnected'): ?>
                    <form action="/connectors/<?= $connection['id'] ?>/disconnect" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin memutus koneksi ini? Seluruh kredensial token terenkripsi akan dihapus secara permanen.');" style="display: inline;">
                        <button type="submit" class="btn btn-danger" style="background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); color: #f87171;">
                            Putus Sambungan
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flashSuccess): ?>
        <div style="background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.875rem;">
            <?= e($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.875rem;">
            <?= e($flashError) ?>
        </div>
    <?php endif; ?>

    <!-- Overview Cards Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 28px;">
        <!-- Card 1: Credential Status -->
        <div class="card" style="padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                <h3 style="font-size: 0.9375rem; font-weight: 600; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #10b981;">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    Status Kredensial & Enkripsi
                </h3>
                <span class="badge badge-success">AES-256-GCM</span>
            </div>

            <div style="font-size: 0.8125rem;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="color: var(--text-muted);">Tipe Token:</span>
                    <span style="font-weight: 600; color: var(--text-main);"><?= e($credMeta['token_type'] ?? 'Terenkripsi') ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="color: var(--text-muted);">Status Kadaluarsa:</span>
                    <span>
                        <?php if ($credMeta && $credMeta['is_expired']): ?>
                            <span style="color: #ef4444; font-weight: 600;">Kadaluarsa (Perlu Re-autentikasi)</span>
                        <?php elseif ($credMeta && $credMeta['expires_at']): ?>
                            <span style="color: #10b981; font-weight: 500;">Berlaku s/d <?= date('d M Y H:i', strtotime($credMeta['expires_at'])) ?></span>
                        <?php else: ?>
                            <span style="color: #10b981; font-weight: 500;">Aktif / Permanen</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Terakhir Diperbarui:</span>
                    <span style="color: var(--text-muted);">
                        <?= !empty($credMeta['updated_at']) ? date('d M Y H:i', strtotime($credMeta['updated_at'])) : '-' ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Card 2: Cursor & Pagination State -->
        <div class="card" style="padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                <h3 style="font-size: 0.9375rem; font-weight: 600; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--brand-primary);">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <polyline points="1 20 1 14 7 14"></polyline>
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                    </svg>
                    Status Cursor Inkremental
                </h3>
                <span class="badge" style="background: rgba(59, 130, 246, 0.12); color: var(--brand-primary);">Incremental</span>
            </div>

            <?php if (empty($cursors)): ?>
                <div style="font-size: 0.8125rem; color: var(--text-muted);">
                    Belum ada cursor yang tersimpan. Sinkronisasi berikutnya akan mengambil data dari awal.
                </div>
            <?php else: ?>
                <div style="font-size: 0.8125rem;">
                    <?php foreach ($cursors as $cur): ?>
                        <div style="background: var(--bg-surface-soft); padding: 8px 10px; border-radius: 6px; margin-bottom: 6px;">
                            <div style="font-weight: 600; color: var(--text-main); margin-bottom: 2px;">
                                Sumber: <?= e($cur['resource_identifier']) ?>
                            </div>
                            <div style="font-family: monospace; color: var(--brand-primary); font-size: 0.75rem; word-break: break-all;">
                                Cursor: <?= e($cur['cursor_value']) ?>
                            </div>
                            <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px;">
                                Terakhir disimpan: <?= date('d M Y H:i:s', strtotime($cur['updated_at'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Card 3: Settings Form -->
        <div class="card" style="padding: 20px;">
            <h3 style="font-size: 0.9375rem; font-weight: 600; color: var(--text-main); margin: 0 0 14px 0;">
                Pengaturan Interval & Sinkronisasi
            </h3>

            <form action="/connectors/<?= $connection['id'] ?>/settings" method="POST">
                <div style="margin-bottom: 10px;">
                    <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">Nama Koneksi</label>
                    <input type="text" name="name" class="form-control" value="<?= e($connection['name']) ?>" required style="width: 100%; padding: 6px 10px; background: var(--bg-surface-soft); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-size: 0.8125rem;">
                </div>

                <div style="margin-bottom: 10px;">
                    <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">Interval Sinkronisasi</label>
                    <select name="sync_interval_minutes" class="form-control" style="width: 100%; padding: 6px 10px; background: var(--bg-surface-soft); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-size: 0.8125rem;">
                        <option value="15" <?= $connection['sync_interval_minutes'] == 15 ? 'selected' : '' ?>>15 Menit</option>
                        <option value="30" <?= $connection['sync_interval_minutes'] == 30 ? 'selected' : '' ?>>30 Menit</option>
                        <option value="60" <?= $connection['sync_interval_minutes'] == 60 ? 'selected' : '' ?>>1 Jam</option>
                        <option value="120" <?= $connection['sync_interval_minutes'] == 120 ? 'selected' : '' ?>>2 Jam</option>
                        <option value="360" <?= $connection['sync_interval_minutes'] == 360 ? 'selected' : '' ?>>6 Jam</option>
                        <option value="1440" <?= $connection['sync_interval_minutes'] == 1440 ? 'selected' : '' ?>>24 Jam</option>
                    </select>
                </div>

                <?php if ($connection['connector_code'] === 'google_sheets'): ?>
                    <div style="border-top: 1px solid var(--border-color); margin: 12px 0 10px 0; padding-top: 10px;">
                        <span style="font-size: 0.75rem; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 8px;">Kredensial & Sumber Dokumen</span>
                        
                        <div style="margin-bottom: 10px;">
                            <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">Spreadsheet ID atau URL Dokumen *</label>
                            <input type="text" name="spreadsheet_id" class="form-control" value="<?= e($credentials['spreadsheet_id'] ?? $credentials['spreadsheet_url'] ?? '') ?>" placeholder="https://docs.google.com/spreadsheets/d/... atau kode ID" style="width: 100%; padding: 6px 10px; background: var(--bg-surface-soft); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-size: 0.8125rem; font-family: monospace;">
                        </div>

                        <div style="margin-bottom: 10px;">
                            <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">Google Service Account JSON</label>
                            <textarea name="service_account_json" class="form-control" rows="3" placeholder="<?= !empty($credentials['service_account_json']) ? '(Kunci Service Account terpasang — kosongkan jika tidak ingin mengubah)' : 'Tempelkan isi file JSON kunci Service Account' ?>" style="width: 100%; padding: 6px 10px; background: var(--bg-surface-soft); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-size: 0.75rem; font-family: monospace;"></textarea>
                        </div>

                        <div style="margin-bottom: 10px;">
                            <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">Google Cloud API Key (Spreadsheet Publik)</label>
                            <input type="password" name="api_key" class="form-control" placeholder="<?= !empty($credentials['api_key']) ? '(API Key tersimpan — kosongkan jika tidak diubah)' : 'AIzaSy...' ?>" style="width: 100%; padding: 6px 10px; background: var(--bg-surface-soft); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-size: 0.8125rem; font-family: monospace;">
                        </div>

                        <div style="margin-bottom: 10px;">
                            <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">OAuth Access Token (Alternatif)</label>
                            <input type="password" name="access_token" class="form-control" placeholder="<?= !empty($credentials['access_token']) ? '(Access Token tersimpan — kosongkan jika tidak diubah)' : 'ya29.a0AfH6SM...' ?>" style="width: 100%; padding: 6px 10px; background: var(--bg-surface-soft); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-size: 0.8125rem; font-family: monospace;">
                        </div>
                    </div>
                <?php endif; ?>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 14px;">
                    <label style="font-size: 0.75rem; color: var(--text-main); display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                        <input type="checkbox" name="auto_sync_enabled" value="1" <?= $connection['auto_sync_enabled'] ? 'checked' : '' ?> style="accent-color: var(--brand-primary);">
                        Auto-Sync
                    </label>

                    <button type="submit" class="btn btn-secondary btn-sm" style="font-size: 0.75rem;">
                        Simpan Pengaturan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Sync History Section -->
    <div class="card" style="padding: 24px; margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h2 style="font-size: 1.125rem; font-weight: 600; color: var(--text-main); margin: 0;">
                Riwayat Sinkronisasi (Sync History)
            </h2>
            <span style="font-size: 0.8125rem; color: var(--text-muted);"><?= count($history) ?> aktivitas terakhir</span>
        </div>

        <?php if (empty($history)): ?>
            <div style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 0.875rem;">
                Belum ada riwayat proses sinkronisasi untuk koneksi ini. Klik "Sinkronisasi Sekarang" untuk memulai.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem; text-align: left;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-color); color: var(--text-muted);">
                            <th style="padding: 10px;">ID Job</th>
                            <th style="padding: 10px;">Tipe</th>
                            <th style="padding: 10px;">Status</th>
                            <th style="padding: 10px;">Diproses</th>
                            <th style="padding: 10px;">Ditambah</th>
                            <th style="padding: 10px;">Diperbarui</th>
                            <th style="padding: 10px;">Waktu Mulai</th>
                            <th style="padding: 10px;">Selesai</th>
                            <th style="padding: 10px; text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $job): ?>
                            <?php
                                $jobBadge = match ($job['status']) {
                                    'completed' => '<span class="badge badge-success">Selesai</span>',
                                    'running' => '<span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;">Berjalan</span>',
                                    'failed' => '<span class="badge badge-danger">Gagal</span>',
                                    default => '<span class="badge badge-secondary">' . e($job['status']) . '</span>',
                                };
                            ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px; font-weight: 600; color: var(--text-main);">#<?= $job['id'] ?></td>
                                <td style="padding: 10px; text-transform: uppercase; font-size: 0.75rem;"><?= e($job['job_type']) ?></td>
                                <td style="padding: 10px;"><?= $jobBadge ?></td>
                                <td style="padding: 10px;"><?= number_format((int)$job['records_processed']) ?></td>
                                <td style="padding: 10px; color: #10b981; font-weight: 600;">+<?= number_format((int)$job['records_added']) ?></td>
                                <td style="padding: 10px; color: var(--brand-primary);"><?= number_format((int)$job['records_updated']) ?></td>
                                <td style="padding: 10px; color: var(--text-muted);"><?= !empty($job['started_at']) ? date('H:i:s', strtotime($job['started_at'])) : '-' ?></td>
                                <td style="padding: 10px; color: var(--text-muted);"><?= !empty($job['finished_at']) ? date('H:i:s', strtotime($job['finished_at'])) : '-' ?></td>
                                <td style="padding: 10px; text-align: right;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="showJobLogs(<?= $job['id'] ?>)" style="font-size: 0.75rem; padding: 2px 8px;">
                                        Lihat Log
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Latest Job Audit Logs Stream -->
    <?php if (!empty($latestLogs)): ?>
        <div class="card" style="padding: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h2 style="font-size: 1.125rem; font-weight: 600; color: var(--text-main); margin: 0;">
                    Log Audit Sinkronisasi Terakhir (Job #<?= $latestJob['id'] ?>)
                </h2>
                <span class="badge" style="background: rgba(16, 185, 129, 0.12); color: #10b981; font-size: 0.75rem;">
                    Sensor Kredensial Otomatis
                </span>
            </div>

            <div style="background: var(--bg-surface-soft); border-radius: 6px; padding: 12px; font-family: monospace; font-size: 0.8125rem; max-height: 260px; overflow-y: auto;">
                <?php foreach ($latestLogs as $log): ?>
                    <?php
                        $lvlColor = match (strtolower($log['log_level'])) {
                            'error', 'critical' => '#ef4444',
                            'warning' => '#f59e0b',
                            default => '#10b981',
                        };
                    ?>
                    <div style="margin-bottom: 6px; line-height: 1.4;">
                        <span style="color: var(--text-muted);">[<?= date('H:i:s', strtotime($log['created_at'])) ?>]</span>
                        <span style="color: <?= $lvlColor ?>; font-weight: 600;">[<?= strtoupper(e($log['log_level'])) ?>]</span>
                        <span style="color: var(--text-main);"><?= e($log['message']) ?></span>
                        <?php if (!empty($log['context_json'])): ?>
                            <div style="color: var(--text-muted); font-size: 0.75rem; padding-left: 20px;">
                                <?= e($log['context_json']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal for Job Logs -->
<div id="logs-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center;">
    <div class="card" style="width: 90%; max-width: 720px; max-height: 80vh; display: flex; flex-direction: column; padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <h3 style="font-size: 1.125rem; font-weight: 600; color: var(--text-main); margin: 0;" id="modal-title">Log Eksekusi Job</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeLogsModal()">✕</button>
        </div>
        <div id="modal-logs-body" style="background: var(--bg-surface-soft); border-radius: 6px; padding: 12px; font-family: monospace; font-size: 0.8125rem; overflow-y: auto; flex: 1;">
            Memuat...
        </div>
    </div>
</div>

<script>
function testConnection(connId) {
    const btn = document.getElementById('btn-test');
    const badge = document.getElementById('main-status-badge');
    const originalText = btn.innerHTML;
    btn.innerHTML = 'Menguji...';
    btn.disabled = true;

    fetch('<?= e(url('/api/v1/connections')) ?>/' + connId + '/test', {
        method: 'POST',
        headers: { 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        if (data.success) {
            badge.className = 'badge badge-success';
            badge.innerText = 'Terhubung';
            alert('✓ Tes Sukses: ' + (data.message || 'Koneksi siap dan terverifikasi.'));
        } else {
            badge.className = 'badge badge-danger';
            badge.innerText = 'Error';
            const msg = data.message || (data.error && (typeof data.error === 'string' ? data.error : data.error.message)) || 'Gagal tersambung.';
            alert('✗ Tes Gagal: ' + msg);
        }
    })
    .catch(err => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert('Gagal: ' + err.message);
    });
}

function triggerSync(connId) {
    const btn = document.getElementById('btn-sync');
    const originalText = btn.innerHTML;
    btn.innerHTML = 'Sinkronisasi...';
    btn.disabled = true;

    fetch('<?= e(url('/api/v1/connections')) ?>/' + connId + '/sync', {
        method: 'POST',
        headers: { 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        if (data.success) {
            alert('✓ Sinkronisasi Selesai: ' + data.records_processed + ' record diproses (' + data.records_added + ' ditambah, ' + data.records_updated + ' diperbarui) dalam ' + data.duration_seconds + 's.');
            window.location.reload();
        } else {
            const msg = data.message || (data.error && (typeof data.error === 'string' ? data.error : data.error.message)) || 'Terjadi kesalahan saat sinkronisasi.';
            alert('✗ Gagal: ' + msg);
        }
    })
    .catch(err => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert('Gagal: ' + err.message);
    });
}

function showJobLogs(jobId) {
    const modal = document.getElementById('logs-modal');
    const title = document.getElementById('modal-title');
    const body = document.getElementById('modal-logs-body');
    title.innerText = 'Log Eksekusi Job #' + jobId;
    body.innerHTML = 'Memuat log...';
    modal.style.display = 'flex';

    fetch('<?= e(url('/api/v1/connections/logs')) ?>/' + jobId)
    .then(r => r.json())
    .then(res => {
        if (!res.data || res.data.length === 0) {
            body.innerHTML = 'Tidak ada entri log untuk job ini.';
            return;
        }
        let html = '';
        res.data.forEach(item => {
            const color = item.log_level === 'error' ? '#ef4444' : (item.log_level === 'warning' ? '#f59e0b' : '#10b981');
            html += '<div style="margin-bottom: 6px;">' +
                    '<span style="color: var(--text-muted);">[' + item.created_at.substr(11, 8) + ']</span> ' +
                    '<span style="color: ' + color + '; font-weight: 600;">[' + item.log_level.toUpperCase() + ']</span> ' +
                    '<span>' + item.message + '</span>' +
                    (item.context_json ? '<div style="color: var(--text-muted); font-size: 0.75rem; padding-left: 16px;">' + item.context_json + '</div>' : '') +
                    '</div>';
        });
        body.innerHTML = html;
    })
    .catch(err => {
        body.innerHTML = 'Gagal memuat log: ' + err.message;
    });
}

function closeLogsModal() {
    document.getElementById('logs-modal').style.display = 'none';
}
</script>
