<?php
declare(strict_types=1);

namespace AnaBot\Connectors\GoogleSheets;

use AnaBot\Connectors\BaseConnector;
use RuntimeException;

/**
 * Google Sheets Connector (Google Sheets API v4)
 * 
 * Strict Constraint: All access is strictly READ-ONLY.
 * Scope: https://www.googleapis.com/auth/spreadsheets.readonly
 */
class GoogleSheetsConnector extends BaseConnector
{
    protected string $connectorCode = 'google_sheets';
    protected string $name = 'Google Sheets';
    protected string $category = 'spreadsheet';
    protected string $authType = 'oauth2';

    private const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';
    private const READONLY_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    public function testConnection(): array
    {
        $spreadsheetId = $this->config['spreadsheet_id'] ?? '';
        if (empty($spreadsheetId)) {
            return [
                'success' => false,
                'message' => 'Spreadsheet ID is required.',
                'details' => ['error' => 'MISSING_SPREADSHEET_ID'],
            ];
        }

        $token = $this->credentials['access_token'] ?? $this->credentials['api_key'] ?? '';
        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'No active OAuth token or API key configured.',
                'details' => ['error' => 'MISSING_CREDENTIALS'],
            ];
        }

        try {
            $url = self::API_BASE . '/' . urlencode($spreadsheetId) . '?includeGridData=false';
            $headers = $this->buildHeaders();
            $response = $this->httpClient->get($url, $headers);

            $isSuccess = $response['status'] === 200;
            $title = $response['json']['properties']['title'] ?? 'Google Sheet';

            return [
                'success' => $isSuccess,
                'message' => $isSuccess ? "Connected to Google Sheet: {$title}" : "Google Sheets API returned HTTP {$response['status']}",
                'details' => [
                    'status_code' => $response['status'],
                    'spreadsheet_title' => $title,
                    'sheets_count' => count($response['json']['sheets'] ?? []),
                    'duration_ms' => $response['duration_ms'],
                ],
            ];
        } catch (\Throwable $e) {
            $this->log('error', "Google Sheets connection test failed: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => 'Google Sheets connection error: ' . $e->getMessage(),
                'details' => ['exception' => get_class($e)],
            ];
        }
    }

    public function getAvailableDatasets(): array
    {
        $spreadsheetId = $this->config['spreadsheet_id'] ?? '';
        if (empty($spreadsheetId)) {
            return [];
        }

        try {
            $url = self::API_BASE . '/' . urlencode($spreadsheetId) . '?includeGridData=false';
            $response = $this->httpClient->get($url, $this->buildHeaders());

            if ($response['status'] !== 200 || !isset($response['json']['sheets'])) {
                return [];
            }

            $datasets = [];
            foreach ($response['json']['sheets'] as $sheet) {
                $sheetTitle = $sheet['properties']['title'] ?? 'Sheet';
                $datasets[] = [
                    'id' => $sheetTitle,
                    'name' => $sheetTitle,
                    'description' => "Sheet tab '{$sheetTitle}' with {$sheet['properties']['gridProperties']['rowCount']} rows",
                ];
            }
            return $datasets;
        } catch (\Throwable) {
            return [];
        }
    }

    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array
    {
        $spreadsheetId = $this->config['spreadsheet_id'] ?? '';
        if (empty($spreadsheetId)) {
            throw new RuntimeException('Spreadsheet ID is not configured.');
        }

        $sheetName = $resourceId;
        // Cursor represents 1-based start row index (e.g. "2" for row 2, skipping header row 1)
        $startRow = is_numeric($cursor) && (int) $cursor > 1 ? (int) $cursor : 2;
        $endRow = $startRow + $limit - 1;

        $range = urlencode("{$sheetName}!A{$startRow}:ZZ{$endRow}");
        $url = self::API_BASE . '/' . urlencode($spreadsheetId) . "/values/{$range}?majorDimension=ROWS";

        // READ-ONLY: HTTP GET
        $response = $this->httpClient->get($url, $this->buildHeaders());

        if ($response['status'] !== 200) {
            throw new RuntimeException("Google Sheets API error: Status {$response['status']} - {$response['body']}");
        }

        $rows = $response['json']['values'] ?? [];
        $records = [];

        // Fetch header row if starting at row 2
        $headers = $this->getHeaderRow($spreadsheetId, $sheetName);

        foreach ($rows as $rowIndex => $row) {
            $record = [];
            foreach ($headers as $colIndex => $colName) {
                $record[$colName] = $row[$colIndex] ?? null;
            }
            $record['_row_number'] = $startRow + $rowIndex;
            $records[] = $record;
        }

        $nextCursor = null;
        if (count($rows) >= $limit) {
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

    private function getHeaderRow(string $spreadsheetId, string $sheetName): array
    {
        $range = urlencode("{$sheetName}!A1:ZZ1");
        $url = self::API_BASE . '/' . urlencode($spreadsheetId) . "/values/{$range}?majorDimension=ROWS";

        try {
            $res = $this->httpClient->get($url, $this->buildHeaders());
            $firstRow = $res['json']['values'][0] ?? [];
            if (!empty($firstRow)) {
                return array_map(fn($col) => trim((string) $col), $firstRow);
            }
        } catch (\Throwable) {
            // fallback
        }

        return ['col_a', 'col_b', 'col_c', 'col_d', 'col_e'];
    }

    private function buildHeaders(): array
    {
        $headers = ['Accept: application/json'];
        $token = $this->credentials['access_token'] ?? $this->credentials['api_key'] ?? '';
        if (!empty($token)) {
            $headers[] = "Authorization: Bearer {$token}";
        }
        return $headers;
    }
}
