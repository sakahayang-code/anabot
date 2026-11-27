<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\AI\AIManager;
use AnaBot\AI\PromptManager;
use AnaBot\Core\Database;
use AnaBot\Core\Encryption;
use AnaBot\Core\MigrationRunner;
use AnaBot\Models\AIProvider;
use AnaBot\Models\AnalysisMessage;
use AnaBot\Models\AnalysisSession;
use AnaBot\Models\Dataset;
use AnaBot\Services\AIContextBuilder;
use AnaBot\Services\AIOrchestrator;
use AnaBot\Services\HttpClient;

/**
 * Phase 8: AI Analyst Automated Test Suite
 * 
 * Verifies:
 * - AnalysisSession model CRUD & dataset attachment
 * - AnalysisMessage model & conversation history extraction
 * - AIContextBuilder factual context generation from real SQLite data
 * - PromptManager multi-turn and quick-action prompt construction
 * - Strict Anti-Hallucination Guardrail enforcement
 * - AIOrchestrator ask() workflow with multi-turn memory
 * - AIOrchestrator executeQuickAction() workflow
 * - Cascade deletion integrity
 */
class AIAnalystTest
{
    private Database $db;
    private Encryption $encryption;
    private AIManager $aiManager;
    private AIContextBuilder $contextBuilder;
    private AIOrchestrator $orchestrator;
    private int $testUserId;
    private int $testDatasetId;

    public function run(): void
    {
        echo "Testing AI Analyst Engine & Conversational Intelligence (Isolated Test DB)..." . PHP_EOL;

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
        $this->contextBuilder = new AIContextBuilder($this->db);

        $this->seedTestData();

        $this->testAnalysisSessionCrud();
        $this->testAnalysisSessionDatasetAttachments();
        $this->testAnalysisMessageCreationAndPagination();
        $this->testAnalysisMessageRecentConversationHistory();
        $this->testAIContextBuilderFactCompilation();
        $this->testPromptManagerMultiTurnPrompt();
        $this->testPromptManagerQuickActionPrompts();
        $this->testAIOrchestratorAskWithGroundedFacts();
        $this->testAIOrchestratorQuickActionExecution();
        $this->testCascadeDeletionIntegrity();

        echo "  [PASS] All 10 AI Analyst test cases passed successfully." . PHP_EOL;
    }

