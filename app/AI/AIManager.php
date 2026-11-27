<?php
declare(strict_types=1);

namespace AnaBot\AI;

use AnaBot\AI\Contracts\AIProviderInterface;
use AnaBot\AI\Providers\ClaudeProvider;
use AnaBot\AI\Providers\CompatibleProvider;
use AnaBot\AI\Providers\GeminiProvider;
use AnaBot\AI\Providers\OpenAIProvider;
use AnaBot\Core\Database;
use AnaBot\Core\Encryption;
use AnaBot\Core\Logger;
use AnaBot\Models\AIModel;
use AnaBot\Models\AIProvider;
use AnaBot\Services\HttpClient;
use RuntimeException;

/**
 * AnaBot AI Manager & Multi-Provider Fallback Orchestrator
 * 
 * Manages provider resolutions, API key decryption (AES-256-GCM), automatic failover,
 * token tracking, monthly budgeting, and audit logging into ai_usage_logs.
 */
class AIManager
{
    private Database $db;
    private Encryption $encryption;
    private HttpClient $httpClient;
    private Logger $logger;

    private static ?AIManager $instance = null;

    /** @var array<string, AIProviderInterface> */
    private array $customProviders = [];

    public static function getInstance(?Database $db = null, ?Encryption $encryption = null, ?HttpClient $httpClient = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($db, $encryption, $httpClient);
        }
        return self::$instance;
    }

    public static function setInstance(?AIManager $manager): void
    {
        self::$instance = $manager;
    }

    public function __construct(
        ?Database $db = null,
        ?Encryption $encryption = null,
        ?HttpClient $httpClient = null,
        ?Logger $logger = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->encryption = $encryption ?? new Encryption();
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Register a custom or mock provider instance (useful for testing and extensibility).
     */
    public function registerProvider(string $code, AIProviderInterface $provider): self
    {
        $this->customProviders[strtolower($code)] = $provider;
        return $this;
    }

    /**
     * Resolve provider instance by code.
     */
    public function getProvider(string $code): AIProviderInterface
    {
        $code = strtolower(trim($code));

        if (isset($this->customProviders[$code])) {
            return $this->customProviders[$code];
        }

        $row = AIProvider::findByCode($code);
        $apiKey = '';
        $endpoint = '';
        $defaultModel = '';

        if ($row) {
            $apiKey = AIProvider::getDecryptedApiKey((int) $row['id'], $this->encryption);
            $endpoint = (string) ($row['api_endpoint'] ?? '');
            $defModelRow = AIModel::getDefault((int) $row['id']);
            if ($defModelRow && !empty($defModelRow['model_name'])) {
                $defaultModel = (string) $defModelRow['model_name'];
            }
        }

        // Fallback to env or config if not saved in db
        $config = require dirname(__DIR__, 2) . '/config/ai.php';
        $providerConfig = $config['providers'][$code] ?? [];

        if (empty($endpoint) && !empty($providerConfig['api_endpoint'])) {
            $endpoint = $providerConfig['api_endpoint'];
        }

        if (empty($defaultModel) && !empty($providerConfig['default_model'])) {
            $defaultModel = $providerConfig['default_model'];
        }

        return match ($code) {
            'gemini' => new GeminiProvider($apiKey, $endpoint, $defaultModel, $this->httpClient, $this->logger),
            'openai' => new OpenAIProvider($apiKey, $endpoint, $defaultModel, $this->httpClient, $this->logger),
            'claude' => new ClaudeProvider($apiKey, $endpoint, $defaultModel, $this->httpClient, $this->logger),
            'compatible' => new CompatibleProvider($apiKey, $endpoint, $defaultModel, $this->httpClient, $this->logger),
            default => throw new RuntimeException("Unsupported AI provider code: {$code}"),
        };
    }

    /**
     * Resolve active primary provider.
     */
    public function getPrimaryProvider(): AIProviderInterface
    {
        // Check custom registered providers first
        if (!empty($this->customProviders)) {
            $firstKey = array_key_first($this->customProviders);
            return $this->customProviders[$firstKey];
        }

        $primaryRow = AIProvider::getPrimary();
        if ($primaryRow) {
            return $this->getProvider($primaryRow['provider_code']);
        }

        // Fallback to default in config
        $config = require dirname(__DIR__, 2) . '/config/ai.php';
        $defaultCode = $config['default_provider'] ?? 'gemini';
        return $this->getProvider($defaultCode);
    }

    /**
     * Resolve fallback provider if configured.
     */
    public function getFallbackProvider(): ?AIProviderInterface
    {
        $fallbackRow = AIProvider::getFallback();
        if ($fallbackRow) {
            return $this->getProvider($fallbackRow['provider_code']);
        }

        // If no explicit fallback set, check for another active configured provider
        $all = AIProvider::all();
        $primary = AIProvider::getPrimary();
        $primaryId = $primary['id'] ?? 0;

        foreach ($all as $p) {
            if ($p['id'] !== $primaryId && !empty($p['is_active']) && !empty($p['api_key_encrypted'])) {
                return $this->getProvider($p['provider_code']);
            }
        }

        return null;
    }

    /**
     * Execute completion with automatic failover to fallback provider on failure.
     *
     * @param string|array $promptOrMessages Single string prompt or messages array
     * @param array $options Additional options (sessionId, model, temperature, max_tokens)
     * @return array Standardized result with failover tracking
     */
    public function generateWithFallback(string|array $promptOrMessages, array $options = []): array
    {
        $startTime = microtime(true);
        $sessionId = isset($options['session_id']) ? (int) $options['session_id'] : null;

        // 1. Resolve Primary Provider
        $primary = $this->getPrimaryProvider();
        $primaryCode = $primary->getProviderCode();
        $primaryRow = AIProvider::findByCode($primaryCode);
        $primaryId = $primaryRow ? (int) $primaryRow['id'] : 1;

        // 2. Check Monthly Token Budget
        if ($primaryRow && !$this->checkBudget($primaryId)) {
            $this->logger->warning("Monthly token budget exceeded for primary provider {$primaryCode}.");
            // Switch to fallback if available
            $fallback = $this->getFallbackProvider();
            if ($fallback) {
                return $this->executeFallback($fallback, $promptOrMessages, $options, "Monthly token budget exceeded for {$primaryCode}");
            }

            return [
                'success' => false,
                'text' => '',
                'provider' => $primaryCode,
                'error' => "Batas kuota token bulanan untuk provider {$primaryCode} telah tercapai.",
                'fallback_used' => false,
            ];
        }

        // 3. Attempt Primary Generation
        $result = is_array($promptOrMessages) 
            ? $primary->generateChat($promptOrMessages, $options)
            : $primary->generateText($promptOrMessages, $options);

        // 4. If primary succeeds, log and return
        if (!empty($result['success'])) {
            $this->logUsage(
                $primaryId,
                null,
                $sessionId,
                (int) ($result['prompt_tokens'] ?? 0),
                (int) ($result['completion_tokens'] ?? 0),
                (float) ($result['estimated_cost_cents'] ?? 0.0),
                (int) ($result['execution_time_ms'] ?? 0),
                'success'
            );
            $result['fallback_used'] = false;
            return $result;
        }

        // 5. Primary failed -> Log error
        $primaryError = $result['error'] ?? 'Unknown primary provider error';
        $this->logger->warning("Primary provider {$primaryCode} failed: {$primaryError}. Attempting failover...");

        $this->logUsage(
            $primaryId,
            null,
            $sessionId,
            0,
            0,
            0.0,
            (int) ($result['execution_time_ms'] ?? 0),
            'failed',
            $primaryError
        );

        // 6. Attempt Fallback Provider
        $fallback = $this->getFallbackProvider();
        if ($fallback && $fallback->getProviderCode() !== $primaryCode) {
            return $this->executeFallback($fallback, $promptOrMessages, $options, $primaryError);
        }

        // No fallback available
        $result['fallback_used'] = false;
        return $result;
    }

    /**
     * Generate chat completion with multi-turn messages array and automatic fallback.
     */
    public function generateChat(array $messages, array $options = []): array
    {
        return $this->generateWithFallback($messages, $options);
    }

    /**
     * Generate text completion with automatic fallback.
     */
    public function generateText(string $prompt, array $options = []): array
    {
        return $this->generateWithFallback($prompt, $options);
    }

    /**
     * Check if monthly token budget has been exceeded.
     */
    public function checkBudget(int $providerId): bool
    {
        $provider = AIProvider::find($providerId);
        if (!$provider) {
            return true;
        }

        $budget = (int) ($provider['monthly_token_budget'] ?? 0);
        if ($budget <= 0) {
            return true; // 0 means unlimited
        }

        // Calculate total tokens consumed this month
        $startOfMonth = date('Y-m-01 00:00:00');
        $row = $this->db->queryOne(
            "SELECT SUM(total_tokens) as consumed 
             FROM ai_usage_logs 
             WHERE provider_id = :pid AND status = 'success' AND created_at >= :start",
            [':pid' => $providerId, ':start' => $startOfMonth]
        );

        $consumed = (int) ($row['consumed'] ?? 0);
        return $consumed < $budget;
    }

    /**
     * Log token usage into ai_usage_logs.
     */
    public function logUsage(
        int $providerId,
        ?int $modelId,
        ?int $sessionId,
        int $promptTokens,
        int $completionTokens,
        float $costCents,
        int $durationMs,
        string $status = 'success',
        ?string $errorMessage = null
    ): int {
        $now = date('c');
        $totalTokens = $promptTokens + $completionTokens;
        $costCentsInt = (int) round($costCents);

        $this->db->execute(
            "INSERT INTO ai_usage_logs 
             (provider_id, model_id, session_id, prompt_tokens, completion_tokens, total_tokens, estimated_cost_cents, execution_time_ms, status, error_message, created_at)
             VALUES (:pid, :mid, :sid, :pt, :ct, :tt, :cost, :time, :st, :err, :now)",
            [
                ':pid' => $providerId,
                ':mid' => $modelId,
                ':sid' => $sessionId,
                ':pt' => $promptTokens,
                ':ct' => $completionTokens,
                ':tt' => $totalTokens,
                ':cost' => $costCentsInt,
                ':time' => $durationMs,
                ':st' => $status,
                ':err' => $errorMessage,
                ':now' => $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Synchronize AI providers and default models catalog into database.
     */
    public function syncProvidersCatalog(): void
    {
        $now = date('c');
        $config = require dirname(__DIR__, 2) . '/config/ai.php';
        $providers = $config['providers'] ?? [];
        $defaultCode = $config['default_provider'] ?? 'gemini';

        foreach ($providers as $code => $cfg) {
            $existing = AIProvider::findByCode($code);
            $isDefault = ($code === $defaultCode) ? 1 : 0;

            if (!$existing) {
                $this->db->execute(
                    "INSERT INTO ai_providers (provider_code, name, api_endpoint, is_active, is_primary, is_fallback, monthly_token_budget, created_at, updated_at)
                     VALUES (:code, :name, :ep, 1, :primary, 0, 0, :now, :now)",
                    [
                        ':code' => $code,
                        ':name' => $cfg['name'],
                        ':ep' => $cfg['api_endpoint'],
                        ':primary' => $isDefault,
                        ':now' => $now,
                    ]
                );
                $providerId = (int) $this->db->lastInsertId();
            } else {
                $providerId = (int) $existing['id'];
            }

            // Sync default models
            $models = match ($code) {
                'gemini' => GeminiProvider::SUPPORTED_MODELS,
                'openai' => OpenAIProvider::SUPPORTED_MODELS,
                'claude' => ClaudeProvider::SUPPORTED_MODELS,
                default => ['custom-model'],
            };

            foreach ($models as $mIndex => $modelName) {
                $isModelDefault = ($mIndex === 0) ? 1 : 0;
                $this->db->execute(
                    "INSERT INTO ai_models (provider_id, model_name, display_name, is_default, created_at)
                     VALUES (:pid, :m, :disp, :def, :now)
                     ON CONFLICT(provider_id, model_name) DO NOTHING",
                    [
                        ':pid' => $providerId,
                        ':m' => $modelName,
                        ':disp' => ucfirst($modelName),
                        ':def' => $isModelDefault,
                        ':now' => $now,
                    ]
                );
            }

            // Automatically upgrade legacy default model to newest flagship model if unchanged
            $currentDefault = AIModel::getDefault($providerId);
            $latestModel = $models[0] ?? '';
            if ($currentDefault && !empty($latestModel)) {
                $legacyGemini = ['gemini-1.5-pro', 'gemini-1.5-flash', 'gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-2.5-flash'];
                if ($code === 'gemini' && in_array($currentDefault['model_name'], $legacyGemini, true)) {
                    AIModel::setDefault($providerId, $latestModel);
                } elseif ($code === 'claude' && $currentDefault['model_name'] === 'claude-3-5-sonnet-20241022') {
                    AIModel::setDefault($providerId, $latestModel);
                }
            }
        }
    }

    private function executeFallback(
        AIProviderInterface $fallback,
        string|array $promptOrMessages,
        array $options,
        string $primaryReason
    ): array {
        $fbCode = $fallback->getProviderCode();
        $fbRow = AIProvider::findByCode($fbCode);
        $fbId = $fbRow ? (int) $fbRow['id'] : 2;
        $sessionId = isset($options['session_id']) ? (int) $options['session_id'] : null;

        $this->logger->info("Executing failover request with provider: {$fbCode}");

        $fbResult = is_array($promptOrMessages)
            ? $fallback->generateChat($promptOrMessages, $options)
            : $fallback->generateText($promptOrMessages, $options);

        if (!empty($fbResult['success'])) {
            $this->logUsage(
                $fbId,
                null,
                $sessionId,
                (int) ($fbResult['prompt_tokens'] ?? 0),
                (int) ($fbResult['completion_tokens'] ?? 0),
                (float) ($fbResult['estimated_cost_cents'] ?? 0.0),
                (int) ($fbResult['execution_time_ms'] ?? 0),
                'success'
            );
            $fbResult['fallback_used'] = true;
            $fbResult['primary_error'] = $primaryReason;
            return $fbResult;
        }

        // Fallback also failed
        $this->logUsage(
            $fbId,
            null,
            $sessionId,
            0,
            0,
            0.0,
            (int) ($fbResult['execution_time_ms'] ?? 0),
            'failed',
            $fbResult['error'] ?? 'Fallback failed'
        );

        $fbResult['fallback_used'] = true;
        $fbResult['primary_error'] = $primaryReason;
        return $fbResult;
    }
}
