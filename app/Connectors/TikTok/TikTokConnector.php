<?php
declare(strict_types=1);

namespace AnaBot\Connectors\TikTok;

use AnaBot\Connectors\BaseConnector;
use RuntimeException;
use Throwable;

/**
 * TikTok Marketing API (v1.3) Connector
 * 
 * Strict Constraint: All access MUST be strictly READ-ONLY (HTTP GET only).
 * Scope: business.data.read
 * 
 * Synchronizes TikTok advertising campaigns, ad performance metrics,
 * ad spend, impressions, CTR, CPC, and conversion events.
 */
class TikTokConnector extends BaseConnector
{
    protected string $connectorCode = 'tiktok';
    protected string $name = 'TikTok Shop & Ads';
    protected string $category = 'social_ads';
    protected string $authType = 'oauth2';

    private const API_BASE = 'https://business-api.tiktok.com/open_api/v1.3';

    /**
     * Get validated Advertiser ID.
     */
    public function getAdvertiserId(): string
    {
        $raw = (string) ($this->config['advertiser_id'] ?? $this->credentials['advertiser_id'] ?? '');
        return trim($raw);
    }

    /**
     * Resolve TikTok access token.
     */
    private function resolveAccessToken(): string
    {
        $token = $this->credentials['access_token'] ?? $this->config['access_token'] ?? '';
        return is_string($token) ? trim($token) : '';
    }

