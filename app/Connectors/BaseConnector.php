<?php
declare(strict_types=1);

namespace AnaBot\Connectors;

use AnaBot\Core\Database;
use AnaBot\Core\Logger;
use AnaBot\Services\HttpClient;
use RuntimeException;

/**
 * Base Abstract Connector providing standard resilient capabilities:
 * - Request throttling & rate limit backoff
 * - Sensitive token redaction
 * - Logging integration with sync_logs
 * - Standardized error translation
 */
abstract class BaseConnector implements ConnectorInterface
{
    protected string $connectorCode = 'base';
    protected string $name = 'Base Connector';
    protected string $category = 'custom';
    protected string $authType = 'api_key';

    protected array $config = [];
    protected array $credentials = [];
    protected ?int $connectionId = null;
    protected ?string $nextCursor = null;

    protected ?HttpClient $httpClient = null;
    protected ?Database $db = null;
    protected ?Logger $logger = null;

    public function __construct(
        array $config = [],
        array $credentials = [],
        ?int $connectionId = null,
        ?HttpClient $httpClient = null,
        ?Database $db = null,
        ?Logger $logger = null
    ) {
        $this->config = $config;
        $this->credentials = $credentials;
        $this->connectionId = $connectionId;
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->db = $db ?? Database::getInstance();
        $this->logger = $logger ?? new Logger();
    }

    public function getConnectorCode(): string
    {
        return $this->connectorCode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getAuthType(): string
    {
        return $this->authType;
    }

    public function configure(array $config, array $credentials = []): bool
    {
        $this->config = array_merge($this->config, $config);
        $this->credentials = array_merge($this->credentials, $credentials);
        return true;
    }

    public function getNextCursor(): ?string
    {
        return $this->nextCursor;
    }

    public function disconnect(): bool
    {
        $this->credentials = [];
        $this->nextCursor = null;
        return true;
    }

    /**
     * Log an event with sensitive data masking.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $maskedContext = $this->maskData($context);
            $this->logger->log($level, "[{$this->connectorCode}] {$message}", $maskedContext);
        }
    }

    /**
     * Recursively mask sensitive fields (tokens, passwords, api keys).
     */
    protected function maskData(array $data): array
    {
        $sensitiveKeys = ['token', 'password', 'key', 'secret', 'auth', 'bearer', 'credential'];
        $clean = [];

        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            $isSensitive = false;
            foreach ($sensitiveKeys as $pattern) {
                if (str_contains($lower, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $clean[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $clean[$key] = $this->maskData($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Handle rate-limiting (HTTP 429) using sleep or backoff calculation.
     */
    protected function handleRateLimit(int $retryAfterSeconds = 5): void
    {
        $clamped = max(1, min(60, $retryAfterSeconds));
        $this->log('warning', "Rate limit hit. Waiting {$clamped}s before retry.");
        usleep($clamped * 1000000);
    }
}
