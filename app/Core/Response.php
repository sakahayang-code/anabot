<?php
declare(strict_types=1);

namespace AnaBot\Core;

/**
 * AnaBot Standard HTTP Response Builder
 */
class Response
{
    public static function json(array $payload, int $status = 200, array $headers = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            foreach ($headers as $header => $value) {
                header("{$header}: {$value}");
            }
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'Operasi berhasil dilakukan', int $status = 200, array $meta = []): void
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => array_merge([
                'timestamp' => date('c'),
            ], $meta),
        ];

        self::json($payload, $status);
    }

    public static function error(string $message, string $errorCode = 'SERVER_ERROR', mixed $details = null, int $status = 400): void
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $message,
                'details' => $details,
            ],
            'meta' => [
                'timestamp' => date('c'),
            ],
        ];

        self::json($payload, $status);
    }

    public static function redirect(string $url, int $status = 302): void
    {
        $target = (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) 
            ? $url 
            : (function_exists('url') ? url($url) : $url);

        if (!headers_sent()) {
            http_response_code($status);
            header("Location: {$target}");
        } else {
            echo "<script>window.location.href=" . json_encode($target) . ";</script>";
        }
        exit;
    }

    public static function view(string $viewPath, array $data = [], int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
        }

        $baseDir = (string) config('app.paths.views', dirname(__DIR__, 2) . '/resources/views');
        $fullPath = rtrim($baseDir, '/') . '/' . ltrim($viewPath, '/') . '.php';

        if (!file_exists($fullPath)) {
            throw new \RuntimeException("View template not found at [{$fullPath}].");
        }

        extract($data, EXTR_SKIP);
        require $fullPath;
        exit;
    }
}
