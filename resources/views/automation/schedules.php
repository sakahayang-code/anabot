<?php
declare(strict_types=1);

/**
 * AnaBot Automation Schedules View (resources/views/automation/schedules.php)
 */
?>

<!-- Header -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.25rem 0;">
            ⚙️ Jadwal Otomasi & Cron
        </h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Kelola tugas berkala, otomatisasi sinkronisasi konektor, pencadangan database, dan pemantauan.
        </p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <a href="/automation/tasks" class="btn btn-outline btn-sm">
            <span>📋</span> Antrean Background
        </a>
        <a href="/automation/health" class="btn btn-outline btn-sm">
            <span>🩺</span> Kesehatan Sistem
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

<!-- Cron Status & Setup Bento Cards -->
<div class="bento-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
    <!-- Card 1: Cron Status -->
    <div class="card" style="padding: 1.25rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); margin-bottom: 0.5rem;">
            Status Eksekusi Cron
        </div>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <?php
            $cronBadge = match ($cronStatus['status']) {
                'ACTIVE'  => ['bg' => '#064e3b', 'color' => '#34d399', 'icon' => '🟢', 'text' => 'AKTIF (Berjalan Normal)'],
                'DELAYED' => ['bg' => '#78350f', 'color' => '#fbbf24', 'icon' => '🟡', 'text' => 'TERLAMBAT (> 30 mnt)'],
                default   => ['bg' => '#7f1d1d', 'color' => '#f87171', 'icon' => '🔴', 'text' => 'TIDAK AKTIF / BELUM JALAN'],
            };
            ?>
            <span style="font-size: 1.25rem;"><?= $cronBadge['icon'] ?></span>
            <div>
                <div style="font-size: 1.1rem; font-weight: 700; color: <?= $cronBadge['color'] ?>;">
                    <?= $cronBadge['text'] ?>
                </div>
                <div style="font-size: 0.8rem; color: var(--text-secondary);">
                    Terakhir aktif: <?= e($cronStatus['last_heartbeat_ago']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Card 2: Active Tasks Count -->
    <div class="card" style="padding: 1.25rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); margin-bottom: 0.5rem;">
            Total Tugas Terdaftar
        </div>
        <div style="font-size: 1.8rem; font-weight: 700; color: var(--text-primary); line-height: 1.2;">
            <?= count($tasks) ?> <span style="font-size: 0.9rem; font-weight: 400; color: var(--text-secondary);">Tugas</span>
        </div>
        <div style="font-size: 0.8rem; color: #34d399; margin-top: 0.25rem;">
            ✓ <?= count(array_filter($tasks, fn($t) => ((int)$t['is_active']) === 1)) ?> aktif otomatis
        </div>
    </div>

    <!-- Card 3: Quick Trigger -->
    <div class="card" style="padding: 1.25rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); margin-bottom: 0.5rem;">
            Pemicu Manual Cepat
        </div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.25rem;">
            <form action="/automation/backup/now" method="POST" style="margin: 0;">
                <button type="submit" class="btn btn-outline btn-sm">💾 Backup Sekarang</button>
            </form>
            <form action="/automation/logs/rotate" method="POST" style="margin: 0;">
                <button type="submit" class="btn btn-outline btn-sm">📜 Rotasi Log</button>
            </form>
        </div>
    </div>
</div>

<!-- Crontab Setup Instructions Card -->
<div class="card" style="margin-bottom: 2rem; background: var(--bg-surface); border: 1px solid var(--border-color); padding: 1.5rem;">
    <h3 style="font-size: 1.05rem; font-weight: 600; color: var(--text-primary); margin: 0 0 0.5rem 0;">
        📌 Konfigurasi Cron Job di cPanel / Hosting
    </h3>
    <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 1rem 0;">
        Untuk menjalankan seluruh tugas terjadwal secara otomatis tanpa henti, tambahkan baris perintah berikut pada menu <strong>Cron Jobs</strong> di panel hosting Anda:
    </p>

    <div style="background: #0f172a; border: 1px solid #334155; border-radius: 6px; padding: 0.85rem 1rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; overflow-x: auto;">
        <code style="color: #38bdf8; font-family: monospace; font-size: 0.9rem; word-break: break-all;">
            <?= e($crontabCommand) ?>
        </code>
        <button onclick="navigator.clipboard.writeText('<?= addslashes($crontabCommand) ?>'); alert('Perintah Crontab disalin ke clipboard!');" class="btn btn-outline btn-sm" style="white-space: nowrap;">
            📋 Salin Perintah
        </button>
    </div>

    <div style="margin-top: 1rem; font-size: 0.8rem; color: var(--text-secondary); border-top: 1px solid var(--border-color); padding-top: 0.75rem;">
        <strong>Hosting Tanpa Akses CLI?</strong> Gunakan URL Web Cron berikut untuk dipicu via UptimeRobot / cron-job.org:<br>
        <code style="color: #a78bfa; word-break: break-all;"><?= e($webCronUrl) ?></code>
    </div>
</div>

<!-- Scheduled Tasks Table Card -->
<div class="card" style="padding: 1.5rem; margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;">
        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin: 0;">
            Daftar Tugas Terjadwal (Scheduled Tasks)
        </h3>
        <button onclick="document.getElementById('newTaskForm').style.display = document.getElementById('newTaskForm').style.display === 'none' ? 'block' : 'none';" class="btn btn-primary btn-sm">
            ➕ Tambah Tugas Kustom
        </button>
    </div>

    <!-- Hidden Form for Creating New Task -->
    <div id="newTaskForm" style="display: none; background: rgba(30, 41, 59, 0.5); border: 1px dashed var(--border-color); border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
        <h4 style="font-size: 0.95rem; font-weight: 600; color: var(--text-primary); margin: 0 0 1rem 0;">Buat Tugas Terjadwal Kustom</h4>
        <form action="/automation/schedules/create" method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) 120px; gap: 1rem; align-items: end;">
            <div>
                <label style="display: block; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Tipe Tugas</label>
                <select name="task_type" class="form-control" required style="width: 100%; padding: 0.5rem; background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 4px;">
                    <option value="sync_connectors">Sinkronisasi Konektor (sync_connectors)</option>
                    <option value="process_queue">Pemrosesan Antrean (process_queue)</option>
                    <option value="database_backup">Cadangan Database (database_backup)</option>
                    <option value="log_rotation">Rotasi Log (log_rotation)</option>
                    <option value="report_cleanup">Pembersihan Laporan (report_cleanup)</option>
                    <option value="anomaly_monitoring">Pemantauan Anomali (anomaly_monitoring)</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Nama Tugas</label>
                <input type="text" name="task_name" placeholder="Misal: Sinkronisasi Rutin Harian" class="form-control" required style="width: 100%; padding: 0.5rem; background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 4px;">
            </div>
            <div>
                <label style="display: block; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Ekspresi Cron (5 bagian)</label>
                <input type="text" name="cron_expression" placeholder="Contoh: */30 * * * *" value="0 * * * *" class="form-control" required style="width: 100%; padding: 0.5rem; background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 4px;">
            </div>
            <div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">Simpan</button>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-color); text-align: left; color: var(--text-secondary);">
                    <th style="padding: 0.75rem 0.5rem;">Nama Tugas</th>
                    <th style="padding: 0.75rem 0.5rem;">Tipe</th>
                    <th style="padding: 0.75rem 0.5rem;">Ekspresi Cron</th>
                    <th style="padding: 0.75rem 0.5rem;">Terakhir Jalan</th>
                    <th style="padding: 0.75rem 0.5rem;">Jadwal Berikutnya</th>
                    <th style="padding: 0.75rem 0.5rem;">Status</th>
                    <th style="padding: 0.75rem 0.5rem; text-align: right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $t): ?>
                    <?php $isActive = ((int) $t['is_active']) === 1; ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 0.85rem 0.5rem; font-weight: 600; color: var(--text-primary);">
                            <?= e($t['task_name']) ?>
                        </td>
                        <td style="padding: 0.85rem 0.5rem; color: var(--text-secondary);">
                            <code style="background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 4px;"><?= e($t['task_type']) ?></code>
                        </td>
                        <td style="padding: 0.85rem 0.5rem;">
                            <code style="color: #38bdf8; font-weight: 700;"><?= e($t['cron_expression']) ?></code>
                        </td>
                        <td style="padding: 0.85rem 0.5rem; color: var(--text-secondary);">
                            <?= !empty($t['last_run_at']) ? date('d M Y H:i', strtotime($t['last_run_at'])) : '<span style="color: #64748b;">Belum pernah</span>' ?>
                        </td>
                        <td style="padding: 0.85rem 0.5rem; color: #a78bfa;">
                            <?= !empty($t['next_run_at']) ? date('d M Y H:i', strtotime($t['next_run_at'])) : '-' ?>
                        </td>
                        <td style="padding: 0.85rem 0.5rem;">
                            <form action="/automation/schedules/<?= (int) $t['id'] ?>/toggle" method="POST" style="margin: 0;">
                                <button type="submit" style="background: none; border: none; cursor: pointer; padding: 0;">
                                    <?php if ($isActive): ?>
                                        <span class="badge" style="background: #064e3b; color: #34d399; font-size: 0.75rem; padding: 3px 8px; border-radius: 12px;">● Aktif</span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #374151; color: #9ca3af; font-size: 0.75rem; padding: 3px 8px; border-radius: 12px;">○ Non-aktif</span>
                                    <?php endif; ?>
                                </button>
                            </form>
                        </td>
                        <td style="padding: 0.85rem 0.5rem; text-align: right;">
                            <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                <form action="/automation/schedules/<?= (int) $t['id'] ?>/run" method="POST" style="margin: 0;">
                                    <button type="submit" class="btn btn-outline btn-sm" title="Jalankan sekarang" style="padding: 2px 8px; font-size: 0.75rem;">
                                        ▶ Jalankan
                                    </button>
                                </form>
                                <form action="/automation/schedules/<?= (int) $t['id'] ?>/delete" method="POST" style="margin: 0;" onsubmit="return confirm('Hapus tugas terjadwal ini?');">
                                    <button type="submit" class="btn btn-outline btn-sm" style="padding: 2px 8px; font-size: 0.75rem; color: #f87171; border-color: rgba(239,68,68,0.3);">
                                        ✕
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
