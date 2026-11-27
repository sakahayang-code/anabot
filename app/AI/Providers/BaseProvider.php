<?php
declare(strict_types=1);

namespace AnaBot\AI\Providers;

use AnaBot\AI\Contracts\AIProviderInterface;
use AnaBot\Core\Logger;
use AnaBot\Services\HttpClient;

/**
 * Abstract Base Provider for AI Integrations
 * 
 * Provides common HTTP communication, token estimation fallbacks, error handling,
 * and sensitive credential masking.
 */
abstract class BaseProvider implements AIProviderInterface
{
    protected string $apiKey = '';
    protected string $apiEndpoint = '';
    protected string $defaultModel = '';
    protected HttpClient $httpClient;
    protected Logger $logger;

    public function __construct(
        string $apiKey = '',
        string $apiEndpoint = '',
        string $defaultModel = '',
        ?HttpClient $httpClient = null,
        ?Logger $logger = null
    ) {
        $this->apiKey = trim($apiKey);
        $this->apiEndpoint = rtrim(trim($apiEndpoint), '/');
        $this->defaultModel = trim($defaultModel);
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->logger = $logger ?? new Logger();
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    public function setHttpClient(HttpClient $client): self
    {
        $this->httpClient = $client;
        return $this;
    }

    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = trim($apiKey);
        return $this;
    }

    public function setApiEndpoint(string $endpoint): self
    {
        $this->apiEndpoint = rtrim(trim($endpoint), '/');
        return $this;
    }

    /**
     * Fallback token estimator: ~4 characters per token for English/code, ~2 for CJK/special.
     */
    protected function estimateTokenCount(string $text): int
    {
        $charCount = mb_strlen($text);
        return (int) max(1, ceil($charCount / 4.0));
    }

    /**
     * Mask sensitive API keys for safe logging and UI display.
     */
    protected function maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) {
            return '***';
        }
        return substr($key, 0, 4) . '...' . substr($key, -4);
    }

    /**
     * Standardized response formatter for all AI provider calls.
     */
    protected function formatResult(
        bool $success,
        string $text,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $executionTimeMs,
        ?string $error = null,
        ?array $raw = null
    ): array {
        $totalTokens = $promptTokens + $completionTokens;
        $costCents = $this->estimateCost($promptTokens, $completionTokens, $model);

        return [
            'success' => $success,
            'text' => $text,
            'model' => $model,
            'provider' => $this->getProviderCode(),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost_cents' => round($costCents, 4),
            'execution_time_ms' => $executionTimeMs,
            'error' => $error,
            'raw_response' => $raw,
        ];
    }
}
