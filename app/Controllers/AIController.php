<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\AI\AIManager;
use AnaBot\Core\Database;
use AnaBot\Core\Encryption;
use AnaBot\Core\Response;
use AnaBot\Models\AIModel;
use AnaBot\Models\AIProvider;

/**
 * AnaBot AI Controller
 * 
 * Manages Multi-Provider AI settings, API key encryption, live test connectivity,
 * token usage logs, and playground prompt testing.
 */
class AIController
{
    private Database $db;
    private Encryption $encryption;
    private AIManager $aiManager;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
        $this->aiManager = new AIManager($this->db, $this->encryption);
        // Ensure catalog is synced
        $this->aiManager->syncProvidersCatalog();
    }

    /**
     * Web View: AI Provider Settings Dashboard
     */
    public function settingsPage(): void
    {
        $providers = AIProvider::all();
        $startOfMonth = date('Y-m-01 00:00:00');

        // Usage statistics for current month
        $monthlyUsage = $this->db->queryOne(
            "SELECT COUNT(*) as total_requests,
                    SUM(total_tokens) as total_tokens,
                    SUM(estimated_cost_cents) as total_cost_cents
             FROM ai_usage_logs 
             WHERE status = 'success' AND created_at >= :start",
            [':start' => $startOfMonth]
        );

        $recentLogs = $this->db->queryAll(
            "SELECT l.*, p.name as provider_name, p.provider_code 
             FROM ai_usage_logs l
             JOIN ai_providers p ON l.provider_id = p.id
             ORDER BY l.id DESC LIMIT 10"
        );

        Response::view('layouts/main', [
            'pageTitle' => 'AI Provider Settings & Budgeting',
            'content' => view('ai/settings', [
                'providers' => $providers,
                'monthlyUsage' => $monthlyUsage,
                'recentLogs' => $recentLogs,
            ]),
        ]);
    }

    /**
     * API: List AI Providers
     */
    public function providersApi(): void
    {
        $providers = AIProvider::all();
        foreach ($providers as &$p) {
            $p['has_api_key'] = !empty($p['api_key_encrypted']);
            unset($p['api_key_encrypted']);
            $p['models'] = AIModel::getByProvider((int) $p['id']);
        }

        Response::json(['success' => true, 'data' => $providers]);
    }

    /**
     * API: Update AI Provider Settings (AES-256-GCM Encryption)
     */
    public function updateProviderApi(int|array $id): void
    {
        $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;
        $input = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;

        try {
            $updated = AIProvider::updateSettings($id, $input, $this->encryption);
            if (!$updated) {
                Response::json(['success' => false, 'error' => 'Provider tidak ditemukan.'], 404);
                return;
            }

            if (!empty($input['default_model'])) {
                AIModel::setDefault($id, (string) $input['default_model']);
            }

            Response::json(['success' => true, 'message' => 'Pengaturan provider AI berhasil diperbarui.']);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * API: Test Connectivity to an AI Provider
     */
    public function testProviderApi(string|array $code): void
    {
        $code = is_array($code) ? (string) ($code['code'] ?? '') : (string) $code;

        try {
            $provider = $this->aiManager->getProvider($code);
            if (!$provider->isConfigured()) {
                Response::json([
                    'success' => false,
                    'error' => "API Key untuk provider {$provider->getProviderName()} belum dikonfigurasi.",
                ], 400);
                return;
            }

            $testPrompt = "Uji koneksi sistem AnaBot. Berikan balasan singkat: 'Koneksi berhasil terhubung.'";
            $res = $provider->generateText($testPrompt, ['max_tokens' => 50, 'temperature' => 0.1]);

            if ($res['success']) {
                Response::json([
                    'success' => true,
                    'message' => "Koneksi ke {$provider->getProviderName()} berhasil!",
                    'latency_ms' => $res['execution_time_ms'],
                    'reply' => $res['text'],
                ]);
            } else {
                Response::json([
                    'success' => false,
                    'error' => $res['error'] ?? 'Gagal menghubungi server AI.',
                ], 400);
            }
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * API: Generate AI completion with auto-fallback
     */
    public function generateApi(): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $prompt = $input['prompt'] ?? null;
        $messages = $input['messages'] ?? null;

        if (empty($prompt) && empty($messages)) {
            Response::json(['success' => false, 'error' => 'Parameter prompt atau messages wajib disertakan.'], 400);
            return;
        }

        try {
            $payload = $messages ?: (string) $prompt;
            $options = [
                'model' => $input['model'] ?? null,
                'temperature' => (float) ($input['temperature'] ?? 0.3),
                'max_tokens' => (int) ($input['max_tokens'] ?? 2048),
                'session_id' => isset($input['session_id']) ? (int) $input['session_id'] : null,
            ];

            $result = $this->aiManager->generateWithFallback($payload, $options);
            Response::json($result);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * API: Get AI Token Usage Logs & Aggregate Stats
     */
    public function usageApi(): void
    {
        $startOfMonth = date('Y-m-01 00:00:00');
        $aggregate = $this->db->queryOne(
            "SELECT COUNT(*) as total_requests,
                    SUM(total_tokens) as total_tokens,
                    SUM(estimated_cost_cents) as total_cost_cents
             FROM ai_usage_logs 
             WHERE status = 'success' AND created_at >= :start",
            [':start' => $startOfMonth]
        );

        $logs = $this->db->queryAll(
            "SELECT l.*, p.name as provider_name, p.provider_code 
             FROM ai_usage_logs l
             JOIN ai_providers p ON l.provider_id = p.id
             ORDER BY l.id DESC LIMIT 50"
        );

        Response::json([
            'success' => true,
            'data' => [
                'current_month' => [
                    'total_requests' => (int) ($aggregate['total_requests'] ?? 0),
                    'total_tokens' => (int) ($aggregate['total_tokens'] ?? 0),
                    'total_cost_usd' => round(((float) ($aggregate['total_cost_cents'] ?? 0)) / 100.0, 4),
                ],
                'recent_logs' => $logs,
            ],
        ]);
    }
}
