<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\AI\AIManager;
use AnaBot\Core\Auth;
use AnaBot\Core\Database;
use AnaBot\Core\Response;
use AnaBot\Core\Validator;
use AnaBot\Models\AIProvider;
use AnaBot\Models\AnalysisMessage;
use AnaBot\Models\AnalysisSession;
use AnaBot\Models\Dataset;
use AnaBot\Services\AIContextBuilder;
use AnaBot\Services\AIOrchestrator;
use Throwable;

/**
 * AnaBot AI Analyst Controller
 * 
 * Manages the AI Analyst chat interface, analytical sessions,
 * grounding context inspector, and multi-turn conversation workflows.
 */
class AIAnalystController
{
    private Database $db;
    private AIOrchestrator $orchestrator;
    private AIContextBuilder $contextBuilder;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->orchestrator = new AIOrchestrator($this->db);
        $this->contextBuilder = new AIContextBuilder($this->db);
    }

    /**
     * Web View: AI Analyst Chat Interface
     */
    public function chatPage(mixed $params = null): void
    {
        $userId = Auth::id() ?? 1;
        $requestedId = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;

        // 1. Fetch all sessions for this user
        $sessions = AnalysisSession::allForUser($userId);

        // 2. Determine active session
        $activeSession = null;
        if ($requestedId > 0) {
            $activeSession = AnalysisSession::find($requestedId, $userId);
        }

        if (!$activeSession && !empty($sessions)) {
            $activeSession = AnalysisSession::find((int) $sessions[0]['id'], $userId);
        }

        // 3. Fetch messages and context if an active session exists
        $messages = [];
        $factsPreview = null;
        if ($activeSession) {
            $messages = AnalysisMessage::getBySession((int) $activeSession['id'], 100);
            $attached = AnalysisSession::getAttachedDatasets((int) $activeSession['id']);
            if (!empty($attached)) {
                $datasetIds = array_map(fn($d) => (int) $d['id'], $attached);
                $factsPreview = $this->contextBuilder->buildContext($datasetIds);
            }
        }

        // 4. Fetch available datasets for linking
        $availableDatasets = Dataset::all(100, 0);

        // 5. Fetch active AI providers
        $activeProviders = AIProvider::allActive();

        // Render page inside main layout
        ob_start();
        $pageTitle = 'AI Analyst — Personal Intelligence Assistant';
        include dirname(__DIR__, 2) . '/resources/views/ai/chat.php';
        $content = ob_get_clean();

        Response::view('layouts/main', [
            'pageTitle' => $pageTitle,
            'content' => $content,
        ]);
    }

    /**
     * Web Action: Create New Session and redirect
     */
    public function newSession(): void
    {
        $userId = Auth::id() ?? 1;
        $title = trim($_POST['title'] ?? '') ?: 'Sesi Analisis ' . date('d/m/Y H:i');
        $datasetIds = $_POST['dataset_ids'] ?? [];

        if (is_string($datasetIds)) {
            $datasetIds = explode(',', $datasetIds);
        }

        $sessionId = AnalysisSession::create([
            'user_id' => $userId,
            'title' => $title,
            'dataset_ids' => array_filter(array_map('intval', (array) $datasetIds)),
        ]);

        Response::redirect('/ai/chat/' . $sessionId);
    }

    // ==========================================
    // REST API ENDPOINTS
    // ==========================================

    /**
     * API: Get all sessions for user
     * GET /api/v1/ai/sessions
     */
    public function getSessions(): void
    {
        $userId = Auth::id() ?? 1;
        $sessions = AnalysisSession::allForUser($userId);
        Response::json([
            'success' => true,
            'data' => $sessions,
        ]);
    }

    /**
     * API: Create a new session
     * POST /api/v1/ai/sessions
     */
    public function createSession(): void
    {
        $userId = Auth::id() ?? 1;
        $input = $this->getRequestJson();

        $title = trim($input['title'] ?? '') ?: 'Sesi Analisis ' . date('d/m/Y H:i');
        $datasetIds = (array) ($input['dataset_ids'] ?? []);

        $sessionId = AnalysisSession::create([
            'user_id' => $userId,
            'title' => $title,
            'description' => $input['description'] ?? null,
            'provider_id' => !empty($input['provider_id']) ? (int) $input['provider_id'] : null,
            'model_id' => !empty($input['model_id']) ? (int) $input['model_id'] : null,
            'dataset_ids' => array_filter(array_map('intval', $datasetIds)),
        ]);

        $session = AnalysisSession::find($sessionId, $userId);

        Response::json([
            'success' => true,
            'message' => 'Sesi analisis berhasil dibuat.',
            'data' => $session,
        ], 201);
    }

    /**
     * API: Get session details and messages
     * GET /api/v1/ai/sessions/{id}
     */
    public function getSession(mixed $params): void
    {
        $userId = Auth::id() ?? 1;
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;

        $session = AnalysisSession::find($id, $userId);
        if (!$session) {
            Response::json(['success' => false, 'error' => 'Sesi tidak ditemukan.'], 404);
            return;
        }

        $messages = AnalysisMessage::getBySession($id, 100);

        Response::json([
            'success' => true,
            'data' => [
                'session' => $session,
                'messages' => $messages,
            ],
        ]);
    }

    /**
     * API: Update session
     * PUT /api/v1/ai/sessions/{id}
     */
    public function updateSession(mixed $params): void
    {
        $userId = Auth::id() ?? 1;
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;

        $session = AnalysisSession::find($id, $userId);
        if (!$session) {
            Response::json(['success' => false, 'error' => 'Sesi tidak ditemukan.'], 404);
            return;
        }

        $input = $this->getRequestJson();
        AnalysisSession::update($id, $input);

        $updated = AnalysisSession::find($id, $userId);
        Response::json([
            'success' => true,
            'message' => 'Sesi berhasil diperbarui.',
            'data' => $updated,
        ]);
    }

    /**
     * API: Delete session
     * DELETE /api/v1/ai/sessions/{id}
     */
    public function deleteSession(mixed $params): void
    {
        $userId = Auth::id() ?? 1;
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;

        $session = AnalysisSession::find($id, $userId);
        if (!$session) {
            Response::json(['success' => false, 'error' => 'Sesi tidak ditemukan.'], 404);
            return;
        }

        AnalysisSession::delete($id);

        Response::json([
            'success' => true,
            'message' => 'Sesi analisis dan seluruh riwayat obrolan berhasil dihapus.',
        ]);
    }

    /**
     * API: Send message to AI Analyst
     * POST /api/v1/ai/sessions/{id}/messages
     */
    public function sendMessage(mixed $params): void
    {
        $userId = Auth::id() ?? 1;
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;

        $session = AnalysisSession::find($id, $userId);
        if (!$session) {
            Response::json(['success' => false, 'error' => 'Sesi tidak ditemukan.'], 404);
            return;
        }

        $input = $this->getRequestJson();
        $query = trim($input['query'] ?? $input['message'] ?? '');

        if ($query === '') {
            Response::json(['success' => false, 'error' => 'Pesan atau pertanyaan tidak boleh kosong.'], 422);
            return;
        }

        try {
            $options = [];
            if (!empty($input['provider_id'])) {
                $options['provider_id'] = (int) $input['provider_id'];
            }
            if (!empty($input['model'])) {
                $options['model'] = (string) $input['model'];
            }

            $result = $this->orchestrator->ask($id, $query, $options);

            Response::json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            Response::json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Execute quick analytical action
     * POST /api/v1/ai/sessions/{id}/quick-action
     */
    public function quickAction(mixed $params): void
    {
        $userId = Auth::id() ?? 1;
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;

        $session = AnalysisSession::find($id, $userId);
        if (!$session) {
            Response::json(['success' => false, 'error' => 'Sesi tidak ditemukan.'], 404);
            return;
        }

        $input = $this->getRequestJson();
        $action = trim($input['action'] ?? 'executive_summary');

        $validActions = ['executive_summary', 'root_cause_diagnosis', 'strategic_recommendations', 'anomaly_detection'];
        if (!in_array($action, $validActions, true)) {
            Response::json(['success' => false, 'error' => 'Aksi analitis tidak valid.'], 422);
            return;
        }

        try {
            $result = $this->orchestrator->executeQuickAction($id, $action);

            Response::json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            Response::json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Preview facts context for session
     * GET /api/v1/ai/sessions/{id}/context
     */
    public function getContextPreview(mixed $params): void
    {
        $userId = Auth::id() ?? 1;
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;

        try {
            $preview = $this->orchestrator->getSessionFactsPreview($id);
            Response::json([
                'success' => true,
                'data' => $preview,
            ]);
        } catch (Throwable $e) {
            Response::json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * API: Clear all messages in session
     * POST /api/v1/ai/sessions/{id}/clear
     */
    public function clearMessages(mixed $params): void
    {
        $userId = Auth::id() ?? 1;
        $id = is_array($params) ? (int) ($params['id'] ?? 0) : (int) $params;

        $session = AnalysisSession::find($id, $userId);
        if (!$session) {
            Response::json(['success' => false, 'error' => 'Sesi tidak ditemukan.'], 404);
            return;
        }

        AnalysisMessage::clearSession($id);

        Response::json([
            'success' => true,
            'message' => 'Riwayat pesan dalam sesi ini berhasil dibersihkan.',
        ]);
    }

    /**
     * Helper to read JSON request input or $_POST
     */
    private function getRequestJson(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST ?? [];
    }
}
