<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Database;
use AnaBot\Core\Encryption;
use RuntimeException;

/**
 * Manages encrypted platform connection credentials using AES-256-GCM.
 * Handles token storage, retrieval, expiration tracking, and rotation.
 */
class CredentialManager
{
    private Database $db;
    private Encryption $encryption;

    public function __construct(?Database $db = null, ?Encryption $encryption = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->encryption = $encryption ?? new Encryption();
    }

    /**
     * Store encrypted credentials for a connection.
     *
     * @param int $connectionId
     * @param array $credentials Sensitive payload (api_key, access_token, refresh_token, client_secret, etc.)
     * @param string $tokenType 'Bearer', 'Basic', 'ApiKey', 'Database', etc.
     * @param string|null $expiresAt ISO8601 string or null
     * @return bool
     */
    public function store(int $connectionId, array $credentials, string $tokenType = 'Bearer', ?string $expiresAt = null): bool
    {
        $encrypted = $this->encryption->encryptJson($credentials);
        $now = date('c');

        $sql = "INSERT INTO connection_credentials (connection_id, credentials_encrypted, token_type, expires_at, updated_at)
                VALUES (:cid, :cred, :type, :exp, :now)
                ON CONFLICT(connection_id) DO UPDATE SET
                    credentials_encrypted = excluded.credentials_encrypted,
                    token_type = excluded.token_type,
                    expires_at = excluded.expires_at,
                    updated_at = excluded.updated_at";

        return $this->db->execute($sql, [
            ':cid' => $connectionId,
            ':cred' => $encrypted,
            ':type' => $tokenType,
            ':exp' => $expiresAt,
            ':now' => $now,
        ]) >= 0;
    }

    /**
     * Retrieve and decrypt credentials for a connection.
     *
     * @param int $connectionId
     * @return array|null Decrypted credentials array or null if not found
     */
    public function get(int $connectionId): ?array
    {
        $record = $this->db->queryOne(
            "SELECT credentials_encrypted FROM connection_credentials WHERE connection_id = :cid LIMIT 1",
            [':cid' => $connectionId]
        );

        if (!$record || empty($record['credentials_encrypted'])) {
            return null;
        }

        try {
            $decrypted = $this->encryption->decryptJson($record['credentials_encrypted']);
            return is_array($decrypted) ? $decrypted : null;
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to decrypt credentials for connection #{$connectionId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Retrieve non-sensitive metadata about stored credentials.
     */
    public function getMetadata(int $connectionId): ?array
    {
        $record = $this->db->queryOne(
            "SELECT token_type, expires_at, updated_at FROM connection_credentials WHERE connection_id = :cid LIMIT 1",
            [':cid' => $connectionId]
        );

        if (!$record) {
            return null;
        }

        $expiresAt = $record['expires_at'];
        $isExpired = false;
        $needsRefresh = false;

        if ($expiresAt !== null) {
            $expTimestamp = strtotime($expiresAt);
            if ($expTimestamp !== false) {
                $now = time();
                $isExpired = $now >= $expTimestamp;
                $needsRefresh = ($expTimestamp - $now) <= 300; // within 5 minutes
            }
        }

        return [
            'token_type' => $record['token_type'],
            'expires_at' => $expiresAt,
            'is_expired' => $isExpired,
            'needs_refresh' => $needsRefresh,
            'updated_at' => $record['updated_at'],
        ];
    }

    /**
     * Check if connection tokens are currently expired.
     */
    public function isExpired(int $connectionId): bool
    {
        $meta = $this->getMetadata($connectionId);
        return $meta !== null && $meta['is_expired'];
    }

    /**
     * Check if tokens are close to expiring and need refresh.
     */
    public function needsRefresh(int $connectionId, int $thresholdMinutes = 5): bool
    {
        $record = $this->db->queryOne(
            "SELECT expires_at FROM connection_credentials WHERE connection_id = :cid LIMIT 1",
            [':cid' => $connectionId]
        );

        if (!$record || empty($record['expires_at'])) {
            return false;
        }

        $expTimestamp = strtotime($record['expires_at']);
        if ($expTimestamp === false) {
            return false;
        }

        return (time() + ($thresholdMinutes * 60)) >= $expTimestamp;
    }

    /**
     * Update access token and optional refresh token, recalculating expiry.
     */
    public function updateTokens(int $connectionId, string $newAccessToken, ?string $newRefreshToken = null, ?int $expiresInSeconds = null): bool
    {
        $current = $this->get($connectionId) ?? [];
        $current['access_token'] = $newAccessToken;

        if ($newRefreshToken !== null && !empty($newRefreshToken)) {
            $current['refresh_token'] = $newRefreshToken;
        }

        $expiresAt = null;
        if ($expiresInSeconds !== null && $expiresInSeconds > 0) {
            $expiresAt = date('c', time() + $expiresInSeconds);
        }

        $meta = $this->getMetadata($connectionId);
        $tokenType = $meta['token_type'] ?? 'Bearer';

        return $this->store($connectionId, $current, $tokenType, $expiresAt);
    }

    /**
     * Securely revoke and delete credentials from storage, marking connection disconnected.
     */
    public function revoke(int $connectionId): bool
    {
        $this->db->execute(
            "DELETE FROM connection_credentials WHERE connection_id = :cid",
            [':cid' => $connectionId]
        );

        $now = date('c');
        $this->db->execute(
            "UPDATE platform_connections SET status = 'disconnected', updated_at = :now WHERE id = :cid",
            [':now' => $now, ':cid' => $connectionId]
        );

        return true;
    }
}
