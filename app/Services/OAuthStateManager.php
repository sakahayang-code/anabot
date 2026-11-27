<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Database;

/**
 * Manages OAuth 2.0 State tokens to guarantee anti-CSRF protection during authorization flows.
 * Tokens are single-use and auto-expire after a configurable lifetime.
 */
class OAuthStateManager
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Generate a new single-use anti-CSRF state token.
     *
     * @param string $connectorCode
     * @param string $redirectUri
     * @param int $lifetimeMinutes Default 15 minutes
     * @return string Generated 64-character hex state token
     */
    public function createState(string $connectorCode, string $redirectUri, int $lifetimeMinutes = 15): string
    {
        // Periodic cleanup of expired states
        $this->cleanupExpired();

        $token = bin2hex(random_bytes(32));
        $now = date('c');
        $expiresAt = date('c', time() + ($lifetimeMinutes * 60));

        $this->db->execute(
            "INSERT INTO oauth_states (state_token, connector_code, redirect_uri, expires_at, created_at)
             VALUES (:token, :code, :uri, :exp, :now)",
            [
                ':token' => $token,
                ':code' => $connectorCode,
                ':uri' => $redirectUri,
                ':exp' => $expiresAt,
                ':now' => $now,
            ]
        );

        return $token;
    }

    /**
     * Verify and immediately consume (delete) a state token to prevent replay attacks.
     *
     * @param string $stateToken
     * @param string $connectorCode
     * @return bool True if valid, false if invalid or expired
     */
    public function verifyAndConsume(string $stateToken, string $connectorCode): bool
    {
        if (empty($stateToken) || empty($connectorCode)) {
            return false;
        }

        $record = $this->db->queryOne(
            "SELECT id, expires_at FROM oauth_states WHERE state_token = :token AND connector_code = :code LIMIT 1",
            [
                ':token' => $stateToken,
                ':code' => $connectorCode,
            ]
        );

        if (!$record) {
            return false;
        }

        // Always delete state token upon consumption (single-use guarantee)
        $this->db->execute(
            "DELETE FROM oauth_states WHERE id = :id",
            [':id' => $record['id']]
        );

        $now = date('c');
        if ($record['expires_at'] < $now) {
            return false; // Expired
        }

        return true;
    }

    /**
     * Delete expired state tokens.
     */
    public function cleanupExpired(): int
    {
        $now = date('c');
        return $this->db->execute(
            "DELETE FROM oauth_states WHERE expires_at < :now",
            [':now' => $now]
        );
    }
}
