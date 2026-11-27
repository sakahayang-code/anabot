<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;
use AnaBot\Core\Encryption;

/**
 * AnaBot AI Provider Model
 * 
 * Manages configuration and encrypted credentials for AI providers in SQLite.
 */
class AIProvider
{
    public static function all(): array
    {
        $db = Database::getInstance();
        return $db->queryAll(
            "SELECT p.*, 
                    (SELECT COUNT(*) FROM ai_models m WHERE m.provider_id = p.id) as models_count,
                    (SELECT m.model_name FROM ai_models m WHERE m.provider_id = p.id AND m.is_default = 1 LIMIT 1) as default_model_name
             FROM ai_providers p 
             ORDER BY p.is_primary DESC, p.id ASC"
        );
    }

    public static function allActive(): array
    {
        $db = Database::getInstance();
        return $db->queryAll("SELECT * FROM ai_providers WHERE is_active = 1 ORDER BY is_primary DESC, id ASC");
    }

    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->queryOne("SELECT * FROM ai_providers WHERE id = :id LIMIT 1", [':id' => $id]);
    }

    public static function findByCode(string $code): ?array
    {
        $db = Database::getInstance();
        return $db->queryOne("SELECT * FROM ai_providers WHERE provider_code = :code LIMIT 1", [':code' => $code]);
    }

    public static function getPrimary(): ?array
    {
        $db = Database::getInstance();
        $provider = $db->queryOne("SELECT * FROM ai_providers WHERE is_primary = 1 AND is_active = 1 LIMIT 1");
        if (!$provider) {
            $provider = $db->queryOne("SELECT * FROM ai_providers WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
        }
        return $provider;
    }

    public static function getFallback(): ?array
    {
        $db = Database::getInstance();
        return $db->queryOne("SELECT * FROM ai_providers WHERE is_fallback = 1 AND is_active = 1 LIMIT 1");
    }

    /**
     * Store or update provider settings with AES-256-GCM encrypted API key.
     */
    public static function updateSettings(int $id, array $data, ?Encryption $encryption = null): bool
    {
        $db = Database::getInstance();
        $enc = $encryption ?? new Encryption();
        $now = date('c');

        $provider = self::find($id);
        if (!$provider) {
            return false;
        }

        $fields = ['updated_at = :now'];
        $params = [':id' => $id, ':now' => $now];

        if (isset($data['name'])) {
            $fields[] = 'name = :name';
            $params[':name'] = (string) $data['name'];
        }
        if (isset($data['api_endpoint'])) {
            $fields[] = 'api_endpoint = :ep';
            $params[':ep'] = (string) $data['api_endpoint'];
        }
        if (isset($data['api_key']) && $data['api_key'] !== '') {
            $fields[] = 'api_key_encrypted = :key';
            $params[':key'] = $enc->encrypt((string) $data['api_key']);
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = :act';
            $params[':act'] = $data['is_active'] ? 1 : 0;
        }
        if (isset($data['monthly_token_budget'])) {
            $fields[] = 'monthly_token_budget = :bgt';
            $params[':bgt'] = (int) $data['monthly_token_budget'];
        }
        if (!empty($data['is_primary'])) {
            // Demote other primary providers
            $db->execute("UPDATE ai_providers SET is_primary = 0");
            $fields[] = 'is_primary = 1';
        }
        if (!empty($data['is_fallback'])) {
            // Demote other fallback providers
            $db->execute("UPDATE ai_providers SET is_fallback = 0");
            $fields[] = 'is_fallback = 1';
        }

        $sql = "UPDATE ai_providers SET " . implode(', ', $fields) . " WHERE id = :id";
        return $db->execute($sql, $params) >= 0;
    }

    /**
     * Decrypt and return API key.
     */
    public static function getDecryptedApiKey(int $id, ?Encryption $encryption = null): string
    {
        $db = Database::getInstance();
        $row = $db->queryOne("SELECT api_key_encrypted FROM ai_providers WHERE id = :id LIMIT 1", [':id' => $id]);
        if (!$row || empty($row['api_key_encrypted'])) {
            return '';
        }

        $enc = $encryption ?? new Encryption();
        return (string) $enc->decrypt($row['api_key_encrypted']);
    }
}
