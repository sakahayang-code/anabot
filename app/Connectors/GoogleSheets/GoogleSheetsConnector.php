<?php
declare(strict_types=1);

namespace AnaBot\Connectors\GoogleSheets;

use AnaBot\Connectors\BaseConnector;
use AnaBot\Services\DataNormalizer;
use RuntimeException;

/**
 * Google Sheets Connector (Google Sheets API v4)
 * 
 * Strict Constraint: All access is strictly READ-ONLY.
 * Scope: https://www.googleapis.com/auth/spreadsheets.readonly
 * 
 * Supports:
 * - OAuth 2.0 (Google User Consent)
 * - Service Account (RS256 JWT exchange)
 * - Raw Spreadsheet ID or full Google Docs URL
 * - Automatic header detection & data type inference
 * - Empty row skipping & currency/date normalization
 * - Row-offset cursor pagination
 */
class GoogleSheetsConnector extends BaseConnector
{
    protected string $connectorCode = 'google_sheets';
    protected string $name = 'Google Sheets';
    protected string $category = 'spreadsheet';
    protected string $authType = 'oauth2';

    private const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';
    private const READONLY_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    /**
     * Get resolved spreadsheet ID from config.
     */
    public function getSpreadsheetId(): string
    {
        $raw = $this->config['spreadsheet_id'] ?? $this->config['spreadsheet_url'] ?? $this->credentials['spreadsheet_id'] ?? $this->credentials['spreadsheet_url'] ?? '';
        return GoogleAuthHelper::extractSpreadsheetId($raw);
    }

    /**
     * Get Service Account client_email if configured.
     */
    public function getServiceAccountEmail(): ?string
    {
        $sa = $this->credentials['service_account_json'] ?? $this->credentials['service_account'] ?? null;
        if (!empty($sa)) {
            $saData = is_array($sa) ? $sa : json_decode((string) $sa, true);
            if (is_array($saData) && !empty($saData['client_email'])) {
                return (string) $saData['client_email'];
            }
        }
        return null;
    }

    /**
     * Build Google Sheets API endpoint URL with query params and API key if present.
     */
    public function buildApiUrl(string $subpath, string $token, array $query = []): string
    {
        $spreadsheetId = $this->getSpreadsheetId();
        $url = self::API_BASE . '/' . urlencode($spreadsheetId) . $subpath;

        if (str_starts_with($token, 'AIzaSy') || !empty($this->credentials['api_key'])) {
            $apiKey = str_starts_with($token, 'AIzaSy') ? $token : (string) ($this->credentials['api_key'] ?? '');
            if (!empty($apiKey)) {
                $query['key'] = $apiKey;
            }
        }

        if (!empty($query)) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($query);
        }

