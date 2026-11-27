<?php
declare(strict_types=1);

namespace AnaBot\AI\Providers;

use AnaBot\Core\Logger;
use AnaBot\Services\HttpClient;

/**
 * Google Gemini AI Provider Driver
 * 
 * Implements Gemini REST API v1beta (gemini-1.5-pro, gemini-1.5-flash, gemini-2.0).
 */
class GeminiProvider extends BaseProvider
{
    public const CODE = 'gemini';
    public const NAME = 'Google Gemini';
    public const DEFAULT_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta';
    public const DEFAULT_MODEL = 'gemini-3.8-flash';

    public const SUPPORTED_MODELS = [
        'gemini-3.8-flash',
        'gemini-3.8-pro',
        'gemini-3.0-flash',
        'gemini-3.0-pro',
        'gemini-2.5-flash',
        'gemini-2.5-pro',
        'gemini-2.0-flash',
        'gemini-2.0-flash-lite',
        'gemini-2.0-pro-exp-02-05',
        'gemini-1.5-pro',
        'gemini-1.5-flash',
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
            return $this->formatResult(false, '', $this->defaultModel, 0, 0, 0, 'Google Gemini API Key belum dikonfigurasi.');
        }

        $startTime = microtime(true);
        $model = $options['model'] ?? $this->defaultModel;
        $temperature = (float) ($options['temperature'] ?? 0.3);
        $maxOutputTokens = (int) ($options['max_tokens'] ?? 2048);

        // Separate system instructions from conversational contents
        $systemText = '';
        $contents = [];

        foreach ($messages as $msg) {
            $role = strtolower((string) ($msg['role'] ?? 'user'));
            $content = (string) ($msg['content'] ?? '');

            if ($role === 'system') {
                $systemText .= ($systemText ? "\n\n" : '') . $content;
            } elseif ($role === 'assistant' || $role === 'model') {
                $contents[] = [
                    'role' => 'model',
                    'parts' => [['text' => $content]],
                ];
            } else {
                $contents[] = [
                    'role' => 'user',
                    'parts' => [['text' => $content]],
                ];
            }
        }

        if (empty($contents)) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => 'Halo']]];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $maxOutputTokens,
            ],
        ];

        if (!empty($systemText)) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemText]],
            ];
        }

        $cleanModel = (string) $model;
        if (str_starts_with($cleanModel, 'models/')) {
            $cleanModel = substr($cleanModel, 7);
        }

        $url = "{$this->apiEndpoint}/models/" . urlencode($cleanModel) . ":generateContent?key=" . urlencode($this->apiKey);

        try {
            $response = $this->httpClient->post($url, $payload, [
                'Content-Type' => 'application/json',
            ]);

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response['status'] !== 200) {
                $errorMsg = $response['json']['error']['message'] ?? "HTTP {$response['status']}: {$response['body']}";
                $this->logger->error("Gemini API error ({$response['status']}): {$errorMsg}");
                return $this->formatResult(false, '', $model, 0, 0, $durationMs, $errorMsg, $response['json'] ?? null);
            }

            $json = $response['json'] ?? [];
            $candidate = $json['candidates'][0] ?? [];
            $generatedText = '';

            if (isset($candidate['content']['parts'])) {
                foreach ($candidate['content']['parts'] as $part) {
                    $generatedText .= $part['text'] ?? '';
                }
            }

            // Extract usage metadata
            $usage = $json['usageMetadata'] ?? [];
            $promptTokens = (int) ($usage['promptTokenCount'] ?? $this->estimateTokenCount(json_encode($contents)));
            $completionTokens = (int) ($usage['candidatesTokenCount'] ?? $this->estimateTokenCount($generatedText));

            return $this->formatResult(
                true,
                $generatedText,
                $model,
                $promptTokens,
                $completionTokens,
                $durationMs,
                null,
                $json
            );
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logger->error("Gemini API exception: {$e->getMessage()}");
            return $this->formatResult(false, '', $model, 0, 0, $durationMs, $e->getMessage());
        }
    }

    public function estimateCost(int $promptTokens, int $completionTokens, string $model): float
    {
        // Costs in USD cents per 1,000 tokens
        if (str_contains($model, 'flash')) {
            $inputRate = 0.0075;  // $0.075 per 1M tokens = 0.0075 cents per 1k
            $outputRate = 0.03;   // $0.30 per 1M tokens = 0.03 cents per 1k
        } else {
            // Pro models
            $inputRate = 0.035;   // $0.35 per 1M tokens = 0.035 cents per 1k
            $outputRate = 0.105;  // $1.05 per 1M tokens = 0.105 cents per 1k
        }

        return (($promptTokens / 1000.0) * $inputRate) + (($completionTokens / 1000.0) * $outputRate);
    }
}