    private function seedTestData(): void
    {
        $now = date('c');

        // 1. User
        $this->db->execute(
            "INSERT INTO users (username, email, password_hash, status, created_at, updated_at)
             VALUES ('analyst_user', 'analyst@anabot.local', 'hash', 'active', :now, :now)",
            [':now' => $now]
        );
        $this->testUserId = (int) $this->db->lastInsertId();

        // 2. Connector
        $this->db->execute(
            "INSERT INTO connectors (connector_code, name, category, auth_type, created_at)
             VALUES ('google_sheets', 'Google Sheets', 'spreadsheet', 'service_account', :now)",
            [':now' => $now]
        );
        $connId = (int) $this->db->lastInsertId();

        // 3. Platform Connection
        $this->db->execute(
            "INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at)
             VALUES (:cid, :uid, 'Analyst Test Connection', 'connected', :now, :now)",
            [':uid' => $this->testUserId, ':cid' => $connId, ':now' => $now]
        );
        $pcId = (int) $this->db->lastInsertId();

        // 4. Data Source
        $this->db->execute(
            "INSERT INTO data_sources (connection_id, external_source_id, source_name, created_at, updated_at)
             VALUES (:pcid, 'sheet_sales_101', 'Penjualan Retail Sheet', :now, :now)",
            [':pcid' => $pcId, ':now' => $now]
        );
        $dsId = (int) $this->db->lastInsertId();

        // 5. Dataset
        $this->db->execute(
            "INSERT INTO datasets (data_source_id, name, record_count, first_record_at, latest_record_at, created_at, updated_at)
             VALUES (:dsid, 'Laporan Penjualan Retail Q1', 10, '2026-01-01', '2026-01-10', :now, :now)",
            [':dsid' => $dsId, ':now' => $now]
        );
        $this->testDatasetId = (int) $this->db->lastInsertId();

        // 6. Dataset Columns
        $cols = [
            ['tanggal', 'DATETIME', 1, 0, 0, 1],
            ['revenue', 'NUMERIC', 0, 1, 0, 2],
            ['units_sold', 'INTEGER', 0, 1, 0, 3],
            ['kategori', 'TEXT', 0, 0, 1, 4],
        ];
        foreach ($cols as $c) {
            $this->db->execute(
                "INSERT INTO dataset_columns (dataset_id, column_name, data_type, is_primary_date, is_metric, is_dimension, column_order, created_at)
                 VALUES (:did, :cn, :dt, :pd, :m, :d, :co, :now)",
                [
                    ':did' => $this->testDatasetId,
                    ':cn' => $c[0],
                    ':dt' => $c[1],
                    ':pd' => $c[2],
                    ':m' => $c[3],
                    ':d' => $c[4],
                    ':co' => $c[5],
                    ':now' => $now,
                ]
            );
        }

        // 7. Seed Records
        $sampleRecords = [
            ['2026-01-01', 1000000, 10, 'Elektronik'],
            ['2026-01-02', 1200000, 12, 'Elektronik'],
            ['2026-01-03', 800000, 8, 'Fashion'],
            ['2026-01-04', 1500000, 15, 'Elektronik'],
            ['2026-01-05', 2000000, 20, 'Elektronik'],
            ['2026-01-06', 700000, 7, 'Food'],
            ['2026-01-07', 950000, 9, 'Fashion'],
            ['2026-01-08', 2500000, 25, 'Elektronik'],
            ['2026-01-09', 1100000, 11, 'Fashion'],
            ['2026-01-10', 3000000, 30, 'Elektronik'],
        ];

        foreach ($sampleRecords as $idx => $row) {
            $rowNum = $idx + 1;
            $dataJson = json_encode([
                'tanggal' => $row[0],
                'revenue' => $row[1],
                'units_sold' => $row[2],
                'kategori' => $row[3],
            ]);
            $this->db->execute(
                "INSERT INTO data_records (dataset_id, external_id, record_date, record_hash, data_json, created_at, updated_at)
                 VALUES (:did, :ext, :rdate, :rhash, :json, :now, :now)",
                [
                    ':did' => $this->testDatasetId,
                    ':ext' => 'row_' . $rowNum,
                    ':rdate' => $row[0],
                    ':rhash' => hash('sha256', (string) $rowNum),
                    ':json' => $dataJson,
                    ':now' => $now,
                ]
            );
        }
    }

    private function testAnalysisSessionCrud(): void
    {
        // Create session
        $sessionId = AnalysisSession::create([
            'user_id' => $this->testUserId,
            'title' => 'Sesi Uji Analisis Q1',
            'description' => 'Eksplorasi performa pendapatan dan korelasi unit.',
        ]);
        assert($sessionId > 0, 'AnalysisSession::create must return positive ID');

        // Find session
        $session = AnalysisSession::find($sessionId, $this->testUserId);
        assert($session !== null, 'AnalysisSession::find must return created session');
        assert($session['title'] === 'Sesi Uji Analisis Q1', 'Session title must match');

        // Update session
        $updated = AnalysisSession::update($sessionId, ['title' => 'Sesi Uji Analisis Q1 Updated']);
        assert($updated === true, 'AnalysisSession::update must return true');

        $sessionAfter = AnalysisSession::find($sessionId, $this->testUserId);
        assert($sessionAfter['title'] === 'Sesi Uji Analisis Q1 Updated', 'Session title must be updated');

        // All for user
        $all = AnalysisSession::allForUser($this->testUserId);
        assert(count($all) >= 1, 'AnalysisSession::allForUser must return at least 1 session');

        echo "  ✓ AnalysisSession CRUD operations verified." . PHP_EOL;
    }

    private function testAnalysisSessionDatasetAttachments(): void
    {
        $sessionId = AnalysisSession::create([
            'user_id' => $this->testUserId,
            'title' => 'Sesi Penautan Dataset',
            'dataset_ids' => [$this->testDatasetId],
        ]);

        $attached = AnalysisSession::getAttachedDatasets($sessionId);
        assert(count($attached) === 1, 'Session must have exactly 1 attached dataset');
        assert((int) $attached[0]['id'] === $this->testDatasetId, 'Attached dataset ID must match test dataset');

        // Detach
        $detached = AnalysisSession::detachDataset($sessionId, $this->testDatasetId);
        assert($detached === true, 'Detach dataset must succeed');
        assert(count(AnalysisSession::getAttachedDatasets($sessionId)) === 0, 'Attached datasets must be empty after detach');

        // Sync multiple
        AnalysisSession::syncDatasets($sessionId, [$this->testDatasetId]);
        assert(count(AnalysisSession::getAttachedDatasets($sessionId)) === 1, 'Sync datasets must restore attached dataset');

        echo "  ✓ AnalysisSession dataset attachment, detachment & sync verified." . PHP_EOL;
    }

