<?php
declare(strict_types=1);

/**
 * AnaBot Connectors - Global Sync History View
 */
?>
<div class="sync-history-page">
    <div style="margin-bottom: 24px;">
        <a href="/connectors" style="color: var(--text-muted); font-size: 0.8125rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 8px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
            Kembali ke Daftar Konektor
        </a>
        <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin: 0 0 6px 0;">
            Riwayat Sinkronisasi Seluruh Platform
        </h1>
        <p style="color: var(--text-muted); font-size: 0.875rem; margin: 0;">
            Audit log pekerjaan latar belakang (sync jobs), status eksekusi, dan volume data yang berhasil diintegrasikan.
        </p>
    </div>

    <div class="card" style="padding: 24px;">
        <?php if (empty($jobs)): ?>
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <p>Belum ada riwayat aktivitas sinkronisasi data yang tercatat.</p>
                <a href="/connectors" class="btn btn-primary btn-sm">Buka Daftar Koneksi</a>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem; text-align: left;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-color); color: var(--text-muted);">
                            <th style="padding: 10px;">ID Job</th>
                            <th style="padding: 10px;">Nama Koneksi</th>
                            <th style="padding: 10px;">Tipe</th>
                            <th style="padding: 10px;">Status</th>
                            <th style="padding: 10px;">Diproses</th>
                            <th style="padding: 10px;">Ditambah</th>
                            <th style="padding: 10px;">Diperbarui</th>
                            <th style="padding: 10px;">Waktu Eksekusi</th>
                            <th style="padding: 10px; text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <?php
                                $badge = match ($job['status']) {
                                    'completed' => '<span class="badge badge-success">Selesai</span>',
                                    'running' => '<span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;">Berjalan</span>',
                                    'failed' => '<span class="badge badge-danger">Gagal</span>',
                                    default => '<span class="badge badge-secondary">' . e($job['status']) . '</span>',
                                };
                            ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px; font-weight: 600; color: var(--text-main);">#<?= $job['id'] ?></td>
                                <td style="padding: 10px;">
                                    <a href="/connectors/<?= $job['connection_id'] ?>" style="color: var(--brand-primary); text-decoration: none; font-weight: 500;">
                                        <?= e($job['connection_name']) ?>
                                    </a>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($job['connector_name']) ?></div>
                                </td>
                                <td style="padding: 10px; text-transform: uppercase; font-size: 0.75rem;"><?= e($job['job_type']) ?></td>
                                <td style="padding: 10px;"><?= $badge ?></td>
                                <td style="padding: 10px;"><?= number_format((int)$job['records_processed']) ?></td>
                                <td style="padding: 10px; color: #10b981; font-weight: 600;">+<?= number_format((int)$job['records_added']) ?></td>
                                <td style="padding: 10px; color: var(--brand-primary);"><?= number_format((int)$job['records_updated']) ?></td>
                                <td style="padding: 10px; color: var(--text-muted);">
                                    <?= date('d M Y H:i', strtotime($job['created_at'])) ?>
                                </td>
                                <td style="padding: 10px; text-align: right;">
                                    <a href="/connectors/<?= $job['connection_id'] ?>" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 2px 8px; text-decoration: none;">
                                        Detail
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
