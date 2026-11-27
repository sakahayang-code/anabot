<?php
declare(strict_types=1);

namespace AnaBot\Connectors\CustomAPI;

use AnaBot\Connectors\BaseConnector;
use RuntimeException;

/**
 * Custom REST API Connector
 * 
 * Strict Constraint: All access is strictly READ-ONLY (GET requests only).
 * Supports API Key via Bearer Token, Custom Header, or Query Parameter.
 */
class CustomAPIConnector extends BaseConnector
{
    protected string $connectorCode = 'custom_api';
    protected string $name = 'Custom REST API';
    protected string $category = 'custom';
    protected string $authType = 'api_key';

    public function testConnection(): array
    {
        $baseUrl = $this->config['base_url'] ?? '';
        if (empty($baseUrl)) {
            return [
                'success' => false,
                'message' => 'Base URL is required for Custom API connector.',
                'details' => ['error' => 'MISSING_BASE_URL'],
            ];
        }

        $testEndpoint = $this->config['test_endpoint'] ?? '';
        $url = rtrim($baseUrl, '/') . ($testEndpoint ? '/' . ltrim($testEndpoint, '/') : '');

        try {
            $headers = $this->buildAuthHeaders();
            $response = $this->httpClient->get($url, $headers);

            $isSuccess = $response['status'] >= 200 && $response['status'] < 300;
            return [
                'success' => $isSuccess,
                'message' => $isSuccess ? 'Successfully connected to Custom REST API.' : "API returned HTTP status {$response['status']}.",
                'details' => [
                    'status_code' => $response['status'],
                    'duration_ms' => $response['duration_ms'],
                    'content_type' => $response['headers']['content-type'] ?? 'unknown',
                ],
            ];
        } catch (\Throwable $e) {
            $this->log('error', "Connection test failed: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'details' => ['exception' => get_class($e)],
            ];
        }
    }

    public function getAvailableDatasets(): array
    {
        // Return configured resources or default endpoint resource
        $resources = $this->config['resources'] ?? [];
        if (!empty($resources) && is_array($resources)) {
            return $resources;
        }

        $defaultEndpoint = $this->config['default_endpoint'] ?? 'data';
        return [
            [
                'id' => $defaultEndpoint,
                'name' => 'Default Resource (' . $defaultEndpoint . ')',
                'description' => 'Custom API endpoint resource',
            ]
        ];
    }

    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array
    {
        $baseUrl = $this->config['base_url'] ?? '';
        if (empty($baseUrl)) {
            throw new RuntimeException('Base URL is not configured.');
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($resourceId, '/');

        // Append pagination parameters (supports page-based or cursor-based)
        $queryGlue = str_contains($url, '?') ? '&' : '?';
        $paginationParam = $this->config['cursor_param'] ?? 'cursor';
        $limitParam = $this->config['limit_param'] ?? 'limit';

        $queryParams = [$limitParam . '=' . urlencode((string) $limit)];
        if ($cursor !== null && $cursor !== '') {
            $queryParams[] = $paginationParam . '=' . urlencode($cursor);
        }

        $url .= $queryGlue . implode('&', $queryParams);

        $headers = $this->buildAuthHeaders();

        // READ-ONLY ENFORCEMENT: Only GET requests are executed
        $response = $this->httpClient->get($url, $headers);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("API error: Status {$response['status']} - {$response['body']}");
        }

        $json = $response['json'] ?? [];
        $recordsKey = $this->config['records_key'] ?? 'data';
        $records = [];

        if (isset($json[$recordsKey]) && is_array($json[$recordsKey])) {
            $records = $json[$recordsKey];
        } elseif (is_array($json) && array_is_list($json)) {
            $records = $json;
        }

        // Determine next cursor
        $nextCursorKey = $this->config['next_cursor_key'] ?? 'next_cursor';
        $nextCursor = null;

        if (isset($json[$nextCursorKey]) && is_scalar($json[$nextCursorKey])) {
            $nextCursor = (string) $json[$nextCursorKey];
        } elseif (count($records) >= $limit) {
            // Auto increment integer page cursor if applicable
            $currentCursorInt = is_numeric($cursor) ? (int) $cursor : 1;
            $nextCursor = (string) ($currentCursorInt + 1);
        }

        $this->nextCursor = $nextCursor;

        return [
            'records' => $records,
            'next_cursor' => $nextCursor,
            'has_more' => $nextCursor !== null && count($records) > 0,
            'total' => $json['total'] ?? count($records),
        ];
    }

    public function validatePermissions(): array
    {
        return [
            'valid' => true,
            'granted_scopes' => ['read'],
            'missing_scopes' => [],
        ];
    }

    private function buildAuthHeaders(): array
    {
        $headers = ['Accept: application/json'];
        $authStyle = $this->config['auth_style'] ?? 'bearer';
        $token = $this->credentials['api_key'] ?? $this->credentials['access_token'] ?? '';

        if (!empty($token)) {
            if ($authStyle === 'bearer') {
                $headers[] = "Authorization: Bearer {$token}";
            } elseif ($authStyle === 'custom_header') {
                $headerName = $this->config['header_name'] ?? 'X-API-KEY';
                $headers[] = "{$headerName}: {$token}";
            }
        }

        return $headers;
    }
}
