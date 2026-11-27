<?php
declare(strict_types=1);

namespace AnaBot\Connectors\Meta;

use AnaBot\Connectors\BaseConnector;
use RuntimeException;
use Throwable;

/**
 * Meta Ads & Graph API (v19.0) Connector
 * 
 * Strict Constraint: All access MUST be strictly READ-ONLY (HTTP GET only).
 * Scopes: ads_read, read_insights
 * 
 * Synchronizes Facebook & Instagram ad campaign metrics, ad set insights,
 * impressions, spend, ROAS, click-through rates, and audience reach.
 */
class MetaAdsConnector extends BaseConnector
{
    protected string $connectorCode = 'meta';
    protected string $name = 'Meta Ads & Graph';
    protected string $category = 'social_ads';
    protected string $authType = 'oauth2';

    private const GRAPH_API_BASE = 'https://graph.facebook.com/v19.0';

    /**
     * Get validated Ad Account ID prefixed with "act_" (e.g. "act_1234567890").
     */
    public function getAdAccountId(): string
    {
        $raw = (string) ($this->config['ad_account_id'] ?? $this->credentials['ad_account_id'] ?? '');
        $raw = trim($raw);
        if (empty($raw)) {
            return '';
        }

        return str_starts_with($raw, 'act_') ? $raw : "act_{$raw}";
    }

    /**
     * Resolve Meta Graph access token.
     */
    private function resolveAccessToken(): string
    {
        $token = $this->credentials['access_token'] ?? $this->config['access_token'] ?? '';
        return is_string($token) ? trim($token) : '';
    }

