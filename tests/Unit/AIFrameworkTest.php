<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\AI\AIManager;
use AnaBot\AI\Contracts\AIProviderInterface;
use AnaBot\AI\PromptManager;
use AnaBot\AI\Providers\ClaudeProvider;
use AnaBot\AI\Providers\CompatibleProvider;
use AnaBot\AI\Providers\GeminiProvider;
use AnaBot\AI\Providers\OpenAIProvider;
use AnaBot\Core\Database;
use AnaBot\Core\Encryption;
use AnaBot\Core\MigrationRunner;
use AnaBot\Models\AIProvider;
use AnaBot\Services\HttpClient;

/**
 * Phase 7: Multi-Provider AI Framework Automated Test Suite
 * 
 * Verifies:
 * - Provider driver contracts (Gemini, OpenAI, Claude, Compatible)
 * - Request payloads & response parsing (Text, Tokens, Cost)
 * - AES-256-GCM API Key Encryption in SQLite
 * - Multi-Provider Failover Orchestration (Fallback on HTTP 429 / Error)
 * - Token Usage Audit Logging (ai_usage_logs)
 * - Monthly Token Budgeting
 * - PromptManager Anti-Hallucination Guardrails
 */
class AIFrameworkTest
{
    private Database $db;
    private Encryption $encryption;
    private AIManager $aiManager;

