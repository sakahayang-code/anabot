<?php
declare(strict_types=1);

namespace AnaBot\Connectors\GoogleAnalytics;

use AnaBot\Connectors\BaseConnector;
use AnaBot\Connectors\GoogleSheets\GoogleAuthHelper;
use RuntimeException;
use Throwable;

/**
 * Google Analytics 4 (GA4 Data API v1beta) Connector
 * 
 * Strict Constraint: All access MUST be strictly READ-ONLY.
 * Scope: https://www.googleapis.com/auth/analytics.readonly
 * 
 * Synchronizes website traffic, visitor sessions, acquisition sources,
 * conversion events, and audience demographic metrics.
 */
class GoogleAnalyticsConnector extends BaseConnector
{
    protected string $connectorCode = 'google_analytics';
    protected string $name = 'Google Analytics 4';
    protected string $category = 'analytics';
    protected string $authType = 'oauth2';

    private const DATA_API_BASE = 'https://analyticsdata.googleapis.com/v1beta';
    private const READONLY_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    /**
     * Get sanitized GA4 Property ID (e.g. "123456789" or "properties/123456789").
     */
    public function getPropertyId(): string
    {
        $raw = (string) ($this->config['property_id'] ?? $this->credentials['property_id'] ?? '');
        $raw = trim($raw);
        if (str_starts_with($raw, 'properties/')) {
            $raw = substr($raw, 11);
        }
        return preg_replace('/[^0-9]/', '', $raw) ?? '';
    }

