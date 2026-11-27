<?php
declare(strict_types=1);

namespace AnaBot\AI\Contracts;

/**
 * Standard contract for all AnaBot AI Providers.
 * 
 * Supports multi-provider architecture: Google Gemini, OpenAI, Anthropic Claude, and OpenAI-Compatible.
 */
interface AIProviderInterface
{
    /**
     * Get unique provider code (e.g. 'gemini', 'openai', 'claude', 'compatible').
     */
    public function getProviderCode(): string;

    /**
     * Get human-readable provider name.
     */
    public function getProviderName(): string;

    /**
     * Check if provider has active API credentials configured.
     */
    public function isConfigured(): bool;

    /**
     * Get default model identifier.
     */
    public function getDefaultModel(): string;

    /**
     * Get list of supported model identifiers.
     */
    public function getSupportedModels(): array;

    /**
     * Generate text completion from a prompt.
     *
     * @param string $prompt Prompt text
     * @param array $options Additional options (model, temperature, max_tokens, system_instruction)
     * @return array Standardized result dictionary
     */
    public function generateText(string $prompt, array $options = []): array;

    /**
     * Generate completion for a multi-turn chat conversation.
     *
     * @param array $messages Array of messages [['role' => 'user'|'assistant'|'system', 'content' => string], ...]
     * @param array $options Additional generation options
     * @return array Standardized result dictionary
     */
    public function generateChat(array $messages, array $options = []): array;

    /**
     * Estimate cost in cents for a given token usage and model.
     *
     * @param int $promptTokens Number of input tokens
     * @param int $completionTokens Number of output tokens
     * @param string $model Model name
     * @return float Estimated cost in USD cents
     */
    public function estimateCost(int $promptTokens, int $completionTokens, string $model): float;
}
