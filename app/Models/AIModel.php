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
        $affected = $db->execute(
            "UPDATE ai_models SET is_default = 1 WHERE provider_id = :pid AND model_name = :m",
            [':pid' => $providerId, ':m' => $modelName]
        );
        return $affected > 0;
    }
}