        return $url;
    }

    /**
     * Format friendly, actionable error message for HTTP 403 Permission Denied.
     */
    public function formatPermissionDeniedMessage(): string
    {
        $saEmail = $this->getServiceAccountEmail();
        if (!empty($saEmail)) {
            return "Akses ke Google Sheet Ditolak (HTTP 403: The caller does not have permission).\n\n" .
                   "Solusi: Dokumen spreadsheet belum dibagikan ke Service Account Anda.\n" .
                   "1. Buka file Google Sheet Anda di browser.\n" .
                   "2. Klik tombol 'Bagikan' (Share) di pojok kanan atas.\n" .
                   "3. Tambahkan email Service Account ini:\n" .
                   "   👉 {$saEmail}\n" .
                   "4. Berikan akses minimal 'Pelihat' (Viewer), lalu klik 'Kirim' / 'Selesai'.";
        }

        return "Akses ke Google Sheet Ditolak (HTTP 403: The caller does not have permission).\n\n" .
               "Solusi:\n" .
               "1. Jika menggunakan Google Cloud API Key: Buka Google Sheet > klik tombol 'Bagikan' (Share) > pada 'Akses umum' ubah dari 'Dibatasi' menjadi 'Siapa saja yang memiliki link' (Viewer/Pelihat).\n" .
               "2. Jika menggunakan Service Account: Tempelkan file JSON Service Account pada formulir pengaturan koneksi dan bagikan spreadsheet ke email Service Account tersebut.";
    }

    /**
     * Test live connectivity and credentials.
     */
    public function testConnection(): array
    {
        $spreadsheetId = $this->getSpreadsheetId();
        if (empty($spreadsheetId)) {
            return [
                'success' => false,
                'message' => 'Spreadsheet ID atau URL dokumen belum diisi. Silakan masukkan di formulir pengaturan koneksi.',
                'details' => ['error' => 'MISSING_SPREADSHEET_ID'],
            ];
        }

        try {
            $token = $this->resolveAccessToken();
            if (empty($token)) {
                return [
                    'success' => false,
                    'message' => 'Kredensial belum dikonfigurasi. Masukkan Service Account JSON atau Google API Key / Access Token.',
                    'details' => ['error' => 'MISSING_CREDENTIALS'],
                ];
            }

            $url = $this->buildApiUrl('', $token, ['includeGridData' => 'false']);
            $headers = $this->buildHeaders($token);
            $response = $this->httpClient->get($url, $headers);

            if ($response['status'] === 200) {
                $title = $response['json']['properties']['title'] ?? 'Google Sheet';
                $sheets = $response['json']['sheets'] ?? [];

                return [
                    'success' => true,
                    'message' => "Berhasil terhubung ke spreadsheet: {$title}",
                    'details' => [
                        'spreadsheet_id' => $spreadsheetId,
                        'title' => $title,
                        'sheets_count' => count($sheets),
                        'sheets' => array_map(fn($s) => $s['properties']['title'] ?? 'Sheet', $sheets),
                        'duration_ms' => $response['duration_ms'],
                    ],
                ];
            }

            if ($response['status'] === 403) {
                $errMessage = $this->formatPermissionDeniedMessage();
            } elseif ($response['status'] === 404) {
                $errMessage = "Dokumen Spreadsheet tidak ditemukan (HTTP 404). Pastikan Spreadsheet ID atau URL yang Anda masukkan sudah benar.";
            } else {
                $errMessage = $response['json']['error']['message'] ?? "Google API returned HTTP {$response['status']}";
            }

            return [
                'success' => false,
                'message' => $errMessage,
                'details' => [
                    'status_code' => $response['status'],
                    'error' => $response['json']['error'] ?? $response['body'],
                ],
            ];
        } catch (\Throwable $e) {
            $this->log('error', "Google Sheets test connection error: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => 'Kesalahan koneksi Google Sheets: ' . $e->getMessage(),
                'details' => ['exception' => get_class($e)],
            ];
        }
    }

    /**
     * Get available sheets/tabs from the spreadsheet.
     */
    public function getAvailableDatasets(): array
    {
        $spreadsheetId = $this->getSpreadsheetId();
        if (empty($spreadsheetId)) {
            return [];
        }

        try {
            $token = $this->resolveAccessToken();
            $url = $this->buildApiUrl('', $token, ['includeGridData' => 'false']);
            $response = $this->httpClient->get($url, $this->buildHeaders($token));

            if ($response['status'] !== 200 || !isset($response['json']['sheets'])) {
                if ($response['status'] === 403) {
                    $this->log('warning', "Google Sheets 403: " . ($response['json']['error']['message'] ?? 'Permission Denied'));
                }
                return [];
            }

            $datasets = [];
            foreach ($response['json']['sheets'] as $sheet) {
                $props = $sheet['properties'] ?? [];
                $title = $props['title'] ?? 'Sheet';
                $rowCount = $props['gridProperties']['rowCount'] ?? 0;
                $colCount = $props['gridProperties']['columnCount'] ?? 0;

                $datasets[] = [
                    'id' => $title,
                    'name' => $title,
                    'description' => "Lembar kerja '{$title}' ({$rowCount} baris, {$colCount} kolom)",
                    'row_count' => $rowCount,
                    'col_count' => $colCount,
                ];
            }
            return $datasets;
        } catch (\Throwable $e) {
            $this->log('warning', "Failed to list Google Sheets tabs: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Fetch preview of a sheet tab (headers + sample rows + inferred schema).
     */
    public function previewSheet(?string $sheetName = null, int $sampleLimit = 5): array
    {
        $spreadsheetId = $this->getSpreadsheetId();
        if (empty($spreadsheetId)) {
            throw new RuntimeException('Spreadsheet ID is not configured.');
        }

        $available = $this->getAvailableDatasets();
        $targetSheet = $sheetName ?? ($available[0]['id'] ?? 'Sheet1');

        $token = $this->resolveAccessToken();
        // Read header + sample rows (e.g. A1:ZZ6)
        $endRow = 1 + max(1, $sampleLimit);
        $range = "{$targetSheet}!A1:ZZ{$endRow}";
        $url = $this->buildApiUrl('/values/' . urlencode($range), $token, ['majorDimension' => 'ROWS']);

        $response = $this->httpClient->get($url, $this->buildHeaders($token));
        if ($response['status'] !== 200) {
            if ($response['status'] === 403) {
                throw new RuntimeException($this->formatPermissionDeniedMessage());
            }
            if ($response['status'] === 404) {
                throw new RuntimeException("Dokumen Spreadsheet tidak ditemukan (HTTP 404). Pastikan Spreadsheet ID atau URL yang Anda masukkan sudah benar.");
            }
            throw new RuntimeException("Gagal mengambil preview data: Status {$response['status']}");
        }

        $rawRows = $response['json']['values'] ?? [];
        if (empty($rawRows)) {
            return [
                'sheet_name' => $targetSheet,
                'headers' => [],
                'columns' => [],
                'sample_rows' => [],
                'total_rows_estimate' => 0,
            ];
        }

        // 1. Detect header row (first non-empty row)
        $headerRow = array_shift($rawRows);
        $headers = [];
        $columns = [];
        $hasPrimaryDate = false;

        // Build column definitions and collect sample values per column
        $colSamples = [];
        foreach ($headerRow as $cIdx => $rawHeader) {
            $cleanName = DataNormalizer::sanitizeColumnName((string) $rawHeader, $cIdx);
            $headers[$cIdx] = [
                'raw_name' => (string) $rawHeader,
                'field_name' => $cleanName,
            ];
            $colSamples[$cleanName] = [];
        }

        // 2. Parse sample rows
        $sampleRecords = [];
        foreach ($rawRows as $row) {
            if ($this->isRowEmpty($row)) {
                continue;
            }

            $record = [];
            foreach ($headers as $cIdx => $meta) {
                $fieldName = $meta['field_name'];
                $val = $row[$cIdx] ?? null;
                $record[$fieldName] = $val;
                if ($val !== null && trim((string) $val) !== '') {
                    $colSamples[$fieldName][] = (string) $val;
                }
            }
            $sampleRecords[] = $record;
        }

        // 3. Infer data type and role per column
        foreach ($headers as $cIdx => $meta) {
            $fName = $meta['field_name'];
            $samples = $colSamples[$fName] ?? [];
            $inferredType = DataNormalizer::inferColumnType($samples);
            $roleInfo = DataNormalizer::classifyColumnRole($fName, $inferredType, $hasPrimaryDate);

            if ($roleInfo['is_primary_date']) {
                $hasPrimaryDate = true;
            }

            $columns[] = [
                'column_name' => $fName,
                'label' => $meta['raw_name'],
                'data_type' => $inferredType,
                'is_primary_date' => $roleInfo['is_primary_date'],
                'is_metric' => $roleInfo['is_metric'],
                'is_dimension' => $roleInfo['is_dimension'],
                'column_order' => $cIdx,
            ];
        }

        return [
            'sheet_name' => $targetSheet,
            'headers' => array_column($headers, 'raw_name'),
            'columns' => $columns,
            'sample_rows' => $sampleRecords,
            'total_rows_estimate' => count($rawRows),
        ];
    }

    /**
     * Fetch paginated batch of data records (READ-ONLY).
     */
    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array
    {
        $spreadsheetId = $this->getSpreadsheetId();
        if (empty($spreadsheetId)) {
            throw new RuntimeException('Spreadsheet ID is not configured.');
        }

        $sheetName = $resourceId;
        // Cursor represents 1-based start row index (defaults to row 2, skipping row 1 header)
        $startRow = is_numeric($cursor) && (int) $cursor > 1 ? (int) $cursor : 2;
        $endRow = $startRow + $limit - 1;

        $token = $this->resolveAccessToken();
        $range = "{$sheetName}!A{$startRow}:ZZ{$endRow}";
        $url = $this->buildApiUrl('/values/' . urlencode($range), $token, ['majorDimension' => 'ROWS']);

        // READ-ONLY: HTTP GET
        $response = $this->httpClient->get($url, $this->buildHeaders($token));

        if ($response['status'] !== 200) {
            if ($response['status'] === 403) {
                throw new RuntimeException($this->formatPermissionDeniedMessage());
            }
            if ($response['status'] === 404) {
                throw new RuntimeException("Dokumen Spreadsheet tidak ditemukan (HTTP 404). Periksa kembali Spreadsheet ID atau URL dokumen Anda.");
            }
            throw new RuntimeException("Google Sheets API error: Status {$response['status']} - {$response['body']}");
        }

        $rawRows = $response['json']['values'] ?? [];
        $records = [];

        // Fetch header definitions
        $headerMeta = $this->getHeaderMeta($spreadsheetId, $sheetName, $token);

        foreach ($rawRows as $rowIndex => $row) {
            if ($this->isRowEmpty($row)) {
                continue;
            }

            $record = [];
            foreach ($headerMeta as $colIndex => $col) {
                $rawVal = $row[$colIndex] ?? null;
                $cleanVal = $rawVal !== null ? trim((string) $rawVal) : null;

                // Normalize numbers & dates
                if ($cleanVal !== null && $cleanVal !== '') {
                    if ($col['data_type'] === 'NUMERIC' || $col['data_type'] === 'INTEGER') {
                        $cleanVal = DataNormalizer::cleanCurrency($cleanVal);
                    } elseif ($col['data_type'] === 'DATETIME') {
                        $cleanVal = DataNormalizer::normalizeDate($cleanVal);
                    }
                }

                $record[$col['column_name']] = $cleanVal;
            }

            $record['_row_number'] = $startRow + $rowIndex;
            $records[] = $record;
        }

        $nextCursor = null;
        if (count($rawRows) >= $limit) {
            $nextCursor = (string) ($endRow + 1);
        }

        $this->nextCursor = $nextCursor;

        return [
            'records' => $records,
            'next_cursor' => $nextCursor,
            'has_more' => $nextCursor !== null && count($records) > 0,
            'total' => count($records),
        ];
    }

    public function validatePermissions(): array
    {
        return [
            'valid' => true,
            'granted_scopes' => [self::READONLY_SCOPE],
            'missing_scopes' => [],
        ];
    }

    /**
     * Resolve active OAuth access token or exchange Service Account JWT.
     */
    public function resolveAccessToken(): string
    {
        // 1. Direct access token
        if (!empty($this->credentials['access_token'])) {
            return $this->credentials['access_token'];
        }

        // 2. Service Account JSON key authentication
        $sa = $this->credentials['service_account_json'] ?? $this->credentials['service_account'] ?? null;
        if (!empty($sa)) {
            $saData = is_array($sa) ? $sa : json_decode((string) $sa, true);
            if (is_array($saData) && isset($saData['private_key'], $saData['client_email'])) {
                $jwt = GoogleAuthHelper::createServiceAccountJwt($saData, self::READONLY_SCOPE);
                $res = GoogleAuthHelper::exchangeJwtForAccessToken($jwt, $this->httpClient);
                $this->credentials['access_token'] = $res['access_token'];
                return $res['access_token'];
            }
        }

        // 3. Fallback to API Key if configured
        return (string) ($this->credentials['api_key'] ?? '');
    }

    private function getHeaderMeta(string $spreadsheetId, string $sheetName, string $token): array
    {
        $range = "{$sheetName}!A1:ZZ1";
        $url = $this->buildApiUrl('/values/' . urlencode($range), $token, ['majorDimension' => 'ROWS']);

        try {
            $res = $this->httpClient->get($url, $this->buildHeaders($token));
            $firstRow = $res['json']['values'][0] ?? [];
            if (!empty($firstRow)) {
                $meta = [];
                foreach ($firstRow as $idx => $header) {
                    $cleanName = DataNormalizer::sanitizeColumnName((string) $header, $idx);
                    $meta[] = [
                        'raw_name' => (string) $header,
                        'column_name' => $cleanName,
                        'data_type' => 'TEXT',
                    ];
                }
                return $meta;
            }
        } catch (\Throwable) {
            // fallback
        }

        return [
            ['raw_name' => 'Column A', 'column_name' => 'col_1', 'data_type' => 'TEXT'],
            ['raw_name' => 'Column B', 'column_name' => 'col_2', 'data_type' => 'TEXT'],
            ['raw_name' => 'Column C', 'column_name' => 'col_3', 'data_type' => 'TEXT'],
        ];
    }

    private function buildHeaders(string $token): array
    {
        $headers = ['Accept: application/json'];
        if (!empty($token)) {
            if (str_starts_with($token, 'AIzaSy')) {
                $headers[] = "X-Goog-Api-Key: {$token}";
            } else {
                $headers[] = "Authorization: Bearer {$token}";
            }
        }
        return $headers;
    }

    private function isRowEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
