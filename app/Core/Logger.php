<?php
declare(strict_types=1);

namespace AnaBot\Core;

/**
 * AnaBot Structured Logger with Sensitive Data Masking
 */
class Logger
{
    private string $logPath;
    private array $sensitiveKeys;

    public function __construct(?string $logPath = null)
    {
        $this->logPath = $logPath ?? (string) config('app.paths.logs', dirname(__DIR__, 2) . '/storage/logs');
        $this->sensitiveKeys = (array) config('security.sensitive_keys', [
            'password', 'password_hash', 'api_key', 'token', 'access_token',
            'refresh_token', 'client_secret', 'app_key', 'credentials', 'two_factor_secret'
        ]);

        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('NOTICE', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $cleanContext = $this->maskSensitiveData($context);
        $timestamp = date('c');
        $contextStr = !empty($cleanContext) ? ' ' . json_encode($cleanContext, JSON_UNESCAPED_SLASHES) : '';
        $line = sprintf("[%s] [%s] %s%s%s", $timestamp, strtoupper($level), $message, $contextStr, PHP_EOL);

        $file = rtrim($this->logPath, '/') . '/anabot_' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function maskSensitiveData(array $data): array
    {
        $masked = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;
            foreach ($this->sensitiveKeys as $pattern) {
                if (str_contains($lowerKey, strtolower($pattern))) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $masked[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $masked[$key] = $this->maskSensitiveData($value);
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }
}
