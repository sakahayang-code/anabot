<?php
declare(strict_types=1);

/**
 * AnaBot Shared Hosting Compatibility & Health Diagnostic Tool
 * 
 * Verifies system requirements, PHP extensions, SQLite features,
 * and directory permissions for zero-Docker, zero-Redis shared hosting environments.
 * 
 * Can be run via CLI (`php bin/hosting_check.php`) or accessed via web browser.
 */

$isCli = (php_sapi_name() === 'cli');

$results = [
    'checks' => [],
    'passed' => 0,
    'warnings' => 0,
    'failed' => 0,
];

function recordCheck(string $category, string $item, bool $passed, string $message, bool $isWarning = false): void
{
    global $results;
    $status = $passed ? 'PASS' : ($isWarning ? 'WARN' : 'FAIL');
    if ($status === 'PASS') {
        $results['passed']++;
    } elseif ($status === 'WARN') {
        $results['warnings']++;
    } else {
        $results['failed']++;
    }

    $results['checks'][] = [
        'category' => $category,
        'item'     => $item,
        'status'   => $status,
        'message'  => $message,
    ];
}

// 1. PHP Version Check (PHP >= 8.4.0)
$phpVersion = PHP_VERSION;
$versionOk = version_compare($phpVersion, '8.4.0', '>=');
recordCheck(
    'PHP Runtime',
    'PHP Version',
    $versionOk,
    "Terpasang PHP {$phpVersion} (Persyaratan: >= 8.4.0)"
);

// 2. Required PHP Extensions Check
$requiredExtensions = [
    'pdo'         => 'Database Abstraction Layer',
    'pdo_sqlite'  => 'SQLite 3 Database Driver',
    'openssl'     => 'AES-256-GCM Encryption & RS256 JWT Signing',
    'curl'        => 'Resilient HTTP Client with SSRF Protection',
    'json'        => 'JSON Parser & Serializer',
    'mbstring'    => 'Multi-byte UTF-8 String Processing',
    'zip'         => 'OpenXML XLSX Spreadsheet Generation',
    'zlib'        => 'PDF & Gzip Compression Stream',
];

foreach ($requiredExtensions as $ext => $purpose) {
    $loaded = extension_loaded($ext);
    recordCheck(
        'Ekstensi PHP',
        "ext-{$ext}",
        $loaded,
        $loaded ? "Tersedia ({$purpose})" : "TIDAK DITEMUKAN ({$purpose} wajib aktif)"
    );
}