    private function testAnalysisMessageCreationAndPagination(): void
    {
        $sessionId = AnalysisSession::create([
            'user_id' => $this->testUserId,
            'title' => 'Sesi Pengujian Pesan',
        ]);

        // Create user message
        $m1 = AnalysisMessage::create([
            'session_id' => $sessionId,
            'sender_type' => 'user',
            'message_content' => 'Bagaimana laju pertumbuhan revenue?',
        ]);
        assert($m1 > 0, 'User message ID must be positive');

        // Create assistant message
        $m2 = AnalysisMessage::create([
            'session_id' => $sessionId,
            'sender_type' => 'assistant',
            'message_content' => 'Laju pertumbuhan revenue mengalami kenaikan total +200% dengan tren positif.',
            'tokens_input' => 150,
            'tokens_output' => 45,
        ]);
        assert($m2 > 0, 'Assistant message ID must be positive');

        $messages = AnalysisMessage::getBySession($sessionId);
        assert(count($messages) === 2, 'Session must have 2 messages');
        assert($messages[0]['sender_type'] === 'user', 'First message must be user');
        assert($messages[1]['sender_type'] === 'assistant', 'Second message must be assistant');
        assert((int) $messages[1]['tokens_input'] === 150, 'tokens_input must match');
        assert((int) $messages[1]['tokens_output'] === 45, 'tokens_output must match');

        echo "  ✓ AnalysisMessage persistence, sender types, and token metrics verified." . PHP_EOL;
    }