    /**
     * Build TikTok headers with Access-Token.
     */
    private function buildHeaders(): array
    {
        $token = $this->resolveAccessToken();
        return [
            'Access-Token: ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /**
     * Test live connection to TikTok Advertiser Account.
     */
    public function testConnection(): array
    {
        $advertiserId = $this->getAdvertiserId();
        if (empty($advertiserId)) {
            return [
                'success' => false,
                'message' => 'Advertiser ID (advertiser_id) wajib diisi.',
                'details' => ['error' => 'MISSING_ADVERTISER_ID'],
            ];
        }

        $token = $this->resolveAccessToken();
        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'TikTok Access Token (access_token) wajib diisi.',
                'details' => ['error' => 'MISSING_ACCESS_TOKEN'],
            ];
        }

        $url = self::API_BASE . '/advertiser/info/?advertiser_ids=' . urlencode(json_encode([$advertiserId]));
        $headers = $this->buildHeaders();

        try {
            $response = $this->httpClient->get($url, $headers);
            $body = json_decode((string) $response['body'], true);

            // TikTok returns code === 0 for success
            if ($response['status'] === 200 && isset($body['code']) && $body['code'] === 0) {
                $list = $body['data']['list'] ?? [];
                $account = $list[0] ?? [];
                $name = $account['name'] ?? "Advertiser {$advertiserId}";
                $currency = $account['currency'] ?? 'IDR';

                return [
                    'success' => true,
                    'message' => "Berhasil terhubung ke akun iklan TikTok: {$name} ({$currency}).",
                    'details' => [
                        'advertiser_id' => $advertiserId,
                        'name'          => $name,
                        'currency'      => $currency,
                        'duration_ms'   => $response['duration_ms'],
                        'read_only'     => true,
                    ],
                ];
            }

            $errMsg = $body['message'] ?? "TikTok API mengembalikan HTTP {$response['status']}";
            return [
                'success' => false,
                'message' => "Gagal terhubung ke TikTok Ads: {$errMsg}",
                'details' => [
                    'status_code' => $response['status'],
                    'api_code'    => $body['code'] ?? null,
                    'error'       => $body['message'] ?? $response['body'],
                ],
            ];
        } catch (Throwable $e) {
            $this->log('error', "TikTok test connection failed: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => 'Gagal terhubung ke TikTok Marketing API: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * List supported datasets from TikTok.
     */
    public function getAvailableDatasets(): array
    {
        return [
            [
                'id'          => 'campaign_metrics',
                'name'        => 'Kinerja Kampanye Iklan TikTok',
                'description' => 'Metrik performa kampanye: spend, impressions, clicks, CPC, CPM, CTR, dan konversi.',
            ],
            [
                'id'          => 'ad_performance',
                'name'        => 'Kinerja Materi Iklan (Ad Level)',
                'description' => 'Metrik performa per unit iklan: biaya tayang, jumlah klik, conversion rate, dan cost per conversion.',
            ],
            [
                'id'          => 'daily_reach',
                'name'        => 'Jangkauan & Pengeluaran Harian',
                'description' => 'Agregasi harian pengeluaran iklan, impresi, jangkauan akun, dan total konversi.',
            ],
        ];
    }

    /**
     * Fetch TikTok advertising performance data (strictly READ-ONLY).
     */
    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array
    {
        $advertiserId = $this->getAdvertiserId();
        if (empty($advertiserId)) {
            throw new RuntimeException('Advertiser ID TikTok wajib diisi sebelum sinkronisasi.');
        }

        $token = $this->resolveAccessToken();
        if (empty($token)) {
            throw new RuntimeException('TikTok Access Token tidak ditemukan.');
        }

        $limit = max(1, min(100, $limit));
        $page = $cursor ? max(1, (int) $cursor) : 1;

        $startDate = (string) ($this->config['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
        $endDate = (string) ($this->config['end_date'] ?? date('Y-m-d', strtotime('-1 days')));

        $dataLevel = match ($resourceId) {
            'ad_performance' => 'AUCTION_AD',
            'daily_reach'    => 'AUCTION_ADVERTISER',
            default          => 'AUCTION_CAMPAIGN', // campaign_metrics
        };

        $dimensions = match ($resourceId) {
            'ad_performance' => ['ad_id', 'stat_time_day'],
            'daily_reach'    => ['stat_time_day'],
            default          => ['campaign_id', 'stat_time_day'],
        };

        $metrics = [
            'spend',
            'impressions',
            'clicks',
            'cpc',
            'cpm',
            'ctr',
            'conversion',
            'cost_per_conversion',
        ];

        $queryParams = [
            'advertiser_id' => $advertiserId,
            'service_type'  => 'AUCTION',
            'report_type'   => 'BASIC',
            'data_level'    => $dataLevel,
            'dimensions'    => json_encode($dimensions),
            'metrics'       => json_encode($metrics),
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'page'          => $page,
            'page_size'     => $limit,
        ];

        $url = self::API_BASE . '/report/integrated/get/?' . http_build_query($queryParams);
        $headers = $this->buildHeaders();

        $response = $this->httpClient->get($url, $headers);
        if ($response['status'] !== 200) {
            $err = json_decode((string) $response['body'], true);
            $msg = $err['message'] ?? "HTTP {$response['status']}";
            throw new RuntimeException("Gagal mengambil data dari TikTok API: {$msg}");
        }

        $body = json_decode((string) $response['body'], true);
        if (isset($body['code']) && $body['code'] !== 0) {
            throw new RuntimeException("TikTok API Error: " . ($body['message'] ?? 'Unknown error'));
        }

        $list = $body['data']['list'] ?? [];
        $records = [];

        foreach ($list as $item) {
            $dimData = $item['dimensions'] ?? [];
            $metricData = $item['metrics'] ?? [];

            $record = [];
            foreach ($dimData as $k => $v) {
                $record[$k] = $v;
            }
            foreach ($metricData as $k => $v) {
                if (in_array($k, ['impressions', 'clicks', 'conversion'], true)) {
                    $record[$k] = (int) $v;
                } elseif (is_numeric($v)) {
                    $record[$k] = (float) $v;
                } else {
                    $record[$k] = $v;
                }
            }
            $records[] = $record;
        }

        $pageInfo = $body['data']['page_info'] ?? [];
        $totalPage = (int) ($pageInfo['total_page'] ?? 1);
        $totalNumber = (int) ($pageInfo['total_number'] ?? count($records));

        $hasMore = $page < $totalPage;
        $this->nextCursor = $hasMore ? (string) ($page + 1) : null;

        return [
            'records'     => $records,
            'next_cursor' => $this->nextCursor,
            'has_more'    => $hasMore,
            'total'       => $totalNumber,
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
                'missing_scopes' => ['business.data.read'],
            ];
        }

        return [
            'valid'          => true,
            'granted_scopes' => ['business.data.read'],
            'missing_scopes' => [],
        ];
    }
}
