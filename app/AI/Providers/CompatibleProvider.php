<?php
declare(strict_types=1);

namespace AnaBot\AI\Providers;

use AnaBot\Core\Logger;
use AnaBot\Services\HttpClient;

/**
 * OpenAI-Compatible Provider Driver
 * 
 * Supports Groq, DeepSeek, Mistral, Ollama, OpenRouter, and any OpenAI-compatible API endpoint.
 */
class CompatibleProvider extends BaseProvider
{
    public const CODE = 'compatible';
    public const NAME = 'OpenAI Compatible';

    public function __construct(
        string $apiKey = '',
        string $apiEndpoint = '',
        string $defaultModel = '',
        ?HttpClient $httpClient = null,
        ?Logger $logger = null
    ) {
        parent::__construct(
            $apiKey,
            $apiEndpoint,
            !empty($defaultModel) ? $defaultModel : 'custom-model',
            $httpClient,
            $logger
        );
    }

    public function getProviderCode(): string
    {
        return self::CODE;
    }

    public function getProviderName(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        // For local endpoints like Ollama, an API key is not strictly required if endpoint is set
        return !empty($this->apiEndpoint);
    }

    public function getSupportedModels(): array
    {
        return [
            $this->defaultModel,
            'deepseek-chat',
            'llama-3.3-70b-versatile',
            'mixtral-8x7b-32768',
            'qwen-2.5-72b',
        ];
    }

    public function generateText(string $prompt, array $options = []): array
    {
        return $this->generateChat([['role' => 'user', 'content' => $prompt]], $options);
    }

    public function generateChat(array $messages, array $options = []): array
    {
        if (!$this->isConfigured()) {
            return $this->formatResult(false, '', $this->defaultModel, 0, 0, 0, 'OpenAI Compatible Endpoint belum dikonfigurasi.');
        }

        $startTime = microtime(true);
        $model = $options['model'] ?? $this->defaultModel;
        $temperature = (float) ($options['temperature'] ?? 0.3);
        $maxTokens = (int) ($options['max_tokens'] ?? 2048);

        $sanitizedMessages = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            if ($role === 'model') {
                $role = 'assistant';
            }
            $sanitizedMessages[] = [
                'role' => $role,
                'content' => (string) ($m['content'] ?? ''),
            ];
        }

        $payload = [
            'model' => $model,
            'messages' => $sanitizedMessages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        // Format endpoint URL (handle both base url and full completions url)
        $url = $this->apiEndpoint;
        if (!str_ends_with($url, '/chat/completions')) {
            $url .= '/chat/completions';
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        try {
            $response = $this->httpClient->post($url, $payload, $headers);
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response['status'] !== 200) {
                $errorMsg = $response['json']['error']['message'] ?? "HTTP {$response['status']}: {$response['body']}";
                $this->logger->error("Compatible Provider API error ({$response['status']}): {$errorMsg}");
                return $this->formatResult(false, '', $model, 0, 0, $durationMs, $errorMsg, $response['json'] ?? null);
            }

            $json = $response['json'] ?? [];
            $choice = $json['choices'][0] ?? [];
            $text = $choice['message']['content'] ?? '';

            $usage = $json['usage'] ?? [];
            $promptTokens = (int) ($usage['prompt_tokens'] ?? $this->estimateTokenCount(json_encode($sanitizedMessages)));
            $completionTokens = (int) ($usage['completion_tokens'] ?? $this->estimateTokenCount($text));

            return $this->formatResult(
                true,
                $text,
                $model,
                $promptTokens,
                $completionTokens,
                $durationMs,
                null,
                $json
            );
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logger->error("Compatible Provider API exception: {$e->getMessage()}");
            return $this->formatResult(false, '', $model, 0, 0, $durationMs, $e->getMessage());
        }
    }

    public function estimateCost(int $promptTokens, int $completionTokens, string $model): float
    {
        // Default estimate: ~$0.20 per 1M tokens (0.02 cents / 1k)
        $rate = 0.02;
        return (($promptTokens + $completionTokens) / 1000.0) * $rate;
    }
}
