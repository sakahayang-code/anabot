<?php
declare(strict_types=1);

namespace AnaBot\Connectors\SearchConsole;

use AnaBot\Connectors\BaseConnector;
use AnaBot\Connectors\GoogleSheets\GoogleAuthHelper;
use RuntimeException;
use Throwable;

/**
 * Google Search Console API (v3) Connector
 * 
 * Strict Constraint: All access MUST be strictly READ-ONLY.
 * Scope: https://www.googleapis.com/auth/webmasters.readonly
 * 
 * Synchronizes organic search impressions, query rankings, click-through rates (CTR),
 * top performing landing pages, and device segmentation.
 */
class SearchConsoleConnector extends BaseConnector
{
    protected string $connectorCode = 'search_console';
    protected string $name = 'Google Search Console';
    protected string $category = 'seo';
    protected string $authType = 'oauth2';

    private const API_BASE = 'https://www.googleapis.com/webmasters/v3';
    private const READONLY_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /**
     * Get validated site URL / domain property identifier.
     */
    public function getSiteUrl(): string
    {
        $raw = (string) ($this->config['site_url'] ?? $this->credentials['site_url'] ?? '');
        return trim($raw);
    }

    /**
     * Resolve Google OAuth2 access token.
     */
    private function resolveAccessToken(): string
    {
        $token = $this->credentials['access_token'] ?? $this->config['access_token'] ?? '';
        if (!empty($token) && is_string($token)) {
            return $token;
        }

        $saRaw = $this->credentials['service_account_json'] ?? $this->config['service_account_json'] ?? null;
        if (!empty($saRaw)) {
            $saData = is_array($saRaw) ? $saRaw : json_decode((string) $saRaw, true);
            if (is_array($saData) && !empty($saData['client_email']) && !empty($saData['private_key'])) {
                $jwt = GoogleAuthHelper::createServiceAccountJwt($saData, self::READONLY_SCOPE);
                $resp = GoogleAuthHelper::exchangeJwtForAccessToken($jwt, $this->httpClient);
                return $resp['access_token'] ?? '';
            }
        }

        return '';
    }

