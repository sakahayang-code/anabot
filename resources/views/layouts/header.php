<?php
declare(strict_types=1);

/**
 * AnaBot Top Header Navbar Component
 */

use AnaBot\Core\Auth;

$user = Auth::user() ?? ['username' => 'Admin', 'email' => 'admin@anabot.local'];
$title = $pageTitle ?? 'Dashboard';
?>
<header class="app-header">
    <div class="header-left">
        <button id="hamburgerBtn" class="hamburger-btn" aria-label="Toggle navigation">
            ☰
        </button>
        <div class="page-title">
            <span><?= e($title) ?></span>
            <span class="badge badge-info" style="font-size: 0.65rem;">v<?= app_version() ?></span>
        </div>
    </div>

    <div class="header-right">
        <!-- Theme Toggle (Dark / Light) -->
        <button id="themeToggleBtn" class="theme-toggle-btn" title="Ganti Tema (Gelap/Terang)">
            <span id="themeToggleIcon">☀️</span>
        </button>

        <!-- User Profile Pill -->
        <div class="user-profile-menu">
            <div class="user-avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
            <span><?= e($user['username']) ?></span>
        </div>

        <!-- Logout Button -->
        <form method="POST" action="<?= e(url('/logout')) ?>" style="margin: 0;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline btn-sm" title="Keluar dari sesi" style="color: var(--danger); border-color: rgba(239, 68, 68, 0.3);">
                Keluar
            </button>
        </form>
    </div>
</header>
