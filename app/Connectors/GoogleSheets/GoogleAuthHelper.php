<?php
declare(strict_types=1);

namespace AnaBot\Connectors\GoogleSheets;

use AnaBot\Services\HttpClient;
use RuntimeException;

/**
 * Google Authentication & URL Parsing Helper
 * 
 * Supports:
 * - Parsing Google Spreadsheet URL to extract spreadsheet ID
 * - Native RS256 JWT generation for Google Service Account credentials (zero external dependencies)
 * - Exchanging signed JWT for Google OAuth2 access token
 */
class GoogleAuthHelper
{
    public const READONLY_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';
    public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /**
     * Extract spreadsheet ID from full URL or raw ID.
     */
    public static function extractSpreadsheetId(string $input): string
    {
        $input = trim($input);

        // Standard Google Sheets URL: https://docs.google.com/spreadsheets/d/{ID}/edit...
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]+)#', $input, $matches)) {
            return $matches[1];
        }

        // Published web URL: https://docs.google.com/spreadsheets/d/e/([a-zA-Z0-9-_]+)/...
        if (preg_match('#/spreadsheets/d/e/([a-zA-Z0-9-_]+)#', $input, $matches)) {
            return $matches[1];
        }

        // Return raw input if it matches typical Google ID format (40+ alphanumeric/hyphens/underscores)
        if (preg_match('/^[a-zA-Z0-9-_]{20,100}$/', $input)) {
            return $input;
        }

        return $input;
    }

    /**
     * Generate signed RS256 JWT assertion for Google Service Account.
     *
     * @param array $serviceAccountData Parsed service account JSON
     * @param string $scope OAuth 2.0 Scope
     * @return string Signed JWT assertion string
     */
    public static function createServiceAccountJwt(array $serviceAccountData, string $scope = self::READONLY_SCOPE): string
    {
        $clientEmail = $serviceAccountData['client_email'] ?? '';
        $privateKey = $serviceAccountData['private_key'] ?? '';
        $tokenUri = $serviceAccountData['token_uri'] ?? self::TOKEN_ENDPOINT;

        if (empty($clientEmail) || empty($privateKey)) {
            throw new RuntimeException('Service account JSON must contain client_email and private_key.');
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $clientEmail,
            'scope' => $scope,
            'aud' => $tokenUri,
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $headerEncoded = self::base64UrlEncode((string) json_encode($header));
        $claimsEncoded = self::base64UrlEncode((string) json_encode($claims));
        $dataToSign = "{$headerEncoded}.{$claimsEncoded}";

        $binarySignature = '';
        $privKeyResource = openssl_pkey_get_private($privateKey);
        if ($privKeyResource === false) {
            throw new RuntimeException('Invalid RSA private key in service account JSON.');
        }

        $success = openssl_sign($dataToSign, $binarySignature, $privKeyResource, OPENSSL_ALGO_SHA256);
        if (!$success) {
            throw new RuntimeException('OpenSSL failed to sign Google Service Account JWT.');
        }

        $signatureEncoded = self::base64UrlEncode($binarySignature);
        return "{$dataToSign}.{$signatureEncoded}";
    }

    /**
     * Exchange Service Account JWT assertion for access token.
     */
    public static function exchangeJwtForAccessToken(string $jwtAssertion, ?HttpClient $httpClient = null): array
    {
        $client = $httpClient ?? new HttpClient();

        $postData = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwtAssertion,
        ];

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];

        $body = http_build_query($postData);
        $response = $client->post(self::TOKEN_ENDPOINT, $body, $headers);

        if ($response['status'] !== 200 || empty($response['json']['access_token'])) {
            $err = $response['json']['error_description'] ?? $response['json']['error'] ?? $response['body'];
            throw new RuntimeException("Google OAuth token exchange failed (HTTP {$response['status']}): {$err}");
        }

        return [
            'access_token' => $response['json']['access_token'],
            'expires_in' => $response['json']['expires_in'] ?? 3600,
            'token_type' => $response['json']['token_type'] ?? 'Bearer',
        ];
    }

    /**
     * Base64Url encoding according to RFC 7515.
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