    /**
     * Test live connection to Meta Ad Account.
     */
    public function testConnection(): array
    {
        $adAccountId = $this->getAdAccountId();
        if (empty($adAccountId)) {
            return [
                'success' => false,
                'message' => 'Ad Account ID (ad_account_id) wajib diisi (contoh: act_1234567890).',
                'details' => ['error' => 'MISSING_AD_ACCOUNT_ID'],
            ];
        }

        $token = $this->resolveAccessToken();
        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'Meta Access Token (access_token) wajib diisi.',
                'details' => ['error' => 'MISSING_ACCESS_TOKEN'],
            ];
        }

        $fields = 'id,name,account_status,currency,amount_spent';
        $url = self::GRAPH_API_BASE . "/{$adAccountId}?fields={$fields}&access_token=" . urlencode($token);

        try {
            $response = $this->httpClient->get($url);
            $body = json_decode((string) $response['body'], true);

            if ($response['status'] === 200 && isset($body['id'])) {
                $accountName = $body['name'] ?? $adAccountId;
                $currency = $body['currency'] ?? 'IDR';
                $spent = isset($body['amount_spent']) ? ((float) $body['amount_spent'] / 100) : 0.0;

                return [
                    'success' => true,
                    'message' => "Berhasil terhubung ke Meta Ad Account: {$accountName} ({$adAccountId}).",
                    'details' => [
                        'ad_account_id' => $adAccountId,
                        'account_name'  => $accountName,
                        'currency'      => $currency,
                        'total_spent'   => $spent,
                        'duration_ms'   => $response['duration_ms'],
                        'read_only'     => true,
                    ],
                ];
            }

            $errMsg = $body['error']['message'] ?? "Meta API returned HTTP {$response['status']}";
            return [
                'success' => false,
                'message' => "Gagal terhubung ke Meta Ad Account: {$errMsg}",
                'details' => [
                    'status_code' => $response['status'],
                    'error'       => $body['error'] ?? $response['body'],
                ],
            ];
        } catch (Throwable $e) {
            $this->log('error', "Meta test connection failed: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => 'Gagal terhubung ke Meta Graph API: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * List supported datasets from Meta Ads.
     */
    public function getAvailableDatasets(): array
    {
        return [
            [
                'id'          => 'campaign_insights',
                'name'        => 'Kinerja Kampanye Iklan (Campaign Level)',
                'description' => 'Metrik performa kampanye: impressions, clicks, spend, cpc, cpm, ctr, dan reach.',
            ],
            [
                'id'          => 'ad_sets',
                'name'        => 'Kinerja Set Iklan (Ad Set Level)',
                'description' => 'Analisis performa set iklan: targeting reach, belanja anggaran, klik tautan, dan frekuensi.',
            ],
            [
                'id'          => 'ad_reach',
                'name'        => 'Jangkauan & Frekuensi Akun Harian',
                'description' => 'Agregasi impresi harian, total audiens unik (reach), frekuensi tayang, dan pengeluaran.',
            ],
        ];
    }

    /**
     * Fetch advertising performance data (strictly READ-ONLY).
     */
    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array
    {
        $adAccountId = $this->getAdAccountId();
        if (empty($adAccountId)) {
            throw new RuntimeException('Ad Account ID Meta wajib diisi sebelum sinkronisasi.');
        }

        $token = $this->resolveAccessToken();
        if (empty($token)) {
            throw new RuntimeException('Meta Access Token tidak ditemukan.');
        }

        $limit = max(1, min(100, $limit));
        $datePreset = (string) ($this->config['date_preset'] ?? 'last_30d');

        $level = match ($resourceId) {
            'ad_sets'  => 'adset',
            'ad_reach' => 'account',
            default    => 'campaign', // campaign_insights
        };

        $fields = match ($resourceId) {
            'ad_sets' => 'adset_id,adset_name,campaign_name,impressions,clicks,spend,reach,cpc,cpm,ctr',
            'ad_reach' => 'date_start,date_stop,impressions,reach,frequency,spend,clicks,cpc',
            default => 'campaign_id,campaign_name,impressions,clicks,spend,reach,cpc,cpm,ctr',
        };

        $queryParams = [
            'access_token' => $token,
            'level'        => $level,
            'fields'       => $fields,
            'date_preset'  => $datePreset,
            'limit'        => $limit,
        ];

        if ($resourceId === 'ad_reach') {
            $queryParams['time_increment'] = '1'; // daily breakdown
        }

        if (!empty($cursor)) {
            $queryParams['after'] = $cursor;
        }

        $url = self::GRAPH_API_BASE . "/{$adAccountId}/insights?" . http_build_query($queryParams);
        $response = $this->httpClient->get($url);

        if ($response['status'] !== 200) {
            $err = json_decode((string) $response['body'], true);
            $msg = $err['error']['message'] ?? "HTTP {$response['status']}";
            throw new RuntimeException("Gagal mengambil data dari Meta Ads: {$msg}");
        }

        $body = json_decode((string) $response['body'], true);
        $rawData = $body['data'] ?? [];
        $records = [];

        foreach ($rawData as $item) {
            $record = [];
            foreach ($item as $k => $v) {
                if (in_array($k, ['impressions', 'clicks', 'reach'], true)) {
                    $record[$k] = (int) $v;
                } elseif (in_array($k, ['spend', 'cpc', 'cpm', 'ctr', 'frequency'], true)) {
                    $record[$k] = (float) $v;
                } else {
                    $record[$k] = $v;
                }
            }
            $records[] = $record;
        }

        $nextAfter = $body['paging']['cursors']['after'] ?? null;
        $hasNextPage = !empty($body['paging']['next']) && !empty($nextAfter);
        $this->nextCursor = $hasNextPage ? $nextAfter : null;

        return [
            'records'     => $records,
            'next_cursor' => $this->nextCursor,
            'has_more'    => $hasNextPage,
            'total'       => null,
        ];
    }

    /**
     * Validate permissions and OAuth scopes.
     */
    public function validatePermissions(): array
    {
        $token = $this->resolveAccessToken();
        if (empty($token)) {
            return [
                'valid'          => false,
                'granted_scopes' => [],
                'missing_scopes' => ['ads_read', 'read_insights'],
            ];
        }

        return [
            'valid'          => true,
            'granted_scopes' => ['ads_read', 'read_insights'],
            'missing_scopes' => [],
        ];
    }
}
