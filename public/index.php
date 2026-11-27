<?php
declare(strict_types=1);

/**
 * AnaBot Web Front Controller
 */

define('ANABOT_START', microtime(true));

// Enhanced error visibility during bootstrap if APP_DEBUG is enabled
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    // 1. Standalone Zero-Dependency PSR-4 Autoloader with Composer fallback
    require_once dirname(__DIR__) . '/bootstrap/autoload.php';

    // 2. Auto-initialize .env from .env.example if missing on shared hosting
    $envPath = dirname(__DIR__) . '/.env';
    $envExample = dirname(__DIR__) . '/.env.example';
    if (!file_exists($envPath) && file_exists($envExample)) {
        $envContent = (string) file_get_contents($envExample);
        $randomKey = bin2hex(random_bytes(32));
        $envContent = preg_replace('/APP_KEY=.*/', 'APP_KEY=' . $randomKey, $envContent);
        @file_put_contents($envPath, $envContent);
    } elseif (file_exists($envPath)) {
        $envContent = (string) file_get_contents($envPath);
        if (preg_match('/^APP_KEY=\s*$/m', $envContent)) {
            $randomKey = bin2hex(random_bytes(32));
            $envContent = preg_replace('/^APP_KEY=\s*$/m', 'APP_KEY=' . $randomKey, $envContent);
            @file_put_contents($envPath, $envContent);
        }
    }

    // 3. Load Environment Variables
    $secretsEnv = env('ANABOT_SECRETS_PATH', '') ? rtrim(env('ANABOT_SECRETS_PATH', ''), '/') . '/.env' : null;
    if ($secretsEnv && file_exists($secretsEnv)) {
        load_env($secretsEnv);
    } elseif (file_exists($envPath) && function_exists('load_env')) {
        load_env($envPath);
    }

    // 4. Adjust display_errors according to APP_DEBUG configuration
    $isDebug = (bool) env('APP_DEBUG', true);
    ini_set('display_errors', $isDebug ? '1' : '0');

    // 5. Initialize Core Application Container
    $app = \AnaBot\Core\App::getInstance();
    $router = $app->getRouter();

    // 6. Load Web and API Route Definitions
    require_once dirname(__DIR__) . '/routes/web.php';
    require_once dirname(__DIR__) . '/routes/api.php';

    // 7. Dispatch Request
    $app->run();
} catch (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $isDebug = (bool) (function_exists('env') ? env('APP_DEBUG', true) : true);
    if ($isDebug) {
        echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>AnaBot - System Diagnostic</title>';
        echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0f172a;color:#f8fafc;padding:2rem;margin:0;}';
        echo '.box{max-width:850px;margin:2rem auto;background:#1e293b;border-radius:12px;padding:2rem;border:1px solid #ef4444;box-shadow:0 10px 25px rgba(0,0,0,0.5);}';
        echo 'h1{color:#ef4444;font-size:1.5rem;margin-top:0;display:flex;align-items:center;gap:8px;}';
        echo '.meta{color:#94a3b8;font-size:0.9rem;margin-bottom:1rem;}';
        echo 'pre{background:#0b1120;padding:1rem;border-radius:8px;overflow:auto;color:#38bdf8;font-size:0.85rem;line-height:1.5;border:1px solid #334155;}</style></head><body>';
        echo '<div class="box"><h1>⚠️ AnaBot Initialization Error</h1>';
        echo '<div class="meta">PHP ' . PHP_VERSION . ' &bull; Server: ' . htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<p><strong>Pesan:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><strong>Lokasi:</strong> ' . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') . ' line ' . $e->getLine() . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre></div></body></html>';
    } else {
        echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>500 - Internal Server Error</title>';
        echo '<style>body{font-family:sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}';
        echo '.box{text-align:center;max-width:500px;background:#1e293b;padding:2rem;border-radius:12px;border:1px solid #334155;}';
        echo 'h1{color:#38bdf8;}</style></head><body>';
        echo '<div class="box"><h1>500 Internal Server Error</h1><p>Terjadi kendala pada sistem server. Silakan hubungi administrator.</p></div></body></html>';
    }
    exit(1);
}