    private function testAnalysisMessageRecentConversationHistory(): void
    {
        $sessionId = AnalysisSession::create([
            'user_id' => $this->testUserId,
            'title' => 'Sesi Riwayat Multi-Turn',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            AnalysisMessage::create([
                'session_id' => $sessionId,
                'sender_type' => 'user',
                'message_content' => "Pertanyaan ke-{$i}",
            ]);
            AnalysisMessage::create([
                'session_id' => $sessionId,
                'sender_type' => 'assistant',
                'message_content' => "Jawaban ke-{$i}",
            ]);
        }

        // Fetch last 4 turns (2 user, 2 assistant)
        $history = AnalysisMessage::getRecentConversationHistory($sessionId, 4);
        assert(count($history) === 4, 'Conversation history must return exactly 4 turns');
        assert($history[0]['role'] === 'user', 'History turn 0 must be user');
        assert($history[1]['role'] === 'assistant', 'History turn 1 must be assistant');
        assert($history[2]['content'] === 'Pertanyaan ke-5', 'Last user question must be Pertanyaan ke-5');
        assert($history[3]['content'] === 'Jawaban ke-5', 'Last assistant answer must be Jawaban ke-5');

        echo "  ✓ Multi-turn conversation history extraction & chronological sorting verified." . PHP_EOL;
    }

    private function testAIContextBuilderFactCompilation(): void
    {
        $context = $this->contextBuilder->buildContext([$this->testDatasetId]);

        assert($context['dataset_count'] === 1, 'Context must contain 1 dataset');
        assert(!empty($context['markdown_facts']), 'markdown_facts must not be empty');

        $md = $context['markdown_facts'];
        assert(str_contains($md, 'FAKTA DATA TERVERIFIKASI'), 'Markdown must contain verified facts header');
        assert(str_contains($md, 'revenue'), 'Markdown must contain revenue metric');
        assert(str_contains($md, 'units_sold'), 'Markdown must contain units_sold metric');
        assert(str_contains($md, 'Statistik Deskriptif'), 'Markdown must contain descriptive statistics section');
        assert(str_contains($md, 'Pertumbuhan & Arah Tren'), 'Markdown must contain growth & trend section');
        assert(str_contains($md, 'Korelasi Pearson'), 'Markdown must contain Pearson correlation section');
        assert(str_contains($md, 'Kontribusi Dimensi'), 'Markdown must contain dimension breakdown section');
        assert(str_contains($md, 'Elektronik'), 'Markdown must list top category Elektronik');

        // Summary cards
        assert(!empty($context['summary_cards']), 'summary_cards must be generated');
        assert(count($context['summary_cards']) >= 3, 'Must have at least 3 summary KPI cards');

        echo "  ✓ AIContextBuilder mathematical fact compilation & markdown formatting verified." . PHP_EOL;
    }

    private function testPromptManagerMultiTurnPrompt(): void
    {
        $factsMarkdown = "### FAKTA DATA TERVERIFIKASI\n- Revenue: 14.750.000\n- Tren OLS: UPTREND";
        $history = [
            ['role' => 'user', 'content' => 'Bagaimana tren bisnis?'],
            ['role' => 'assistant', 'content' => 'Tren bisnis mengalami kenaikan stabil (Uptrend).'],
        ];
        $userQuery = 'Apa kategori produk terbaik?';

        $messages = PromptManager::buildMultiTurnPrompt($factsMarkdown, $history, $userQuery);

        assert(count($messages) === 4, 'Multi-turn messages array must contain system + 2 history + 1 current');
        assert($messages[0]['role'] === 'system', 'Message 0 must be system');
        assert(str_contains($messages[0]['content'], 'ATURAN WAJIB (STRICT GUARDRAILS)'), 'System message must contain Anti-Hallucination Guardrail');
        assert(str_contains($messages[0]['content'], 'FAKTA DATA TERVERIFIKASI'), 'System message must contain factual grounding markdown');
        assert($messages[1]['role'] === 'user', 'Message 1 must be user history');
        assert($messages[2]['role'] === 'assistant', 'Message 2 must be assistant history');
        assert($messages[3]['role'] === 'user', 'Message 3 must be current user query');
        assert($messages[3]['content'] === $userQuery, 'Message 3 content must match query');

        echo "  ✓ PromptManager multi-turn prompt with Anti-Hallucination Guardrails verified." . PHP_EOL;
    }

    private function testPromptManagerQuickActionPrompts(): void
    {
        $factsMarkdown = "### FAKTA DATA TERVERIFIKASI\n- Total: 14.750.000";

        $actions = ['executive_summary', 'root_cause_diagnosis', 'strategic_recommendations', 'anomaly_detection'];

        foreach ($actions as $act) {
            $prompt = PromptManager::buildQuickActionPrompt($act, $factsMarkdown);
            assert(count($prompt) === 2, "Action {$act} must return [system, user] messages");
            assert($prompt[0]['role'] === 'system', "Action {$act} role 0 must be system");
            assert($prompt[1]['role'] === 'user', "Action {$act} role 1 must be user");
            assert(!empty($prompt[1]['content']), "Action {$act} user content must not be empty");
        }

        echo "  ✓ PromptManager specialized quick-action prompts (Summary, Diagnosis, Strategy, Anomaly) verified." . PHP_EOL;
    }

    private function testAIOrchestratorAskWithGroundedFacts(): void
    {
        $sessionId = AnalysisSession::create([
            'user_id' => $this->testUserId,
            'title' => 'Sesi Orkestrasi AI Analyst',
            'dataset_ids' => [$this->testDatasetId],
        ]);

        // Create a mock HttpClient for AIManager to return simulated AI answer
        $mockHttp = new class extends HttpClient {
            public function post(string $url, mixed $data = null, array $headers = [], array $options = []): array {
                $reply = "Berdasarkan data aktual, total pendapatan tercatat Rp 14.750.000 dengan tren positif UPTREND. Kategori Elektronik mendominasi volume penjualan.";
                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => json_encode([
                        'choices' => [
                            [
                                'message' => ['role' => 'assistant', 'content' => $reply],
                                'finish_reason' => 'stop',
                            ]
                        ],
                        'usage' => [
                            'prompt_tokens' => 280,
                            'completion_tokens' => 65,
                            'total_tokens' => 345,
                        ]
                    ]),
                    'error' => null,
                ];
            }
        };

        $mockManager = new AIManager($this->db, $this->encryption, $mockHttp);
        $orchestrator = new AIOrchestrator($this->db, $mockManager, $this->contextBuilder);

        // Ask question
        $res = $orchestrator->ask($sessionId, 'Jelaskan ringkasan pendapatan kami');

        assert($res['session_id'] === $sessionId, 'Result session_id must match');
        assert(str_contains($res['response'], '14.750.000'), 'AI response must contain verified metric');
        assert($res['tokens_input'] === 280, 'Input tokens must match mock');
        assert($res['tokens_output'] === 65, 'Output tokens must match mock');
        assert(!empty($res['summary_cards']), 'Summary cards must be returned in response');

        // Check messages table in DB
        $dbMessages = AnalysisMessage::getBySession($sessionId);
        assert(count($dbMessages) === 2, 'DB must record 2 messages (1 user, 1 assistant)');
        assert($dbMessages[0]['sender_type'] === 'user', 'Message 0 must be user');
        assert($dbMessages[1]['sender_type'] === 'assistant', 'Message 1 must be assistant');

        echo "  ✓ AIOrchestrator::ask() end-to-end grounded query execution & DB recording verified." . PHP_EOL;
    }

