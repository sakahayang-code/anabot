<?php
declare(strict_types=1);

namespace AnaBot\AI\Providers;

use AnaBot\Core\Logger;
use AnaBot\Services\HttpClient;

/**
 * Anthropic Claude Provider Driver
 * 
 * Implements Anthropic Messages API v1 (claude-3-5-sonnet, claude-3-5-haiku, claude-3-opus).
 */
class ClaudeProvider extends BaseProvider
{
    public const CODE = 'claude';
    public const NAME = 'Anthropic Claude';
    public const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1';
    public const DEFAULT_MODEL = 'claude-3-7-sonnet-20250219';
    public const ANTHROPIC_VERSION = '2023-06-01';

    public const SUPPORTED_MODELS = [
        'claude-3-7-sonnet-20250219',
        'claude-3-5-sonnet-20241022',
        'claude-3-5-haiku-20241022',
        'claude-3-opus-20240229',
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
            return $this->formatResult(false, '', $this->defaultModel, 0, 0, 0, 'Anthropic Claude API Key belum dikonfigurasi.');
        }

        $startTime = microtime(true);
        $model = $options['model'] ?? $this->defaultModel;
        $temperature = (float) ($options['temperature'] ?? 0.3);
        $maxTokens = (int) ($options['max_tokens'] ?? 2048);

        // Separate system prompt from messages (Claude API requires 'system' at top-level)
        $systemText = '';
        $claudeMessages = [];

        foreach ($messages as $msg) {
            $role = strtolower((string) ($msg['role'] ?? 'user'));
            $content = (string) ($msg['content'] ?? '');

            if ($role === 'system') {
                $systemText .= ($systemText ? "\n\n" : '') . $content;
            } elseif ($role === 'assistant' || $role === 'model') {
                $claudeMessages[] = [
                    'role' => 'assistant',
                    'content' => $content,
                ];
            } else {
                $claudeMessages[] = [
                    'role' => 'user',
                    'content' => $content,
                ];
            }
        }

        if (empty($claudeMessages)) {
            $claudeMessages[] = ['role' => 'user', 'content' => 'Halo'];
        }

        $payload = [
            'model' => $model,
            'messages' => $claudeMessages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];

        if (!empty($systemText)) {
            $payload['system'] = $systemText;
        }

        $url = "{$this->apiEndpoint}/messages";
        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'Content-Type' => 'application/json',
        ];

        try {
            $response = $this->httpClient->post($url, $payload, $headers);
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response['status'] !== 200) {
                $errorMsg = $response['json']['error']['message'] ?? "HTTP {$response['status']}: {$response['body']}";
                $this->logger->error("Claude API error ({$response['status']}): {$errorMsg}");
                return $this->formatResult(false, '', $model, 0, 0, $durationMs, $errorMsg, $response['json'] ?? null);
            }

            $json = $response['json'] ?? [];
            $text = '';
            if (isset($json['content']) && is_array($json['content'])) {
                foreach ($json['content'] as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $text .= $block['text'] ?? '';
                    }
                }
            }

            // Extract usage
            $usage = $json['usage'] ?? [];
            $promptTokens = (int) ($usage['input_tokens'] ?? $this->estimateTokenCount(json_encode($claudeMessages)));
            $completionTokens = (int) ($usage['output_tokens'] ?? $this->estimateTokenCount($text));

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
            $this->logger->error("Claude API exception: {$e->getMessage()}");
            return $this->formatResult(false, '', $model, 0, 0, $durationMs, $e->getMessage());
        }
    }

    public function estimateCost(int $promptTokens, int $completionTokens, string $model): float
    {
        if (str_contains($model, 'haiku')) {
            $inputRate = 0.08;   // $0.80 / 1M tokens
            $outputRate = 0.40;  // $4.00 / 1M tokens
        } elseif (str_contains($model, 'opus')) {
            $inputRate = 1.50;   // $15.00 / 1M tokens
            $outputRate = 7.50;  // $75.00 / 1M tokens
        } else {
            // Sonnet
            $inputRate = 0.30;   // $3.00 / 1M tokens
            $outputRate = 1.50;  // $15.00 / 1M tokens
        }

        return (($promptTokens / 1000.0) * $inputRate) + (($completionTokens / 1000.0) * $outputRate);
    }
}
