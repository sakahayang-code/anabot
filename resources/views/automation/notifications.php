<?php
declare(strict_types=1);

/**
 * AnaBot In-App Notifications & Alerts View (resources/views/automation/notifications.php)
 */
?>

<!-- Header -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.25rem 0;">
            🔔 Pusat Notifikasi & Peringatan
        </h1>
        <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
            Peringatan anomali data, status kegagalan sinkronisasi, dan laporan aktivitas sistem.
        </p>
    </div>
    <?php if ($unreadCount > 0): ?>
        <form action="/notifications/read-all" method="POST" style="margin: 0;">
            <button type="submit" class="btn btn-outline btn-sm">
                ✓ Tandai Semua Telah Dibaca (<?= $unreadCount ?>)
            </button>
        </form>
    <?php endif; ?>
</div>

<!-- Flash Alerts -->
<?php if (!empty($_SESSION['flash_success'])): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem;">
        <?= e($_SESSION['flash_success']) ?>
        <?php unset($_SESSION['flash_success']); ?>
    </div>
<?php endif; ?>

<!-- Notifications List -->
<div class="card" style="padding: 1.5rem;">
    <?php if (empty($notifications)): ?>
        <div style="text-align: center; padding: 3.5rem 1rem; color: var(--text-secondary);">
            <div style="font-size: 3rem; margin-bottom: 0.75rem;">🎉</div>
            <h3 style="font-size: 1.15rem; color: var(--text-primary); margin: 0 0 0.25rem 0;">Semua Beres!</h3>
            <p style="font-size: 0.9rem; margin: 0;">Tidak ada notifikasi atau peringatan yang belum dibaca saat ini.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <?php foreach ($notifications as $n): ?>
                <?php
                $isRead = ((int) $n['is_read']) === 1;
                $borderLeftColor = match ($n['type']) {
                    'danger', 'critical' => '#ef4444',
                    'warning'            => '#f59e0b',
                    'success'            => '#10b981',
                    default              => '#3b82f6',
                };
                $icon = match ($n['type']) {
                    'danger', 'critical' => '🚨',
                    'warning'            => '⚠️',
                    'success'            => '✅',
                    default              => 'ℹ️',
                };
                ?>
                <div style="background: <?= $isRead ? 'rgba(30, 41, 59, 0.3)' : 'rgba(30, 41, 59, 0.7)' ?>; border: 1px solid var(--border-color); border-left: 4px solid <?= $borderLeftColor ?>; border-radius: 6px; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                    <div style="display: flex; gap: 0.85rem;">
                        <span style="font-size: 1.25rem; line-height: 1;"><?= $icon ?></span>
                        <div>
                            <div style="font-weight: <?= $isRead ? '600' : '700' ?>; color: var(--text-primary); font-size: 0.95rem; margin-bottom: 0.25rem;">
                                <?= e($n['title']) ?>
                                <?php if (!$isRead): ?>
                                    <span style="background: #3b82f6; color: #fff; font-size: 0.65rem; padding: 2px 6px; border-radius: 10px; margin-left: 6px; vertical-align: middle;">BARU</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.5; margin-bottom: 0.5rem;">
                                <?= e($n['message']) ?>
                            </div>
                            <div style="display: flex; gap: 1rem; align-items: center; font-size: 0.75rem; color: var(--text-secondary);">
                                <span><?= date('d M Y H:i', strtotime($n['created_at'])) ?></span>
                                <?php if (!empty($n['action_url'])): ?>
                                    <a href="<?= e($n['action_url']) ?>" style="color: #38bdf8; text-decoration: none; font-weight: 600;">
                                        Lihat Detail &rarr;
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!$isRead): ?>
                        <form action="/notifications/<?= (int) $n['id'] ?>/read" method="POST" style="margin: 0;">
                            <button type="submit" class="btn btn-outline btn-sm" style="font-size: 0.75rem; padding: 3px 8px; white-space: nowrap;">
                                Tandai Dibaca
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
