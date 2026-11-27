<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Logger;
use RuntimeException;

/**
 * Resilient, Secure HTTP Client for AnaBot Connectors
 * 
 * Features:
 * - Built on PHP Native 8.4 cURL (zero external dependencies)
 * - Strict timeout limits (10s connect, 30s execution)
 * - Response size limit (default 10 MB) to prevent shared hosting memory exhaustion
 * - SSRF Protection (blocks RFC1918, link-local, loopback, and cloud metadata IPs)
 * - Exponential backoff with jitter on HTTP 429 & 503
 * - Strict SSL verification enabled by default
 * - Structured audit logging with automatic redaction of sensitive credentials
 */
class HttpClient
{
    private int $connectTimeout;
    private int $timeout;
    private int $maxRetries;
    private int $maxResponseSize;
    private bool $verifySsl;
    private bool $allowLocal;
    private string $userAgent;
    private ?Logger $logger;

    public function __construct(
        int $connectTimeout = 10,
        int $timeout = 30,
        int $maxRetries = 3,
        int $maxResponseSize = 10485760, // 10MB
        bool $verifySsl = true,
        bool $allowLocal = false,
        ?Logger $logger = null
    ) {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->maxResponseSize = $maxResponseSize;
        $this->verifySsl = $verifySsl;
        $this->allowLocal = $allowLocal;
        $this->userAgent = 'AnaBot-Platform/1.0 (+https://anabot.local; PHP Native 8.4)';
        $this->logger = $logger;
    }

    /**
     * Send GET request.
     */
    public function get(string $url, array $headers = [], array $options = []): array
    {
        return $this->request('GET', $url, null, $headers, $options);
    }

    /**
     * Send POST request.
     */
    public function post(string $url, mixed $data = null, array $headers = [], array $options = []): array
    {
        return $this->request('POST', $url, $data, $headers, $options);
    }

    /**
     * Send PUT request.
     */
    public function put(string $url, mixed $data = null, array $headers = [], array $options = []): array
    {
        return $this->request('PUT', $url, $data, $headers, $options);
    }

    /**
     * Send DELETE request.
     */
    public function delete(string $url, array $headers = [], array $options = []): array
    {
        return $this->request('DELETE', $url, null, $headers, $options);
    }

