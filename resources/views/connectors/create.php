<?php
declare(strict_types=1);

/**
 * AnaBot Connectors - Create Connection View
 */
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);

$currentCode = $selectedCode ?? 'custom_api';
?>

<div class="create-connector-page">
    <div style="margin-bottom: 24px;">
        <a href="/connectors" style="color: var(--text-muted); font-size: 0.8125rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 8px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
            Kembali ke Daftar Konektor
        </a>
        <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin: 0 0 6px 0;">
            Tambah Koneksi Platform Baru
        </h1>
        <p style="color: var(--text-muted); font-size: 0.875rem; margin: 0;">
            Hubungkan sumber data eksternal Anda untuk pengumpulan data otomatis ke basis data internal SQLite.
        </p>
    </div>

    <?php if ($flashError): ?>
        <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; font-size: 0.875rem;">
            <?= e($flashError) ?>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">
        <!-- Form Column -->
        <div class="card" style="padding: 24px;">
            <form action="/connectors" method="POST">
                <!-- Platform Selection -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 0.875rem; font-weight: 600; color: var(--text-main); margin-bottom: 8px;">
                        Pilih Platform yang Ingin Dihubungkan
                    </label>
                    <select name="connector_code" id="connector_code" class="form-control" onchange="window.location.href='/connectors/create?code=' + this.value" style="width: 100%; padding: 10px 12px; background: var(--bg-surface-soft); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main);">
                        <?php foreach ($catalog as $code => $cat): ?>
                            <option value="<?= e($code) ?>" <?= $code === $currentCode ? 'selected' : '' ?>>
                                <?= e($cat['name']) ?> (<?= strtoupper(e($cat['auth_type'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Connection Name -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 0.875rem; font-weight: 600; color: var(--text-main); margin-bottom: 8px;">
                        Nama Koneksi (Label Pengenal) *
                    </label>
                    <input type="text" name="name" class="form-control" required placeholder="Contoh: Toko Online Pusat 2026" value="<?= e($connector['name']) ?> Main" style="width: 100%; padding: 10px 12px; background: var(--bg-surface-soft); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main);">
                    <small style="color: var(--text-muted); font-size: 0.75rem;">Nama unik yang akan ditampilkan pada dashboard dan laporan analisis.</small>
                </div>

                <!-- Credential Fields -->
                <div style="background: var(--bg-surface-soft); border-radius: 8px; padding: 18px; margin-bottom: 20px; border: 1px solid var(--border-color);">
                    <h3 style="font-size: 0.9375rem; font-weight: 600; color: var(--text-main); margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        Kredensial Akses (Terenkripsi AES-256-GCM)
                    </h3>

                    <?php if ($connector['auth_type'] === 'oauth2'): ?>
                        <div style="margin-bottom: 14px;">
                            <label style="display: block; font-size: 0.8125rem; font-weight: 500; color: var(--text-main); margin-bottom: 6px;">
                                Access Token / API Key
                            </label>
                            <input type="password" name="access_token" class="form-control" placeholder="ya29.a0AfH6SM..." style="width: 100%; padding: 9px 12px; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-family: monospace;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.8125rem; font-weight: 500; color: var(--text-main); margin-bottom: 6px;">
                                Refresh Token (Opsional)
                            </label>
                            <input type="password" name="refresh_token" class="form-control" placeholder="1//0g..." style="width: 100%; padding: 9px 12px; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-family: monospace;">
                            <small style="color: var(--text-muted); font-size: 0.75rem;">Digunakan untuk memperbarui token secara otomatis sebelum kadaluarsa.</small>
                        </div>
                    <?php else: ?>
                        <div style="margin-bottom: 14px;">
                            <label style="display: block; font-size: 0.8125rem; font-weight: 500; color: var(--text-main); margin-bottom: 6px;">
                                API Key / Secret Token *
                            </label>
                            <input type="password" name="api_key" class="form-control" required placeholder="sk_live_..." style="width: 100%; padding: 9px 12px; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-family: monospace;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.8125rem; font-weight: 500; color: var(--text-main); margin-bottom: 6px;">
                                Base Endpoint URL (Opsional)
                            </label>
                            <input type="url" name="base_url" class="form-control" placeholder="https://api.domainanda.com/v1" style="width: 100%; padding: 9px 12px; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-family: monospace;">
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Synchronization Settings -->
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 0.9375rem; font-weight: 600; color: var(--text-main); margin: 0 0 12px 0;">
                        Pengaturan Penjadwalan Sinkronisasi
                    </h3>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px;">
                        <div>
                            <label style="display: block; font-size: 0.8125rem; font-weight: 500; color: var(--text-main); margin-bottom: 6px;">
                                Interval Sinkronisasi
                            </label>
                            <select name="sync_interval_minutes" class="form-control" style="width: 100%; padding: 9px 12px; background: var(--bg-surface-soft); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main);">
                                <option value="15">Setiap 15 Menit</option>
                                <option value="30">Setiap 30 Menit</option>
                                <option value="60" selected>Setiap 1 Jam</option>
                                <option value="120">Setiap 2 Jam</option>
                                <option value="360">Setiap 6 Jam</option>
                                <option value="1440">Setiap 24 Jam (Harian)</option>
                            </select>
                        </div>

                        <div style="display: flex; align-items: center; padding-top: 24px;">
                            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.875rem; color: var(--text-main);">
                                <input type="checkbox" name="auto_sync_enabled" value="1" checked style="width: 16px; height: 16px; accent-color: var(--brand-primary);">
                                Aktifkan Sinkronisasi Otomatis
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Form Action Buttons -->
                <div style="display: flex; gap: 12px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                    <button type="submit" class="btn btn-primary" style="padding: 10px 24px;">
                        Simpan & Hubungkan Platform
                    </button>
                    <a href="/connectors" class="btn btn-secondary" style="padding: 10px 20px; text-decoration: none;">
                        Batal
                    </a>
                </div>
            </form>
        </div>

        <!-- Security & Architecture Information Card -->
        <div>
            <div class="card" style="padding: 20px; margin-bottom: 16px; border-left: 4px solid #10b981;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                    <span style="color: #10b981;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        </svg>
                    </span>
                    <h3 style="font-size: 0.9375rem; font-weight: 600; color: var(--text-main); margin: 0;">
                        Keamanan & Perlindungan Data
                    </h3>
                </div>
                <p style="font-size: 0.8125rem; color: var(--text-muted); line-height: 1.5; margin: 0 0 12px 0;">
                    Seluruh kredensial API dan OAuth token Anda dienkripsi secara dua arah menggunakan standar <strong>AES-256-GCM</strong> dengan kunci enkripsi internal. Token tidak pernah disimpan dalam bentuk teks polos.
                </p>
                <div style="font-size: 0.75rem; background: var(--bg-surface-soft); padding: 8px 10px; border-radius: 6px; color: #10b981; font-weight: 500;">
                    ✓ READ-ONLY: AnaBot tidak memiliki hak untuk menambah, mengubah, atau menghapus data di akun eksternal Anda.
                </div>
            </div>

            <div class="card" style="padding: 20px;">
                <h3 style="font-size: 0.9375rem; font-weight: 600; color: var(--text-main); margin: 0 0 10px 0;">
                    Scope Akses yang Diminta
                </h3>
                <p style="font-size: 0.8125rem; color: var(--text-muted); line-height: 1.4; margin: 0 0 10px 0;">
                    Platform <strong><?= e($connector['name']) ?></strong> menggunakan scope berikut:
                </p>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <?php foreach ($connector['scopes'] as $scope): ?>
                        <code style="font-size: 0.75rem; background: var(--bg-surface-soft); padding: 4px 8px; border-radius: 4px; color: var(--brand-primary); word-break: break-all;">
                            <?= e($scope) ?>
                        </code>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
