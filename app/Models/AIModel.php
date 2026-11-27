<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

/**
 * AnaBot AI Model Definition Model
 */
class AIModel
{
    public static function getByProvider(int $providerId): array
    {
        $db = Database::getInstance();
        return $db->queryAll(
            "SELECT * FROM ai_models WHERE provider_id = :pid ORDER BY is_default DESC, id ASC",
            [':pid' => $providerId]
        );
    }

    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->queryOne("SELECT * FROM ai_models WHERE id = :id LIMIT 1", [':id' => $id]);
    }

    public static function getDefault(int $providerId): ?array
    {
        $db = Database::getInstance();
        $model = $db->queryOne(
            "SELECT * FROM ai_models WHERE provider_id = :pid AND is_default = 1 LIMIT 1",
            [':pid' => $providerId]
        );
        if (!$model) {
            $model = $db->queryOne(
                "SELECT * FROM ai_models WHERE provider_id = :pid ORDER BY id ASC LIMIT 1",
                [':pid' => $providerId]
            );
        }
        return $model;
    }

    public static function setDefault(int $providerId, string $modelName): bool
    {
        $db = Database::getInstance();
        $db->execute("UPDATE ai_models SET is_default = 0 WHERE provider_id = :pid", [':pid' => $providerId]);

        $now = date('c');
        $db->execute(
            "INSERT INTO ai_models (provider_id, model_name, display_name, is_default, created_at)
             VALUES (:pid, :m, :disp, 1, :now)
             ON CONFLICT(provider_id, model_name) DO UPDATE SET is_default = 1",
            [
                ':pid' => $providerId,
                ':m' => $modelName,
                ':disp' => ucfirst($modelName),
                ':now' => $now,
            ]
        );
        return true;
    }
}