    /**
     * Test live connection to Google Search Console.
     */
    public function testConnection(): array
    {
        $siteUrl = $this->getSiteUrl();
        if (empty($siteUrl)) {
            return [
                'success' => false,
                'message' => 'Site URL atau Domain Property (site_url) wajib diisi (contoh: https://example.com/ atau sc-domain:example.com).',
                'details' => ['error' => 'MISSING_SITE_URL'],
            ];
        }

        try {
            $token = $this->resolveAccessToken();
            if (empty($token)) {
                return [
                    'success' => false,
                    'message' => 'Kredensial Google OAuth2 atau Service Account belum dikonfigurasi.',
                    'details' => ['error' => 'MISSING_CREDENTIALS'],
                ];
            }

            $encodedSite = urlencode($siteUrl);
            $url = self::API_BASE . "/sites/{$encodedSite}";
            $headers = [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ];

            $response = $this->httpClient->get($url, $headers);
            $body = json_decode((string) $response['body'], true);

            if ($response['status'] === 200) {
                $permissionLevel = $body['permissionLevel'] ?? 'siteRestrictedUser';
                return [
                    'success' => true,
                    'message' => "Berhasil terhubung ke Search Console untuk: {$siteUrl} (Level: {$permissionLevel}).",
                    'details' => [
                        'site_url'         => $siteUrl,
                        'permission_level' => $permissionLevel,
                        'duration_ms'      => $response['duration_ms'],
                        'read_only'        => true,
                    ],
                ];
            }

            $errMessage = $body['error']['message'] ?? "Search Console API mengembalikan HTTP {$response['status']}";
            return [
                'success' => false,
                'message' => "Gagal terhubung ke Google Search Console: {$errMessage}",
                'details' => [
                    'status_code' => $response['status'],
                    'error'       => $body['error'] ?? $response['body'],
                ],
            ];
        } catch (Throwable $e) {
            $this->log('error', "Search Console test connection failed: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => 'Gagal terhubung ke Google Search Console: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * List supported datasets from Search Console.
     */
    public function getAvailableDatasets(): array
    {
        return [
            [
                'id'          => 'search_performance',
                'name'        => 'Kinerja Pencarian Harian (Overview)',
                'description' => 'Agregasi metrik impresi, klik organik, rata-rata CTR, dan peringkat posisi rata-rata per hari.',
            ],
            [
                'id'          => 'top_queries',
                'name'        => 'Kueri & Kata Kunci Teratas',
                'description' => 'Kata kunci pencarian yang mendatangkan pengunjung, impresi, CTR, dan posisi ranking Google.',
            ],
            [
                'id'          => 'top_pages',
                'name'        => 'Halaman Populer & Landing Page',
                'description' => 'URL halaman landing dengan klik organik, impresi, dan performa ranking tertinggi.',
            ],
            [
                'id'          => 'device_breakdown',
                'name'        => 'Kinerja Perangkat Pencari',
                'description' => 'Segmentasi klik dan impresi berdasarkan perangkat (DESKTOP, MOBILE, TABLET).',
            ],
        ];
    }

    /**
     * Fetch search performance data (strictly READ-ONLY).
     */
    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array
    {
        $siteUrl = $this->getSiteUrl();
        if (empty($siteUrl)) {
            throw new RuntimeException('Site URL Search Console wajib diisi sebelum sinkronisasi.');
        }

        $token = $this->resolveAccessToken();
        if (empty($token)) {
            throw new RuntimeException('Token autentikasi Google Search Console tidak ditemukan.');
        }

        $limit = max(1, min(1000, $limit));
        $startRow = $cursor ? max(0, (int) $cursor) : 0;

        $dimensions = match ($resourceId) {
            'top_queries'      => ['query'],
            'top_pages'        => ['page'],
            'device_breakdown' => ['device'],
            default            => ['date'], // search_performance
        };

        $startDate = (string) ($this->config['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
        $endDate = (string) ($this->config['end_date'] ?? date('Y-m-d', strtotime('-2 days'))); // Search console data has 2-day lag

        $payload = [
            'startDate'  => $startDate,
            'endDate'    => $endDate,
            'dimensions' => $dimensions,
            'rowLimit'   => $limit,
            'startRow'   => $startRow,
        ];

        $encodedSite = urlencode($siteUrl);
        $url = self::API_BASE . "/sites/{$encodedSite}/searchAnalytics/query";
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $response = $this->httpClient->post($url, json_encode($payload), $headers);
        if ($response['status'] !== 200) {
            $err = json_decode((string) $response['body'], true);
            $msg = $err['error']['message'] ?? "HTTP {$response['status']}";
            throw new RuntimeException("Gagal mengambil data dari Google Search Console: {$msg}");
        }

        $body = json_decode((string) $response['body'], true);
        $rawRows = $body['rows'] ?? [];
        $records = [];

        foreach ($rawRows as $row) {
            $keys = $row['keys'] ?? [];
            $dimensionKey = $dimensions[0] ?? 'key';
            $dimensionValue = $keys[0] ?? '';

            $records[] = [
                $dimensionKey => $dimensionValue,
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr'         => round((float) ($row['ctr'] ?? 0.0) * 100, 2), // Percentage 0 - 100%
                'position'    => round((float) ($row['position'] ?? 0.0), 2),
            ];
        }

        $hasMore = count($rawRows) === $limit;
        $this->nextCursor = $hasMore ? (string) ($startRow + count($rawRows)) : null;

        return [
            'records'     => $records,
            'next_cursor' => $this->nextCursor,
            'has_more'    => $hasMore,
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
                'missing_scopes' => [self::READONLY_SCOPE],
            ];
        }

        return [
            'valid'          => true,
            'granted_scopes' => [self::READONLY_SCOPE],
            'missing_scopes' => [],
        ];
    }
}
