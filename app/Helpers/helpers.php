<?php
declare(strict_types=1);

/**
 * AnaBot Global Helper Functions
 */

use AnaBot\Core\App;

if (!function_exists('app')) {
    function app(?string $service = null): mixed
    {
        $instance = App::getInstance();
        if ($service === null) {
            return $instance;
        }
        return $instance->get($service);
    }
}

if (!function_exists('load_env')) {
    function load_env(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                // Strip unquoted inline comments
                if (!str_starts_with($value, '"') && !str_starts_with($value, "'") && str_contains($value, ' #')) {
                    $value = trim(explode(' #', $value, 2)[0]);
                }

                // Strip quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value,
        };
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        static $configs = [];
        $parts = explode('.', $key);
        $file = array_shift($parts);

        if (!isset($configs[$file])) {
            $path = dirname(__DIR__, 2) . '/config/' . $file . '.php';
            if (file_exists($path)) {
                $configs[$file] = require $path;
            } else {
                $configs[$file] = [];
            }
        }

        $current = $configs[$file];
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            App::getInstance()->startSession();
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('is_cli')) {
    function is_cli(): bool
    {
        return PHP_SAPI === 'cli' || defined('STDIN');
    }
}

if (!function_exists('mask_token')) {
    function mask_token(string $token, int $visible = 4): string
    {
        $len = strlen($token);
        if ($len <= $visible * 2) {
            return str_repeat('*', $len);
        }
        return substr($token, 0, $visible) . str_repeat('*', max(0, $len - $visible * 2)) . substr($token, -$visible);
    }
}

if (!function_exists('app_version')) {
    function app_version(): string
    {
        return '1.0.0-dev';
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $subpath = ''): string
    {
        $base = config('app.paths.storage', dirname(__DIR__, 2) . '/storage');
        return $subpath ? rtrim($base, '/') . '/' . ltrim($subpath, '/') : $base;
    }
}

if (!function_exists('view')) {
    function view(string $viewPath, array $data = [], int $status = 200): void
    {
        ob_start();
        extract($data, EXTR_SKIP);
        $baseDir = (string) config('app.paths.views', dirname(__DIR__, 2) . '/resources/views');
        $fullPath = rtrim($baseDir, '/') . '/' . ltrim($viewPath, '/') . '.php';
        if (!file_exists($fullPath)) {
            throw new \RuntimeException("View template not found at [{$fullPath}].");
        }
        require $fullPath;
        $content = ob_get_clean();

        \AnaBot\Core\Response::view('layouts/main', [
            'pageTitle' => $data['pageTitle'] ?? 'AnaBot',
            'content' => $content,
        ], $status);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): void
    {
        \AnaBot\Core\Response::redirect($url, $status);
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $appUrl = env('APP_URL');
        if (empty($appUrl) || $appUrl === 'http://localhost:8080' || $appUrl === 'http://localhost:8000') {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            $scheme = $isHttps ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $baseDir = trim(str_replace('\\', '/', dirname($scriptName)), '/');
            if (str_ends_with($baseDir, '/public')) {
                $baseDir = substr($baseDir, 0, -7);
            } elseif ($baseDir === 'public') {
                $baseDir = '';
            }

            $appUrl = !empty($baseDir) ? "{$scheme}://{$host}/{$baseDir}" : "{$scheme}://{$host}";
        } else {
            $appUrl = rtrim((string) $appUrl, '/');
        }

        $cleanPath = ltrim($path, '/');
        return $cleanPath !== '' ? $appUrl . '/' . $cleanPath : $appUrl;
    }
}

if (!function_exists('is_api_request')) {
    function is_api_request(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_starts_with($uri, '/api/') || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
    }
}

if (!function_exists('format_bytes')) {
    function format_bytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min((int) $pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

