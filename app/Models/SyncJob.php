<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

class SyncJob
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findById(int $id): ?array
    {
        return $this->db->queryOne("SELECT * FROM sync_jobs WHERE id = :id LIMIT 1", [':id' => $id]);
    }

    public function getRecentByConnection(int $connectionId, int $limit = 10): array
    {
        return $this->db->queryAll(
            "SELECT * FROM sync_jobs WHERE connection_id = :cid ORDER BY id DESC LIMIT :lim",
            [':cid' => $connectionId, ':lim' => $limit]
        );
    }
}
