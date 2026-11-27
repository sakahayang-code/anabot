<?php
declare(strict_types=1);

/**
 * AnaBot Automation Tasks & Queue Monitor View (resources/views/automation/tasks.php)
 */
?>

<!-- Header -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.25rem 0;">
            📋 Monitoring Antrean Latar Belakang (Sync Queue)
        </h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Pantau status pekerjaan sinkronisasi data yang sedang berjalan, antrean pending, dan riwayat kegagalan.
        </p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <a href="/automation/schedules" class="btn btn-outline btn-sm">
            <span>⚙️</span> Jadwal Otomasi
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

<!-- Queue Health Bento Grid -->
<div class="bento-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
    <!-- Pending -->
    <div class="card" style="padding: 1.25rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 0.25rem;">Pekerjaan Pending</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: #fbbf24;"><?= (int) ($queueHealth['pending_jobs_count'] ?? 0) ?></div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">Menunggu giliran worker</div>
    </div>

    <!-- Running -->
    <div class="card" style="padding: 1.25rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 0.25rem;">Sedang Berjalan</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: #38bdf8;"><?= (int) ($queueHealth['running_jobs_count'] ?? 0) ?></div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">Aktif diproses saat ini</div>
    </div>

    <!-- Completed 24h -->
    <div class="card" style="padding: 1.25rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 0.25rem;">Sukses (24 Jam)</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: #34d399;"><?= (int) ($queueHealth['completed_24h_count'] ?? 0) ?></div>
        <div style="font-size: 0.75rem; color: #34d399;">✓ Sinkronisasi berhasil</div>
    </div>

    <!-- Failed 24h -->
    <div class="card" style="padding: 1.25rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 0.25rem;">Gagal (24 Jam)</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: <?= ((int)($queueHealth['recent_failures_count'] ?? 0)) > 0 ? '#f87171' : 'var(--text-secondary)' ?>;">
            <?= (int) ($queueHealth['recent_failures_count'] ?? 0) ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">Perlu penanganan/retry</div>
    </div>

    <!-- Stalled -->
    <div class="card" style="padding: 1.25rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 0.25rem;">Terhenti (Stalled)</div>
        <div style="font-size: 1.8rem; font-weight: 700; color: <?= ((int)($queueHealth['stalled_jobs_count'] ?? 0)) > 0 ? '#f87171' : 'var(--text-secondary)' ?>;">
            <?= (int) ($queueHealth['stalled_jobs_count'] ?? 0) ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">> 30 menit tanpa update</div>
    </div>
</div>

<!-- Jobs History Table Card -->
<div class="card" style="padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin: 0;">
            Riwayat Pekerjaan Sinkronisasi Antrean
        </h3>
        <span style="font-size: 0.8rem; color: var(--text-secondary);">
            Menampilkan <?= count($jobs) ?> dari total <?= (int) ($pagination['total_items'] ?? 0) ?> pekerjaan
        </span>
    </div>

    <?php if (empty($jobs)): ?>
        <div style="text-align: center; padding: 3rem 1rem; color: var(--text-secondary);">
            <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📭</div>
            <p style="font-size: 0.95rem; margin: 0;">Belum ada pekerjaan sinkronisasi yang tercatat.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color); text-align: left; color: var(--text-secondary);">
                        <th style="padding: 0.75rem 0.5rem;">ID</th>
                        <th style="padding: 0.75rem 0.5rem;">Koneksi / Platform</th>
                        <th style="padding: 0.75rem 0.5rem;">Tipe</th>
                        <th style="padding: 0.75rem 0.5rem;">Status</th>
                        <th style="padding: 0.75rem 0.5rem;">Data Diproses</th>
                        <th style="padding: 0.75rem 0.5rem;">Waktu Mulai</th>
                        <th style="padding: 0.75rem 0.5rem;">Waktu Selesai</th>
                        <th style="padding: 0.75rem 0.5rem; text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <?php
                        $statusBadge = match ($job['status']) {
                            'completed' => ['bg' => '#064e3b', 'color' => '#34d399', 'label' => 'Sukses'],
                            'running'   => ['bg' => '#0c4a6e', 'color' => '#38bdf8', 'label' => 'Berjalan...'],
                            'pending'   => ['bg' => '#78350f', 'color' => '#fbbf24', 'label' => 'Pending'],
                            'failed'    => ['bg' => '#7f1d1d', 'color' => '#f87171', 'label' => 'Gagal'],
                            'partial'   => ['bg' => '#831843', 'color' => '#f472b6', 'label' => 'Sebagian'],
                            default     => ['bg' => '#374151', 'color' => '#9ca3af', 'label' => $job['status']],
                        };
                        ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 0.85rem 0.5rem; font-weight: 700; color: var(--text-secondary);">
                                #<?= (int) $job['id'] ?>
                            </td>
                            <td style="padding: 0.85rem 0.5rem;">
                                <div style="font-weight: 600; color: var(--text-primary);">
                                    <?= e($job['connection_name']) ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                    <?= e($job['connector_name']) ?>
                                </div>
                            </td>
                            <td style="padding: 0.85rem 0.5rem; color: var(--text-secondary);">
                                <code style="background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 4px;"><?= e($job['job_type']) ?></code>
                            </td>
                            <td style="padding: 0.85rem 0.5rem;">
                                <span class="badge" style="background: <?= $statusBadge['bg'] ?>; color: <?= $statusBadge['color'] ?>; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem;">
                                    <?= $statusBadge['label'] ?>
                                </span>
                            </td>
                            <td style="padding: 0.85rem 0.5rem;">
                                <span style="color: var(--text-primary); font-weight: 600;"><?= (int) $job['records_processed'] ?></span>
                                <span style="font-size: 0.75rem; color: var(--text-secondary);">
                                    (+<?= (int) $job['records_added'] ?> / ~<?= (int) $job['records_updated'] ?>)
                                </span>
                                <?php if (!empty($job['error_message'])): ?>
                                    <div style="font-size: 0.75rem; color: #f87171; margin-top: 2px; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e($job['error_message']) ?>">
                                        ⚠️ <?= e($job['error_message']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.85rem 0.5rem; color: var(--text-secondary); font-size: 0.8rem;">
                                <?= !empty($job['started_at']) ? date('d M H:i:s', strtotime($job['started_at'])) : '-' ?>
                            </td>
                            <td style="padding: 0.85rem 0.5rem; color: var(--text-secondary); font-size: 0.8rem;">
                                <?= !empty($job['finished_at']) ? date('d M H:i:s', strtotime($job['finished_at'])) : '-' ?>
                            </td>
                            <td style="padding: 0.85rem 0.5rem; text-align: right;">
                                <?php if ($job['status'] === 'failed'): ?>
                                    <form action="/automation/tasks/<?= (int) $job['id'] ?>/retry" method="POST" style="margin: 0;">
                                        <button type="submit" class="btn btn-outline btn-sm" style="padding: 2px 8px; font-size: 0.75rem; color: #fbbf24; border-color: rgba(251,191,36,0.4);">
                                            ↻ Coba Ulang
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: var(--text-secondary); font-size: 0.75rem;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
            <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 1.5rem;">
                <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
                    <a href="/automation/tasks?page=<?= $p ?>" class="btn btn-sm <?= ($p === (int) $pagination['current_page']) ? 'btn-primary' : 'btn-outline' ?>" style="min-width: 32px; text-align: center;">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