    /**
     * Core request executor with SSRF check, retry backoff, and size limit.
     */
    public function request(string $method, string $url, mixed $data = null, array $headers = [], array $options = []): array
    {
        $method = strtoupper($method);

        // 1. SSRF Validation
        $this->validateUrlForSsrf($url);

        $attempt = 0;
        $maxAttempts = 1 + max(0, $this->maxRetries);

        while ($attempt < $maxAttempts) {
            $attempt++;
            $startTime = microtime(true);

            $ch = curl_init();

            // Set standard cURL options
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $options['connect_timeout'] ?? $this->connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? $this->timeout);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $options['verify_ssl'] ?? $this->verifySsl);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ($options['verify_ssl'] ?? $this->verifySsl) ? 2 : 0);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Prevent open redirect bypass

            // Prepare headers
            $preparedHeaders = $headers;
            $requestBody = null;

            if ($data !== null) {
                if (is_array($data) || is_object($data)) {
                    $requestBody = json_encode($data, JSON_UNESCAPED_SLASHES);
                    $hasContentType = false;
                    foreach ($preparedHeaders as $h) {
                        if (stripos($h, 'Content-Type:') === 0) {
                            $hasContentType = true;
                            break;
                        }
                    }
                    if (!$hasContentType) {
                        $preparedHeaders[] = 'Content-Type: application/json';
                    }
                } else {
                    $requestBody = (string) $data;
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $preparedHeaders);

            // Execute request
            $rawResponse = curl_exec($ch);
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            if (is_resource($ch)) {
                @curl_close($ch);
            }
            unset($ch);

            // Redacted logging
            $this->logRequest($method, $url, $preparedHeaders, $requestBody, $statusCode, $durationMs, $curlErrno ? $curlError : null);

            // Handle network/timeout errors
            if ($curlErrno !== 0) {
                $isRetryable = in_array($curlErrno, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_RECV_ERROR, CURLE_SEND_ERROR], true);
                if ($isRetryable && $attempt < $maxAttempts) {
                    $this->backoff($attempt, 0);
                    continue;
                }
                throw new RuntimeException("HTTP Request failed [cURL {$curlErrno}]: {$curlError}");
            }

            // Parse response headers & body
            $rawHeaders = substr((string) $rawResponse, 0, $headerSize);
            $responseBody = substr((string) $rawResponse, $headerSize);

            // Enforce response size guard
            if (strlen($responseBody) > $this->maxResponseSize) {
                throw new RuntimeException("HTTP response body size (" . strlen($responseBody) . " bytes) exceeds maximum limit of {$this->maxResponseSize} bytes.");
            }

            $parsedHeaders = $this->parseHeaders($rawHeaders);

            // Retry on HTTP 429 (Rate Limit) or HTTP 503 (Service Unavailable)
            if (($statusCode === 429 || $statusCode === 503) && $attempt < $maxAttempts) {
                $retryAfter = $this->parseRetryAfter($parsedHeaders['retry-after'] ?? null);
                $this->backoff($attempt, $retryAfter);
                continue;
            }

            // Parse JSON body if applicable
            $parsedJson = null;
            $contentType = $parsedHeaders['content-type'] ?? '';
            if (str_contains($contentType, 'application/json') || str_contains($contentType, '+json')) {
                $parsedJson = json_decode($responseBody, true);
            }

            return [
                'status' => $statusCode,
                'headers' => $parsedHeaders,
                'body' => $responseBody,
                'json' => $parsedJson,
                'duration_ms' => $durationMs,
            ];
        }

        throw new RuntimeException("HTTP request to {$url} exceeded maximum retry attempts ({$this->maxRetries}).");
    }

    /**
     * Validate URL against SSRF vulnerabilities.
     */
    public function validateUrlForSsrf(string $url): void
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException("Invalid URL format: {$url}");
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Unsupported URL scheme '{$scheme}'. Only http and https are permitted.");
        }

        $host = strtolower($parts['host']);

        // Check if host is direct IP or resolve DNS
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            // Host could not be resolved
            throw new RuntimeException("Failed to resolve hostname: {$host}");
        }

        if ($this->allowLocal) {
            return;
        }

        if ($this->isPrivateOrReservedIp($ip)) {
            throw new RuntimeException("SSRF Protection: Access to private/reserved IP '{$ip}' for host '{$host}' is strictly blocked.");
        }
    }

    /**
     * Check if an IP address belongs to private, loopback, or link-local ranges.
     */
    public function isPrivateOrReservedIp(string $ip): bool
    {
        // IPv6 Check
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($ip === '::1') return true; // Loopback
            $firstWord = strtolower(explode(':', $ip)[0]);
            if (str_starts_with($firstWord, 'fc') || str_starts_with($firstWord, 'fd')) return true; // ULA
            if (str_starts_with($firstWord, 'fe8') || str_starts_with($firstWord, 'fe9') || str_starts_with($firstWord, 'fea') || str_starts_with($firstWord, 'feb')) return true; // Link-local
            return false;
        }

        // IPv4 Check
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        $ranges = [
            ['0.0.0.0', '0.255.255.255'],       // Current network
            ['10.0.0.0', '10.255.255.255'],     // Class A private
            ['127.0.0.0', '127.255.255.255'],   // Loopback
            ['169.254.0.0', '169.254.255.255'], // Link-local / Cloud metadata (169.254.169.254)
            ['172.16.0.0', '172.31.255.255'],   // Class B private
            ['192.168.0.0', '192.168.255.255'], // Class C private
            ['224.0.0.0', '239.255.255.255'],   // Multicast
            ['240.0.0.0', '255.255.255.255'],   // Reserved
        ];

        foreach ($ranges as [$min, $max]) {
            if ($long >= ip2long($min) && $long <= ip2long($max)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exponential backoff delay with random jitter.
     */
    private function backoff(int $attempt, int $explicitRetryAfter): void
    {
        if ($explicitRetryAfter > 0) {
            $delayMs = min(30000, $explicitRetryAfter * 1000);
        } else {
            // Base delay 500ms: 500ms, 1000ms, 2000ms + jitter (0-200ms)
            $baseMs = (int) (500 * (2 ** ($attempt - 1)));
            $jitterMs = random_int(0, 200);
            $delayMs = min(10000, $baseMs + $jitterMs);
        }

        usleep($delayMs * 1000);
    }

    /**
     * Parse Retry-After header (either seconds or HTTP-Date).
     */
    private function parseRetryAfter(?string $header): int
    {
        if (empty($header)) {
            return 0;
        }
        if (is_numeric($header)) {
            return max(1, (int) $header);
        }
        $timestamp = strtotime($header);
        if ($timestamp !== false) {
            return max(1, $timestamp - time());
        }
        return 0;
    }

    /**
     * Parse raw headers string into lowercase associative array.
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $lines = explode("\r\n", $rawHeaders);
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return $headers;
    }

    /**
     * Structured logging with sensitive token redaction.
     */
    private function logRequest(string $method, string $url, array $headers, ?string $body, int $status, float $durationMs, ?string $error): void
    {
        if ($this->logger === null) {
            return;
        }

        // Redact URL query parameters like access_token, key, secret
        $redactedUrl = preg_replace('/([?&](?:access_token|api_key|key|token|client_secret)=)[^&]+/i', '$1***REDACTED***', $url);

        // Redact authorization headers
        $cleanHeaders = [];
        foreach ($headers as $h) {
            if (preg_match('/^(authorization|x-api-key|cookie):\s*(.+)$/i', $h, $m)) {
                $cleanHeaders[] = $m[1] . ': ***REDACTED***';
            } else {
                $cleanHeaders[] = $h;
            }
        }

        $context = [
            'method' => $method,
            'url' => $redactedUrl,
            'status' => $status,
            'duration_ms' => $durationMs,
            'headers' => $cleanHeaders,
        ];

        if ($error !== null) {
            $context['error'] = $error;
            $this->logger->error("HTTP request error: {$method} {$redactedUrl}", $context);
        } else {
            $this->logger->debug("HTTP request: {$method} {$redactedUrl} ({$status})", $context);
        }
    }
}