// 3. SQLite Capabilities & Pragmas
try {
    $tempDbPath = sys_get_temp_dir() . '/anabot_diag_' . uniqid() . '.sqlite';
    $pdo = new PDO("sqlite:{$tempDbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Busy timeout
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $bt = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
    recordCheck('Fitur SQLite', 'PRAGMA busy_timeout', $bt === 5000, "Nilai busy_timeout: {$bt}ms");

    // Foreign Keys
    $pdo->exec('PRAGMA foreign_keys = ON');
    $fk = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
    recordCheck('Fitur SQLite', 'PRAGMA foreign_keys', $fk === 1, "Status foreign_keys: " . ($fk ? 'Aktif' : 'Non-aktif'));

    // WAL Mode
    $wal = (string) $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();
    $walOk = strtolower($wal) === 'wal';
    recordCheck('Fitur SQLite', 'PRAGMA journal_mode=WAL', $walOk, "Journal mode: " . strtoupper($wal));

    unset($pdo);
    @unlink($tempDbPath);
    @unlink("{$tempDbPath}-shm");
    @unlink("{$tempDbPath}-wal");
} catch (\Throwable $e) {
    recordCheck('Fitur SQLite', 'SQLite Pragma Verification', false, "Error: " . $e->getMessage());
}

// 4. Directory Write Permissions Check
$baseDir = dirname(__DIR__);
$directories = [
    'storage'          => "{$baseDir}/storage",
    'storage/database' => "{$baseDir}/storage/database",
    'storage/logs'     => "{$baseDir}/storage/logs",
    'storage/cache'    => "{$baseDir}/storage/cache",
    'storage/reports'  => "{$baseDir}/storage/reports",
    'storage/backups'  => "{$baseDir}/storage/backups",
];

foreach ($directories as $label => $path) {
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    $isWritable = is_writable($path);
    recordCheck(
        'Izin Direktori',
        $label,
        $isWritable,
        $isWritable ? "Dapat Ditulis (Writable)" : "TIDAK DAPAT DITULIS (Periksa permission chmod 0755/0775)"
    );
}

// 5. Shared Hosting Resource Limits Check
$memoryLimit = ini_get('memory_limit');
$memoryBytes = (function($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $num = (int) $val;
    switch($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return $num;
})($memoryLimit);

// 128M is standard minimum
$memoryOk = ($memoryBytes >= 134217728) || ($memoryLimit === '-1');
recordCheck(
    'Alokasi Sumber Daya',
    'memory_limit',
    $memoryOk,
    "Batas Memori: {$memoryLimit} (Minimal yang disarankan: 128M)",
    !$memoryOk
);

$maxExecutionTime = (int) ini_get('max_execution_time');
$timeoutWarn = ($maxExecutionTime > 0 && $maxExecutionTime < 30);
recordCheck(
    'Alokasi Sumber Daya',
    'max_execution_time',
    !$timeoutWarn,
    "Batas Waktu Eksekusi: " . ($maxExecutionTime === 0 ? "Tidak terbatas (CLI)" : "{$maxExecutionTime} detik"),
    $timeoutWarn
);

// OUTPUT PRESENTATION
if ($isCli) {
    echo "==========================================================" . PHP_EOL;
    echo "  ANABOT SHARED HOSTING COMPATIBILITY DIAGNOSTIC TOOL" . PHP_EOL;
    echo "  PHP: " . PHP_VERSION . " | OS: " . PHP_OS_FAMILY . PHP_EOL;
    echo "==========================================================" . PHP_EOL . PHP_EOL;

    $currentCat = '';
    foreach ($results['checks'] as $c) {
        if ($c['category'] !== $currentCat) {
            $currentCat = $c['category'];
            echo "[ {$currentCat} ]" . PHP_EOL;
        }

        $badge = match ($c['status']) {
            'PASS' => "\033[32m[PASS]\033[0m",
            'WARN' => "\033[33m[WARN]\033[0m",
            default => "\033[31m[FAIL]\033[0m",
        };

        printf("  %-7s %-25s : %s\n", $badge, $c['item'], $c['message']);
    }

    echo PHP_EOL;
    echo "==========================================================" . PHP_EOL;
    echo "  RINGKASAN DIAGNOSTIK:" . PHP_EOL;
    echo "  Lulus    : {$results['passed']}" . PHP_EOL;
    echo "  Peringatan: {$results['warnings']}" . PHP_EOL;
    echo "  Gagal    : {$results['failed']}" . PHP_EOL;
    echo "==========================================================" . PHP_EOL;

    if ($results['failed'] === 0) {
        echo "\033[32m  STATUS: SERVER SIAP MENJALANKAN ANABOT SECARA OPTIMAL!\033[0m" . PHP_EOL;
        exit(0);
    } else {
        echo "\033[31m  STATUS: DITEMUKAN PERSYARATAN YANG BELUM TERPENUHI.\033[0m" . PHP_EOL;
        exit(1);
    }
} else {
    // HTML Browser Output
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>AnaBot - Hosting Compatibility Diagnostics</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 32px 16px; margin: 0; }
            .container { max-width: 800px; margin: 0 auto; background: #1e293b; padding: 28px; border-radius: 12px; border: 1px solid #334155; }
            h1 { font-size: 1.5rem; margin-top: 0; margin-bottom: 8px; color: #38bdf8; }
            .subtitle { color: #94a3b8; font-size: 0.875rem; margin-bottom: 24px; }
            .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; }
            .badge-PASS { background: #065f46; color: #34d399; }
            .badge-WARN { background: #854d0e; color: #facc15; }
            .badge-FAIL { background: #991b1b; color: #f87171; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 0.875rem; }
            th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #334155; }
            th { background: #0f172a; color: #94a3b8; font-size: 0.75rem; text-transform: uppercase; }
            .summary { padding: 16px; border-radius: 8px; margin-top: 20px; font-weight: 600; font-size: 0.9375rem; }
            .summary-ok { background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #34d399; }
            .summary-err { background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #f87171; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>AnaBot Hosting Diagnostics</h1>
            <div class="subtitle">Pemeriksaan Kompatibilitas Lingkungan Server Shared Hosting (cPanel / Apache / LiteSpeed)</div>
            
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Kategori</th>
                        <th>Komponen</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results['checks'] as $c): ?>
                        <tr>
                            <td><span class="badge badge-<?= $c['status'] ?>"><?= $c['status'] ?></span></td>
                            <td style="color: #94a3b8;"><?= htmlspecialchars($c['category']) ?></td>
                            <td style="font-weight: 600;"><?= htmlspecialchars($c['item']) ?></td>
                            <td><?= htmlspecialchars($c['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($results['failed'] === 0): ?>
                <div class="summary summary-ok">
                    ✓ SEMPURNA: Server Anda memenuhi seluruh spesifikasi AnaBot v1.0 (Lulus: <?= $results['passed'] ?>, Peringatan: <?= $results['warnings'] ?>).
                </div>
            <?php else: ?>
                <div class="summary summary-err">
                    ⚠ PERHATIAN: Ditemukan <?= $results['failed'] ?> item kegagalan konfigurasi yang perlu disesuaikan sebelum penerapan.
                </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}
