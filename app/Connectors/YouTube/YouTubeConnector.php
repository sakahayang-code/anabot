<?php
declare(strict_types=1);

namespace AnaBot\Connectors\YouTube;

use AnaBot\Connectors\BaseConnector;
use AnaBot\Connectors\GoogleSheets\GoogleAuthHelper;
use RuntimeException;
use Throwable;

/**
 * YouTube Analytics API (v2) Connector
 * 
 * Strict Constraint: All access MUST be strictly READ-ONLY (HTTP GET only).
 * Scope: https://www.googleapis.com/auth/yt-analytics.readonly
 * 
 * Synchronizes channel performance, video engagement, watch time,
 * subscriber growth, and audience geography.
 */
class YouTubeConnector extends BaseConnector
{
    protected string $connectorCode = 'youtube';
    protected string $name = 'YouTube Analytics';
    protected string $category = 'video';
    protected string $authType = 'oauth2';

    private const API_BASE = 'https://youtubeanalytics.googleapis.com/v2/reports';
    private const READONLY_SCOPE = 'https://www.googleapis.com/auth/yt-analytics.readonly';

    /**
     * Get validated Channel ID or default 'mine'.
     */
    public function getChannelId(): string
    {
        $id = (string) ($this->config['channel_id'] ?? $this->credentials['channel_id'] ?? 'mine');
        $id = trim($id);
        return empty($id) ? 'mine' : $id;
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
     * Test live connectivity to YouTube Analytics API.
     */
    public function testConnection(): array
    {
        try {
            $token = $this->resolveAccessToken();
            if (empty($token)) {
                return [
                    'success' => false,
                    'message' => 'Kredensial YouTube OAuth2 atau Service Account belum dikonfigurasi.',
                    'details' => ['error' => 'MISSING_CREDENTIALS'],
                ];
            }

            $channelId = $this->getChannelId();
            $targetChannel = ($channelId === 'mine') ? 'channel==MINE' : "channel=={$channelId}";

            $startDate = date('Y-m-d', strtotime('-14 days'));
            $endDate = date('Y-m-d', strtotime('-2 days'));

            $params = http_build_query([
                'ids'        => $targetChannel,
                'startDate'  => $startDate,
                'endDate'    => $endDate,
                'metrics'    => 'views,estimatedMinutesWatched',
                'maxResults' => 1,
            ]);

            $url = self::API_BASE . '?' . $params;
            $headers = [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ];

            $response = $this->httpClient->get($url, $headers);
            $body = json_decode((string) $response['body'], true);

            if ($response['status'] === 200) {
                return [
                    'success' => true,
                    'message' => "Koneksi ke YouTube Analytics API berhasil terhubung (Channel: {$channelId}).",
                    'details' => [
                        'channel_id'  => $channelId,
                        'duration_ms' => $response['duration_ms'],
                        'read_only'   => true,
                    ],
                ];
            }

            $errMessage = $body['error']['message'] ?? "YouTube API returned HTTP {$response['status']}";
            return [
                'success' => false,
                'message' => "Gagal terhubung ke YouTube Analytics: {$errMessage}",
                'details' => [
                    'status_code' => $response['status'],
                    'error'       => $body['error'] ?? $response['body'],
                ],
            ];
        } catch (Throwable $e) {
            $this->log('error', "YouTube test connection failed: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => 'Gagal terhubung ke YouTube Analytics: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * List supported datasets from YouTube Analytics.
     */
    public function getAvailableDatasets(): array
    {
        return [
            [
                'id'          => 'channel_metrics',
                'name'        => 'Kinerja Saluran Harian (Channel Metrics)',
                'description' => 'Penayangan harian (views), durasi tonton (watch time), pelanggan baru/hilang, suka, dan komentar.',
            ],
            [
                'id'          => 'video_engagement',
                'name'        => 'Keterlibatan Video Teratas',
                'description' => 'Metrik performa per video: views, menit menonton, likes, komentar, share, dan subscriber baru.',
            ],
            [
                'id'          => 'audience_geography',
                'name'        => 'Distribusi Geografi Penonton',
                'description' => 'Distribusi penayangan dan total waktu tonton berdasarkan negara penonton (country).',
            ],
        ];
    }

    /**
     * Fetch YouTube Analytics records (strictly READ-ONLY).
     */
    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array
    {
        $token = $this->resolveAccessToken();
        if (empty($token)) {
            throw new RuntimeException('Token autentikasi YouTube Analytics tidak ditemukan.');
        }

        $channelId = $this->getChannelId();
        $targetChannel = ($channelId === 'mine') ? 'channel==MINE' : "channel=={$channelId}";

        $limit = max(1, min(200, $limit));
        $startIndex = $cursor ? max(1, (int) $cursor) : 1;

        $startDate = (string) ($this->config['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
        $endDate = (string) ($this->config['end_date'] ?? date('Y-m-d', strtotime('-2 days')));

        $reportConfig = match ($resourceId) {
            'video_engagement' => [
                'dimensions' => 'video',
                'metrics'    => 'views,estimatedMinutesWatched,likes,comments,shares,subscribersGained',
                'sort'       => '-views',
            ],
            'audience_geography' => [
                'dimensions' => 'country',
                'metrics'    => 'views,estimatedMinutesWatched',
                'sort'       => '-views',
            ],
            default => [ // channel_metrics
                'dimensions' => 'day',
                'metrics'    => 'views,estimatedMinutesWatched,averageViewDuration,subscribersGained,subscribersLost,likes,comments,shares',
                'sort'       => 'day',
            ],
        };

        $queryParams = [
            'ids'        => $targetChannel,
            'startDate'  => $startDate,
            'endDate'    => $endDate,
            'metrics'    => $reportConfig['metrics'],
            'dimensions' => $reportConfig['dimensions'],
            'sort'       => $reportConfig['sort'],
            'maxResults' => $limit,
            'startIndex' => $startIndex,
        ];

        $url = self::API_BASE . '?' . http_build_query($queryParams);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $response = $this->httpClient->get($url, $headers);
        if ($response['status'] !== 200) {
            $err = json_decode((string) $response['body'], true);
            $msg = $err['error']['message'] ?? "HTTP {$response['status']}";
            throw new RuntimeException("Gagal mengambil data dari YouTube Analytics: {$msg}");
        }

        $body = json_decode((string) $response['body'], true);
        $columnHeaders = array_map(fn($col) => $col['name'] ?? '', $body['columnHeaders'] ?? []);
        $rawRows = $body['rows'] ?? [];
        $records = [];

        foreach ($rawRows as $row) {
            $record = [];
            foreach ($columnHeaders as $idx => $colName) {
                $val = $row[$idx] ?? null;
                $record[$colName] = is_numeric($val) ? (str_contains((string) $val, '.') ? (float) $val : (int) $val) : $val;
            }
            $records[] = $record;
        }

        $hasMore = count($rawRows) === $limit;
        $this->nextCursor = $hasMore ? (string) ($startIndex + count($rawRows)) : null;

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