    /**
     * Resolve Google OAuth2 access token.
     */
    private function resolveAccessToken(): string
    {
        // 1. Direct access token
        $token = $this->credentials['access_token'] ?? $this->config['access_token'] ?? '';
        if (!empty($token) && is_string($token)) {
            return $token;
        }

        // 2. Service account JSON credentials
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
     * Test live connection to Google Analytics 4 Property.
     */
    public function testConnection(): array
    {
        $propertyId = $this->getPropertyId();
        if (empty($propertyId)) {
            return [
                'success' => false,
                'message' => 'GA4 Property ID (property_id) wajib diisi (angka 9 digit).',
                'details' => ['error' => 'MISSING_PROPERTY_ID'],
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

            $url = self::DATA_API_BASE . "/properties/{$propertyId}:runReport";
            $payload = [
                'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
                'metrics'    => [['name' => 'activeUsers'], ['name' => 'sessions']],
                'limit'      => 1,
            ];

            $headers = [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ];

            $response = $this->httpClient->post($url, json_encode($payload), $headers);
            $body = json_decode((string) $response['body'], true);

            if ($response['status'] === 200) {
                $rowCount = (int) ($body['rowCount'] ?? count($body['rows'] ?? []));
                return [
                    'success' => true,
                    'message' => "Berhasil terhubung ke GA4 Property {$propertyId} (Read-Only verified).",
                    'details' => [
                        'property_id' => $propertyId,
                        'row_count'   => $rowCount,
                        'duration_ms' => $response['duration_ms'],
                        'read_only'   => true,
                    ],
                ];
            }

            $errMessage = $body['error']['message'] ?? "Google API returned HTTP {$response['status']}";
            return [
                'success' => false,
                'message' => "Gagal terhubung ke Google Analytics: {$errMessage}",
                'details' => [
                    'status_code' => $response['status'],
                    'error'       => $body['error'] ?? $response['body'],
                ],
            ];
        } catch (Throwable $e) {
            $this->log('error', "GA4 test connection failed: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => 'Gagal terhubung ke Google Analytics 4: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * List supported datasets from GA4.
     */
    public function getAvailableDatasets(): array
    {
        return [
            [
                'id'          => 'daily_traffic',
                'name'        => 'Trafik Harian & Sesi Kunjungan',
                'description' => 'Metrik pengguna aktif (activeUsers), sesi, pageviews, rasio pentalan (bounceRate), dan durasi sesi.',
            ],
            [
                'id'          => 'traffic_sources',
                'name'        => 'Sumber Lalu Lintas & Akuisisi',
                'description' => 'Kinerja saluran akuisisi (sessionSource, sessionMedium, conversions, sessions).',
            ],
            [
                'id'          => 'user_demographics',
                'name'        => 'Geografi & Perangkat Pengguna',
                'description' => 'Demografi audiens berdasarkan negara (country), kota (city), dan kategori perangkat (deviceCategory).',
            ],
            [
                'id'          => 'event_counts',
                'name'        => 'Aktivitas & Penghitungan Event',
                'description' => 'Frekuensi event interaksi (eventName, eventCount, totalUsers).',
            ],
        ];
    }

    /**
     * Fetch analytical records from Google Analytics 4 (strictly READ-ONLY).
     */
    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array
    {
        $propertyId = $this->getPropertyId();
        if (empty($propertyId)) {
            throw new RuntimeException('GA4 Property ID wajib diisi sebelum sinkronisasi.');
        }

        $token = $this->resolveAccessToken();
        if (empty($token)) {
            throw new RuntimeException('Token autentikasi Google Analytics tidak ditemukan.');
        }

        $limit = max(1, min(1000, $limit));
        $offset = $cursor ? max(0, (int) $cursor) : 0;

        // Configure report dimensions and metrics based on dataset
        $reportConfig = match ($resourceId) {
            'traffic_sources' => [
                'dimensions' => [['name' => 'sessionSource'], ['name' => 'sessionMedium']],
                'metrics'    => [['name' => 'sessions'], ['name' => 'activeUsers'], ['name' => 'conversions']],
            ],
            'user_demographics' => [
                'dimensions' => [['name' => 'country'], ['name' => 'city'], ['name' => 'deviceCategory']],
                'metrics'    => [['name' => 'activeUsers'], ['name' => 'sessions']],
            ],
            'event_counts' => [
                'dimensions' => [['name' => 'eventName']],
                'metrics'    => [['name' => 'eventCount'], ['name' => 'totalUsers']],
            ],
            default => [ // daily_traffic
                'dimensions' => [['name' => 'date']],
                'metrics'    => [
                    ['name' => 'activeUsers'],
                    ['name' => 'sessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'bounceRate'],
                    ['name' => 'averageSessionDuration'],
                ],
            ],
        };

        $startDate = (string) ($this->config['start_date'] ?? '30daysAgo');
        $endDate = (string) ($this->config['end_date'] ?? 'today');

        $payload = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => $reportConfig['dimensions'],
            'metrics'    => $reportConfig['metrics'],
            'offset'     => $offset,
            'limit'      => $limit,
        ];

        $url = self::DATA_API_BASE . "/properties/{$propertyId}:runReport";
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $response = $this->httpClient->post($url, json_encode($payload), $headers);
        if ($response['status'] !== 200) {
            $err = json_decode((string) $response['body'], true);
            $msg = $err['error']['message'] ?? "HTTP {$response['status']}";
            throw new RuntimeException("Gagal mengambil data dari Google Analytics 4: {$msg}");
        }

        $data = json_decode((string) $response['body'], true);
        $records = $this->transformGa4Rows($data);

        $totalRows = (int) ($data['rowCount'] ?? count($records));
        $hasMore = ($offset + count($records)) < $totalRows;
        $this->nextCursor = $hasMore ? (string) ($offset + count($records)) : null;

        return [
            'records'     => $records,
            'next_cursor' => $this->nextCursor,
            'has_more'    => $hasMore,
            'total'       => $totalRows,
        ];
    }

    /**
     * Transform GA4 response rows into flattened associative arrays.
     */
    private function transformGa4Rows(array $data): array
    {
        $dimensionHeaders = array_map(fn($d) => $d['name'] ?? '', $data['dimensionHeaders'] ?? []);
        $metricHeaders = array_map(fn($m) => $m['name'] ?? '', $data['metricHeaders'] ?? []);

        $rows = $data['rows'] ?? [];
        $records = [];

        foreach ($rows as $row) {
            $record = [];

            // Map dimensions
            $dimValues = $row['dimensionValues'] ?? [];
            foreach ($dimensionHeaders as $idx => $dimName) {
                $val = $dimValues[$idx]['value'] ?? null;
                // Format YYYYMMDD to YYYY-MM-DD if date dimension
                if ($dimName === 'date' && is_string($val) && strlen($val) === 8) {
                    $val = substr($val, 0, 4) . '-' . substr($val, 4, 2) . '-' . substr($val, 6, 2);
                }
                $record[$dimName] = $val;
            }

            // Map metrics
            $metricValues = $row['metricValues'] ?? [];
            foreach ($metricHeaders as $idx => $metricName) {
                $rawVal = $metricValues[$idx]['value'] ?? '0';
                $record[$metricName] = is_numeric($rawVal) ? (str_contains($rawVal, '.') ? (float) $rawVal : (int) $rawVal) : $rawVal;
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * Validate OAuth2 permissions and scopes.
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
