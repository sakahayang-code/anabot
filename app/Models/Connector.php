<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

class Connector
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findById(int $id): ?array
    {
        return $this->db->queryOne("SELECT * FROM connectors WHERE id = :id LIMIT 1", [':id' => $id]);
    }

    public function findByCode(string $code): ?array
    {
        return $this->db->queryOne("SELECT * FROM connectors WHERE connector_code = :c LIMIT 1", [':c' => $code]);
    }

    public function getAll(): array
    {
        return $this->db->queryAll("SELECT * FROM connectors ORDER BY category ASC, name ASC");
    }
}