    public function run(): void
    {
        echo "Testing Multi-Provider AI Framework (Isolated Test DB)..." . PHP_EOL;

        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $this->db = new Database($testDbPath);
        Database::setInstance($this->db);

        $runner = new MigrationRunner($this->db);
        $runner->run();

        $this->encryption = new Encryption();
        $this->aiManager = new AIManager($this->db, $this->encryption);
        $this->aiManager->syncProvidersCatalog();

        $this->testProviderInterfaceContracts();
        $this->testGeminiProviderRequestAndResponse();
        $this->testOpenAIProviderRequestAndResponse();
        $this->testClaudeProviderRequestAndResponse();
        $this->testCompatibleProviderFlexibility();
        $this->testApiKeyEncryptionAndDecryption();
        $this->testAutomaticFailoverOrchestration();
        $this->testMonthlyTokenBudgetEnforcement();
        $this->testTokenUsageAuditLogging();
        $this->testPromptManagerAntiHallucinationGuardrail();

        echo "  [PASS] All 10 Multi-Provider AI Framework test cases passed successfully." . PHP_EOL;

        // Cleanup
        unset($this->db);
        Database::setInstance(null);
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }
    }

    private function testProviderInterfaceContracts(): void
    {
        $gemini = new GeminiProvider('fake_key');
        $openai = new OpenAIProvider('fake_key');
        $claude = new ClaudeProvider('fake_key');
        $compat = new CompatibleProvider('fake_key', 'https://api.groq.com/openai/v1');

        assert($gemini instanceof AIProviderInterface, "Gemini must implement AIProviderInterface.");
        assert($gemini->getProviderCode() === 'gemini', "Gemini code must be 'gemini'.");
        assert($gemini->isConfigured() === true, "Gemini with key must be configured.");
        assert($gemini->getDefaultModel() === 'gemini-3.8-flash', "Gemini default model must be gemini-3.8-flash.");
        assert(in_array('gemini-3.8-flash', $gemini->getSupportedModels(), true), "Must support gemini-3.8-flash.");
        assert(in_array('gemini-1.5-pro', $gemini->getSupportedModels(), true), "Must support gemini-1.5-pro.");

        assert($openai instanceof AIProviderInterface, "OpenAI must implement AIProviderInterface.");
        assert($openai->getProviderCode() === 'openai', "OpenAI code must be 'openai'.");
        assert(in_array('gpt-4o', $openai->getSupportedModels(), true), "Must support gpt-4o.");

        assert($claude instanceof AIProviderInterface, "Claude must implement AIProviderInterface.");
        assert($claude->getProviderCode() === 'claude', "Claude code must be 'claude'.");
        assert(in_array('claude-3-5-sonnet-20241022', $claude->getSupportedModels(), true), "Must support claude-3-5-sonnet.");

        assert($compat instanceof AIProviderInterface, "Compatible must implement AIProviderInterface.");
        assert($compat->getProviderCode() === 'compatible', "Compatible code must be 'compatible'.");

        echo "  ✓ Provider interface contracts, codes, and model lists verified." . PHP_EOL;
    }

    private function testGeminiProviderRequestAndResponse(): void
    {
        $mockHttp = new class extends HttpClient {
            public string $lastUrl = '';
            public array $lastPayload = [];

            public function post(string $url, mixed $data = null, array $headers = [], array $options = []): array
            {
                $this->lastUrl = $url;
                $this->lastPayload = $data;

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => '',
                    'json' => [
                        'candidates' => [
                            [
                                'content' => [
                                    'parts' => [
                                        ['text' => 'Berdasarkan data analitik, omset meningkat 15% pada bulan ini.'],
                                    ],
                                ],
                                'finishReason' => 'STOP',
                            ],
                        ],
                        'usageMetadata' => [
                            'promptTokenCount' => 120,
                            'candidatesTokenCount' => 25,
                            'totalTokenCount' => 145,
                        ],
                    ],
                    'duration_ms' => 150.0,
                ];
            }
        };

        $gemini = new GeminiProvider('gemini_test_key_123', '', 'gemini-1.5-pro', $mockHttp);
        $res = $gemini->generateChat([
            ['role' => 'system', 'content' => 'Anda adalah analis data.'],
            ['role' => 'user', 'content' => 'Bagaimana performa penjualan?'],
        ]);

        assert($res['success'] === true, "Gemini generation must succeed.");
        assert(str_contains($res['text'], 'omset meningkat 15%'), "Text must match response part.");
        assert($res['prompt_tokens'] === 120, "Prompt tokens must be 120.");
        assert($res['completion_tokens'] === 25, "Completion tokens must be 25.");
        assert($res['total_tokens'] === 145, "Total tokens must be 145.");
        assert(str_contains($mockHttp->lastUrl, 'models/gemini-1.5-pro:generateContent'), "URL must target gemini-1.5-pro.");
        assert(isset($mockHttp->lastPayload['systemInstruction']), "System instruction must be passed separately.");

        echo "  ✓ Google Gemini request formatting, system instruction, and token parsing verified." . PHP_EOL;
    }

    private function testOpenAIProviderRequestAndResponse(): void
    {
        $mockHttp = new class extends HttpClient {
            public array $lastHeaders = [];
            public array $lastPayload = [];

            public function post(string $url, mixed $data = null, array $headers = [], array $options = []): array
            {
                $this->lastHeaders = $headers;
                $this->lastPayload = $data;

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => '',
                    'json' => [
                        'choices' => [
                            [
                                'message' => [
                                    'role' => 'assistant',
                                    'content' => 'Rata-rata transaksi bernilai Rp 712.148 dengan korelasi moderat terhadap unit.',
                                ],
                            ],
                        ],
                        'usage' => [
                            'prompt_tokens' => 200,
                            'completion_tokens' => 30,
                            'total_tokens' => 230,
                        ],
                    ],
                    'duration_ms' => 120.0,
                ];
            }
        };

        $openai = new OpenAIProvider('sk-proj-testkey123', '', 'gpt-4o', $mockHttp);
        $res = $openai->generateText('Berikan ringkasan statistik.');

        assert($res['success'] === true, "OpenAI generation must succeed.");
        assert(str_contains($res['text'], 'Rp 712.148'), "Output text must match mock response.");
        assert($res['prompt_tokens'] === 200, "Prompt tokens must be 200.");
        assert($res['completion_tokens'] === 30, "Completion tokens must be 30.");
        assert($mockHttp->lastHeaders['Authorization'] === 'Bearer sk-proj-testkey123', "Bearer auth header verified.");
        assert($mockHttp->lastPayload['model'] === 'gpt-4o', "Model in payload must be gpt-4o.");

        echo "  ✓ OpenAI Chat Completions payload, bearer auth, and token parsing verified." . PHP_EOL;
    }

    private function testClaudeProviderRequestAndResponse(): void
    {
        $mockHttp = new class extends HttpClient {
            public array $lastHeaders = [];
            public array $lastPayload = [];

            public function post(string $url, mixed $data = null, array $headers = [], array $options = []): array
            {
                $this->lastHeaders = $headers;
                $this->lastPayload = $data;

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => '',
                    'json' => [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Kategori Fashion Pria menjadi kontributor terbesar (22.1% total omset).',
                            ],
                        ],
                        'usage' => [
                            'input_tokens' => 180,
                            'output_tokens' => 28,
                        ],
                    ],
                    'duration_ms' => 140.0,
                ];
            }
        };

        $claude = new ClaudeProvider('sk-ant-testkey456', '', 'claude-3-5-sonnet-20241022', $mockHttp);
        $res = $claude->generateChat([
            ['role' => 'system', 'content' => 'Gunakan Bahasa Indonesia formal.'],
            ['role' => 'user', 'content' => 'Analisis kategori terbaik.'],
        ]);

        assert($res['success'] === true, "Claude generation must succeed.");
        assert(str_contains($res['text'], 'Fashion Pria'), "Text must match response block.");
        assert($res['prompt_tokens'] === 180, "Prompt tokens must be 180.");
        assert($res['completion_tokens'] === 28, "Completion tokens must be 28.");
        assert($mockHttp->lastHeaders['x-api-key'] === 'sk-ant-testkey456', "x-api-key header must match.");
        assert($mockHttp->lastHeaders['anthropic-version'] === '2023-06-01', "anthropic-version header verified.");
        assert(isset($mockHttp->lastPayload['system']), "System prompt must be placed top-level.");

        echo "  ✓ Anthropic Claude Messages API payload, headers, and token parsing verified." . PHP_EOL;
    }

    private function testCompatibleProviderFlexibility(): void
    {
        $mockHttp = new class extends HttpClient {
            public string $lastUrl = '';
            public function post(string $url, mixed $data = null, array $headers = [], array $options = []): array
            {
                $this->lastUrl = $url;
                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => '',
                    'json' => [
                        'choices' => [['message' => ['content' => 'Compatible response']]],
                        'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
                    ],
                    'duration_ms' => 50.0,
                ];
            }
        };

        $compat = new CompatibleProvider('gsk_test', 'https://api.groq.com/openai/v1', 'llama-3.3-70b-versatile', $mockHttp);
        $res = $compat->generateText('Test');

        assert($res['success'] === true, "Compatible provider call must succeed.");
        assert($res['text'] === 'Compatible response', "Response text must match.");
        assert($mockHttp->lastUrl === 'https://api.groq.com/openai/v1/chat/completions', "Endpoint URL appended /chat/completions correctly.");

        echo "  ✓ OpenAI-Compatible custom endpoint and model flexibility verified." . PHP_EOL;
    }

    private function testApiKeyEncryptionAndDecryption(): void
    {
        $provider = AIProvider::findByCode('gemini');
        assert($provider !== null, "Gemini provider record must exist in DB.");
        $providerId = (int) $provider['id'];

        $secretKey = 'AIzaSySecretApiKey1234567890';
        $updated = AIProvider::updateSettings($providerId, [
            'api_key' => $secretKey,
            'is_active' => 1,
            'monthly_token_budget' => 50000,
        ], $this->encryption);

        assert($updated === true, "updateSettings must succeed.");

        // Check raw DB row to ensure it is encrypted (not plaintext)
        $raw = $this->db->queryOne("SELECT api_key_encrypted FROM ai_providers WHERE id = :id", [':id' => $providerId]);
        assert(!empty($raw['api_key_encrypted']), "Encrypted key must be stored.");
        assert(!str_contains($raw['api_key_encrypted'], $secretKey), "Raw database field MUST NOT contain plaintext key!");

        // Decrypt key
        $decrypted = AIProvider::getDecryptedApiKey($providerId, $this->encryption);
        assert($decrypted === $secretKey, "Decrypted key must exactly match original secret key.");

        echo "  ✓ AES-256-GCM API key encryption, storage, and decryption verified." . PHP_EOL;
    }

    private function testAutomaticFailoverOrchestration(): void
    {
        // 1. Mock Primary Provider (fails with HTTP 429 Too Many Requests)
        $failingPrimary = new class('key', 'https://api.primary.com', 'model-1') extends GeminiProvider {
            public function generateText(string $prompt, array $options = []): array
            {
                return [
                    'success' => false,
                    'text' => '',
                    'model' => 'model-1',
                    'provider' => 'gemini',
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'estimated_cost_cents' => 0,
                    'execution_time_ms' => 45,
                    'error' => 'Rate limit exceeded (HTTP 429). Resource exhausted.',
                    'raw_response' => null,
                ];
            }
        };

        // 2. Mock Fallback Provider (succeeds with HTTP 200)
        $workingFallback = new class('key', 'https://api.fallback.com', 'model-2') extends OpenAIProvider {
            public function generateText(string $prompt, array $options = []): array
            {
                return [
                    'success' => true,
                    'text' => 'Jawaban sukses dari provider cadangan (OpenAI Fallback).',
                    'model' => 'gpt-4o',
                    'provider' => 'openai',
                    'prompt_tokens' => 80,
                    'completion_tokens' => 20,
                    'total_tokens' => 100,
                    'estimated_cost_cents' => 0.04,
                    'execution_time_ms' => 110,
                    'error' => null,
                    'raw_response' => [],
                ];
            }
        };

        // Register custom test providers into AIManager
        $ai = new AIManager($this->db, $this->encryption);
        $ai->registerProvider('gemini', $failingPrimary);
        $ai->registerProvider('openai', $workingFallback);

        // Mark Gemini as primary and OpenAI as fallback in DB
        $p1 = AIProvider::findByCode('gemini');
        $p2 = AIProvider::findByCode('openai');
        $this->db->execute("UPDATE ai_providers SET is_primary = 1, is_fallback = 0 WHERE id = :id", [':id' => $p1['id']]);
        $this->db->execute("UPDATE ai_providers SET is_primary = 0, is_fallback = 1 WHERE id = :id", [':id' => $p2['id']]);

        // Execute generation with fallback
        $result = $ai->generateWithFallback('Jelaskan tren penjualan.');

        assert($result['success'] === true, "Generation must succeed via fallback.");
        assert($result['fallback_used'] === true, "fallback_used flag must be true.");
        assert(str_contains($result['text'], 'OpenAI Fallback'), "Response must come from fallback provider.");
        assert(str_contains($result['primary_error'], 'Rate limit'), "Primary error must be recorded.");

        echo "  ✓ Automatic provider failover orchestration (switching to fallback on error) verified." . PHP_EOL;
    }

    private function testMonthlyTokenBudgetEnforcement(): void
    {
        $provider = AIProvider::findByCode('gemini');
        $providerId = (int) $provider['id'];

        // Set monthly token budget to 10,000 tokens
        $this->db->execute(
            "UPDATE ai_providers SET monthly_token_budget = 10000 WHERE id = :id",
            [':id' => $providerId]
        );

        // Check budget with 0 usage
        $ai = new AIManager($this->db, $this->encryption);
        assert($ai->checkBudget($providerId) === true, "Budget check must pass when usage is 0.");

        // Simulate 12,000 tokens consumed this month
        $ai->logUsage($providerId, null, null, 8000, 4000, 0.5, 200, 'success');

        // Check budget again
        assert($ai->checkBudget($providerId) === false, "Budget check must fail when usage (12,000) exceeds budget (10,000).");

        echo "  ✓ Monthly token budgeting and quota threshold enforcement verified." . PHP_EOL;
    }

    private function testTokenUsageAuditLogging(): void
    {
        $provider = AIProvider::findByCode('openai');
        $providerId = (int) $provider['id'];

        $ai = new AIManager($this->db, $this->encryption);
        $logId = $ai->logUsage($providerId, null, 12, 150, 50, 0.25, 95, 'success');

        assert($logId > 0, "logUsage must return inserted log ID.");

        $row = $this->db->queryOne("SELECT * FROM ai_usage_logs WHERE id = :id", [':id' => $logId]);
        assert($row !== null, "Audit log record must exist.");
        assert((int) $row['prompt_tokens'] === 150, "Prompt tokens must match.");
        assert((int) $row['completion_tokens'] === 50, "Completion tokens must match.");
        assert((int) $row['total_tokens'] === 200, "Total tokens must be 200.");
        assert($row['status'] === 'success', "Status must be success.");

        echo "  ✓ AI token usage, latency, and cost audit logging (ai_usage_logs) verified." . PHP_EOL;
    }

    private function testPromptManagerAntiHallucinationGuardrail(): void
    {
        $sampleAnalytics = [
            'descriptive_stats' => [
                'total_omset' => [
                    'count' => 75,
                    'sum' => 53411120.0,
                    'mean' => 712148.27,
                    'median' => 599810.0,
                    'mode' => 65350.0,
                    'min' => 65350.0,
                    'max' => 2095710.0,
                    'std_dev' => 499816.11,
                ],
            ],
            'time_series' => [
                'metric' => 'total_omset',
                'growth_summary' => [
                    'total_periods' => 30,
                    'first_value' => 500000.0,
                    'latest_value' => 750000.0,
                    'overall_growth_pct' => 50.0,
                    'average_growth_pct' => 1.8,
                    'compound_growth_rate' => 1.4,
                ],
                'trend' => [
                    'direction' => 'uptrend',
                    'description' => 'Tren naik stabil',
                ],
            ],
            'correlation' => [
                'top_pairs' => [
                    [
                        'var_a' => 'total_omset',
                        'var_b' => 'unit_terjual',
                        'r' => 0.6637,
                        'strength' => 'Moderat Positif',
                    ],
                ],
            ],
            'breakdowns' => [
                [
                    'dimension' => 'kategori',
                    'metric' => 'total_omset',
                    'items' => [
                        [
                            'rank' => 1,
                            'category' => 'Fashion Pria',
                            'total' => 11804220.0,
                            'percentage' => 22.1,
                            'cumulative_percentage' => 22.1,
                            'is_pareto_80' => true,
                        ],
                    ],
                ],
            ],
        ];

        $prompt = PromptManager::buildAnalyticsPrompt($sampleAnalytics, 'Platform mana yang performanya paling bagus?', ['dataset_name' => 'Penjualan 2026']);

        assert(count($prompt) === 2, "Prompt array must have exactly system and user message.");
        assert($prompt[0]['role'] === 'system', "First message must be system role.");
        assert(str_contains($prompt[0]['content'], 'LARANGAN HALUSINASI'), "System prompt must enforce Anti-Hallucination Guardrail.");
        assert(str_contains($prompt[1]['content'], '712,148.27'), "User prompt must inject verified mean number.");
        assert(str_contains($prompt[1]['content'], 'r = 0.664'), "User prompt must inject verified Pearson r.");
        assert(str_contains($prompt[1]['content'], 'Fashion Pria'), "User prompt must inject Pareto category breakdown.");
        assert(str_contains($prompt[1]['content'], 'Platform mana yang performanya paling bagus?'), "User query must be included.");

        echo "  ✓ PromptManager Anti-Hallucination Guardrail & deterministic facts injection verified." . PHP_EOL;
    }
}
