<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\AI\AIManager;
use AnaBot\AI\PromptManager;
use AnaBot\Core\Database;
use AnaBot\Core\Logger;
use AnaBot\Models\AnalysisMessage;
use AnaBot\Models\AnalysisSession;
use RuntimeException;

/**
 * AnaBot AI Analyst Orchestrator
 * 
 * Coordinates multi-turn conversation sessions, context assembly from AnalyticsEngine,
 * anti-hallucination prompt guardrails, and AI provider inference execution.
 */
class AIOrchestrator
{
    private Database $db;
    private AIManager $aiManager;
    private AIContextBuilder $contextBuilder;
    private Logger $logger;

    public function __construct(
        ?Database $db = null,
        ?AIManager $aiManager = null,
        ?AIContextBuilder $contextBuilder = null,
        ?Logger $logger = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->aiManager = $aiManager ?? AIManager::getInstance($this->db);
        $this->contextBuilder = $contextBuilder ?? new AIContextBuilder($this->db);
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Process a user question within an analysis session.
     *
     * @param int $sessionId Analysis session ID
     * @param string $userQuery User's analytical question
     * @param array $options Additional options (provider_id, model, temperature)
     * @return array Analytical response and execution metadata
     */
    public function ask(int $sessionId, string $userQuery, array $options = []): array
    {
        $session = AnalysisSession::find($sessionId);
        if (!$session) {
            throw new RuntimeException("Sesi analisis dengan ID #{$sessionId} tidak ditemukan.");
        }

        $userQuery = trim($userQuery);
        if ($userQuery === '') {
            throw new RuntimeException("Pertanyaan tidak boleh kosong.");
        }

        // 1. Gather attached datasets
        $attached = AnalysisSession::getAttachedDatasets($sessionId);
        if (empty($attached)) {
            // Friendly prompt to select a dataset first
            $noDataMsg = "Sesi analisis ini belum memiliki dataset yang ditautkan. Silakan tautkan minimal satu dataset melalui menu di atas agar saya dapat menganalisis data terverifikasi untuk Anda.";
            
            AnalysisMessage::create([
                'session_id' => $sessionId,
                'sender_type' => 'user',
                'message_content' => $userQuery,
            ]);

            $msgId = AnalysisMessage::create([
                'session_id' => $sessionId,
                'sender_type' => 'assistant',
                'message_content' => $noDataMsg,
            ]);

            return [
                'session_id' => $sessionId,
                'message_id' => $msgId,
                'user_query' => $userQuery,
                'response' => $noDataMsg,
                'summary_cards' => [],
                'tokens_input' => 0,
                'tokens_output' => 0,
                'provider' => 'system',
                'model' => 'system',
                'latency_ms' => 0,
            ];
        }

        $datasetIds = array_map(fn($d) => (int) $d['id'], $attached);

        // 2. Build verified factual grounding context from SQLite & AnalyticsEngine
        $context = $this->contextBuilder->buildContext($datasetIds);

        // 3. Load recent conversation history (multi-turn memory)
        $history = AnalysisMessage::getRecentConversationHistory($sessionId, 8);

        // 4. Construct messages payload enforcing Anti-Hallucination Guardrail
        $messages = PromptManager::buildMultiTurnPrompt(
            $context['markdown_facts'],
            $history,
            $userQuery,
            $options
        );

        // 5. Record user message to database
        AnalysisMessage::create([
            'session_id' => $sessionId,
            'sender_type' => 'user',
            'message_content' => $userQuery,
            'context_summary' => "Analyzing " . count($datasetIds) . " datasets: " . implode(', ', array_column($attached, 'name')),
        ]);

        // 6. Execute AI generation via AIManager (automatic failover & budgeting)
        $startTime = microtime(true);
        $aiResult = $this->aiManager->generateChat($messages, $options);
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        $assistantText = $aiResult['text'] ?? $aiResult['content'] ?? 'Maaf, terjadi kendala saat memproses interpretasi data.';
        $tokensInput = (int) ($aiResult['prompt_tokens'] ?? $aiResult['tokens_prompt'] ?? 0);
        $tokensOutput = (int) ($aiResult['completion_tokens'] ?? $aiResult['tokens_completion'] ?? 0);

        // 7. Record assistant message to database
        $msgId = AnalysisMessage::create([
            'session_id' => $sessionId,
            'sender_type' => 'assistant',
            'message_content' => $assistantText,
            'context_summary' => "AI Response via " . ($aiResult['provider'] ?? 'AI') . " (" . ($aiResult['model'] ?? '') . ")",
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
        ]);

        return [
            'session_id' => $sessionId,
            'message_id' => $msgId,
            'user_query' => $userQuery,
            'response' => $assistantText,
            'summary_cards' => $context['summary_cards'] ?? [],
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'provider' => $aiResult['provider'] ?? 'unknown',
            'model' => $aiResult['model'] ?? 'unknown',
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * Execute a specialized one-click quick analysis action.
     *
     * @param int $sessionId Analysis session ID
     * @param string $actionType Action type: executive_summary | root_cause_diagnosis | strategic_recommendations | anomaly_detection
     * @param array $options Additional options
     * @return array Analytical response and execution metadata
     */
    public function executeQuickAction(int $sessionId, string $actionType, array $options = []): array
    {
        $session = AnalysisSession::find($sessionId);
        if (!$session) {
            throw new RuntimeException("Sesi analisis dengan ID #{$sessionId} tidak ditemukan.");
        }

        $attached = AnalysisSession::getAttachedDatasets($sessionId);
        if (empty($attached)) {
            throw new RuntimeException("Harap tautkan minimal satu dataset ke sesi ini sebelum menjalankan analisis cepat.");
        }

        $datasetIds = array_map(fn($d) => (int) $d['id'], $attached);
        $context = $this->contextBuilder->buildContext($datasetIds);

        // Friendly action titles
        $actionTitles = [
            'executive_summary' => 'Ringkasan Eksekutif Terpadu',
            'root_cause_diagnosis' => 'Diagnosis Akar Masalah & Pola Kinerja',
            'strategic_recommendations' => 'Rekomendasi Aksi Strategis & Taktis',
            'anomaly_detection' => 'Investigasi Anomali & Fluktuasi Ekstrem',
        ];
        $actionTitle = $actionTitles[$actionType] ?? 'Analisis Cepat Data';

        // 1. Build prompt
        $messages = PromptManager::buildQuickActionPrompt($actionType, $context['markdown_facts'], $options);

        // 2. Record user action as message
        AnalysisMessage::create([
            'session_id' => $sessionId,
            'sender_type' => 'user',
            'message_content' => "Jalankan aksi analisis cepat: **{$actionTitle}**",
            'context_summary' => "Action: {$actionType}",
        ]);

        // 3. Execute AI generation
        $startTime = microtime(true);
        $aiResult = $this->aiManager->generateChat($messages, $options);
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        $assistantText = $aiResult['text'] ?? $aiResult['content'] ?? 'Maaf, gagal menghasilkan analisis cepat.';
        $tokensInput = (int) ($aiResult['prompt_tokens'] ?? $aiResult['tokens_prompt'] ?? 0);
        $tokensOutput = (int) ($aiResult['completion_tokens'] ?? $aiResult['tokens_completion'] ?? 0);

        // 4. Record assistant message
        $msgId = AnalysisMessage::create([
            'session_id' => $sessionId,
            'sender_type' => 'assistant',
            'message_content' => $assistantText,
            'context_summary' => "Quick Action: {$actionType} via " . ($aiResult['provider'] ?? 'AI'),
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
        ]);

        return [
            'session_id' => $sessionId,
            'message_id' => $msgId,
            'action_type' => $actionType,
            'action_title' => $actionTitle,
            'response' => $assistantText,
            'summary_cards' => $context['summary_cards'] ?? [],
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'provider' => $aiResult['provider'] ?? 'unknown',
            'model' => $aiResult['model'] ?? 'unknown',
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * Get preview of verified factual context for a session.
     */
    public function getSessionFactsPreview(int $sessionId): array
    {
        $session = AnalysisSession::find($sessionId);
        if (!$session) {
            throw new RuntimeException("Sesi analisis dengan ID #{$sessionId} tidak ditemukan.");
        }

        $attached = AnalysisSession::getAttachedDatasets($sessionId);
        if (empty($attached)) {
            return [
                'session' => $session,
                'attached_datasets' => [],
                'context' => null,
            ];
        }

        $datasetIds = array_map(fn($d) => (int) $d['id'], $attached);
        $context = $this->contextBuilder->buildContext($datasetIds);

        return [
            'session' => $session,
            'attached_datasets' => $attached,
            'context' => $context,
        ];
    }
}
