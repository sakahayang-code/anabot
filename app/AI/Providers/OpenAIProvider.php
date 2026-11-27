<?php
declare(strict_types=1);

namespace AnaBot\AI\Providers;

use AnaBot\Core\Logger;
use AnaBot\Services\HttpClient;

/**
 * OpenAI Provider Driver
 * 
 * Implements OpenAI Chat Completions API (gpt-4o, gpt-4o-mini, gpt-4-turbo).
 */
class OpenAIProvider extends BaseProvider
{
    public const CODE = 'openai';
    public const NAME = 'OpenAI';
    public const DEFAULT_ENDPOINT = 'https://api.openai.com/v1';
    public const DEFAULT_MODEL = 'gpt-4o';

    public const SUPPORTED_MODELS = [
        'gpt-4o',
        'gpt-4o-mini',
        'gpt-4-turbo',
    ];

    public function __construct(
        string $apiKey = '',
        string $apiEndpoint = '',
        string $defaultModel = '',
        ?HttpClient $httpClient = null,
        ?Logger $logger = null
    ) {
        parent::__construct(
            $apiKey,
            !empty($apiEndpoint) ? $apiEndpoint : self::DEFAULT_ENDPOINT,
            !empty($defaultModel) ? $defaultModel : self::DEFAULT_MODEL,
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

    public function getSupportedModels(): array
    {
        return self::SUPPORTED_MODELS;
    }

    public function generateText(string $prompt, array $options = []): array
    {
        return $this->generateChat([['role' => 'user', 'content' => $prompt]], $options);
    }

    public function generateChat(array $messages, array $options = []): array
    {
        if (!$this->isConfigured()) {
            return $this->formatResult(false, '', $this->defaultModel, 0, 0, 0, 'OpenAI API Key belum dikonfigurasi.');
        }

        $startTime = microtime(true);
        $model = $options['model'] ?? $this->defaultModel;
        $temperature = (float) ($options['temperature'] ?? 0.3);
        $maxTokens = (int) ($options['max_tokens'] ?? 2048);

        // Sanitize messages
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

        $url = "{$this->apiEndpoint}/chat/completions";
        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ];

        try {
            $response = $this->httpClient->post($url, $payload, $headers);
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response['status'] !== 200) {
                $errorMsg = $response['json']['error']['message'] ?? "HTTP {$response['status']}: {$response['body']}";
                $this->logger->error("OpenAI API error ({$response['status']}): {$errorMsg}");
                return $this->formatResult(false, '', $model, 0, 0, $durationMs, $errorMsg, $response['json'] ?? null);
            }

            $json = $response['json'] ?? [];
            $choice = $json['choices'][0] ?? [];
            $text = $choice['message']['content'] ?? '';

            // Token extraction
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
            $this->logger->error("OpenAI API exception: {$e->getMessage()}");
            return $this->formatResult(false, '', $model, 0, 0, $durationMs, $e->getMessage());
        }
    }

    public function estimateCost(int $promptTokens, int $completionTokens, string $model): float
    {
        if (str_contains($model, 'mini')) {
            $inputRate = 0.015;  // $0.15 / 1M tokens
            $outputRate = 0.06;  // $0.60 / 1M tokens
        } else {
            $inputRate = 0.25;   // $2.50 / 1M tokens
            $outputRate = 1.00;  // $10.00 / 1M tokens
        }

        return (($promptTokens / 1000.0) * $inputRate) + (($completionTokens / 1000.0) * $outputRate);
    }
}