    private function testAIOrchestratorQuickActionExecution(): void
    {
        $sessionId = AnalysisSession::create([
            'user_id' => $this->testUserId,
            'title' => 'Sesi Analisis Cepat Eksekutif',
            'dataset_ids' => [$this->testDatasetId],
        ]);

        $mockHttp = new class extends HttpClient {
            public function post(string $url, mixed $data = null, array $headers = [], array $options = []): array {
                $summary = "## Ringkasan Eksekutif\n1. **Highlight Utama:** Pendapatan meningkat signifikan.\n2. **Kategori Penentu:** Elektronik menyumbang 65% volume.\n3. **Korelasi:** Revenue dan units_sold berkorelasi sangat kuat (r = 1.0).";
                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => json_encode([
                        'choices' => [
                            [
                                'message' => ['role' => 'assistant', 'content' => $summary],
                                'finish_reason' => 'stop',
                            ]
                        ],
                        'usage' => [
                            'prompt_tokens' => 310,
                            'completion_tokens' => 90,
                            'total_tokens' => 400,
                        ]
                    ]),
                    'error' => null,
                ];
            }
        };

        $mockManager = new AIManager($this->db, $this->encryption, $mockHttp);
        $orchestrator = new AIOrchestrator($this->db, $mockManager, $this->contextBuilder);

        $res = $orchestrator->executeQuickAction($sessionId, 'executive_summary');

        assert($res['action_type'] === 'executive_summary', 'Action type must match');
        assert(str_contains($res['response'], 'Ringkasan Eksekutif'), 'Response must contain Executive Summary');
        assert($res['tokens_input'] === 310, 'Prompt tokens must match mock');
        assert($res['tokens_output'] === 90, 'Completion tokens must match mock');

        echo "  ✓ AIOrchestrator::executeQuickAction() one-click executive briefing verified." . PHP_EOL;
    }

    private function testCascadeDeletionIntegrity(): void
    {
        $sessionId = AnalysisSession::create([
            'user_id' => $this->testUserId,
            'title' => 'Sesi Untuk Dihapus',
            'dataset_ids' => [$this->testDatasetId],
        ]);

        AnalysisMessage::create([
            'session_id' => $sessionId,
            'sender_type' => 'user',
            'message_content' => 'Pesan uji coba',
        ]);
        AnalysisMessage::create([
            'session_id' => $sessionId,
            'sender_type' => 'assistant',
            'message_content' => 'Balasan uji coba',
        ]);

        // Verify existing before deletion
        assert(count(AnalysisMessage::getBySession($sessionId)) === 2, 'Must have 2 messages');
        assert(count(AnalysisSession::getAttachedDatasets($sessionId)) === 1, 'Must have 1 attached dataset');

        // Delete session
        $deleted = AnalysisSession::delete($sessionId);
        assert($deleted === true, 'Session delete must return true');

        // Verify cascade deletion
        assert(AnalysisSession::find($sessionId) === null, 'Session must not exist');
        assert(count(AnalysisMessage::getBySession($sessionId)) === 0, 'Messages must be cascade deleted');
        assert(count(AnalysisSession::getAttachedDatasets($sessionId)) === 0, 'Attached sources must be cascade deleted');

        echo "  ✓ Cascade deletion integrity for sessions, messages, and sources verified." . PHP_EOL;
    }
}
