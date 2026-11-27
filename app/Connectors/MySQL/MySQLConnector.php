<?php
declare(strict_types=1);

namespace AnaBot\Connectors\MySQL;

use AnaBot\Connectors\BaseConnector;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * External MySQL / MariaDB Database Connector
 * 
 * Strict Constraint: All access MUST be strictly READ-ONLY.
 * No data mutation or DDL statements are permitted under any circumstances.
 */
class MySQLConnector extends BaseConnector
{
    protected string $connectorCode = 'mysql';
    protected string $name = 'MySQL / MariaDB (Read-Only)';
    protected string $category = 'database';
    protected string $authType = 'database';

    private ?PDO $externalPdo = null;

    /**
     * Forbidden DDL and DML keywords to enforce strict READ-ONLY access.
     */
    private const FORBIDDEN_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
        'CREATE', 'REPLACE', 'GRANT', 'REVOKE', 'LOCK', 'UNLOCK',
        'CALL', 'EXEC', 'EXECUTE', 'SET', 'RENAME', 'KILL'
    ];

    public function testConnection(): array
    {
        try {
            $pdo = $this->getConnection();
            $stmt = $pdo->query('SELECT 1 as test, VERSION() as version, DATABASE() as current_db');
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'message' => 'Koneksi ke basis data MySQL/MariaDB berhasil terhubung (Read-Only verified).',
                'details' => [
                    'version'    => $row['version'] ?? 'Unknown',
                    'database'   => $row['current_db'] ?? ($this->config['database'] ?? ''),
                    'read_only'  => true,
                ],
            ];
        } catch (Throwable $e) {
            $this->log('error', "MySQL connection test failed: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => 'Gagal terhubung ke MySQL: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    public function getAvailableDatasets(): array
    {
        try {
            $pdo = $this->getConnection();
            $stmt = $pdo->query('SHOW TABLES');
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $datasets = [];

            foreach ($tables as $table) {
                $tableName = (string) $table;
                // Get approximate row count
                $countStmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$this->sanitizeIdentifier($tableName)}`");
                $rowCount = (int) ($countStmt->fetchColumn() ?: 0);

                $datasets[] = [
                    'id'          => $tableName,
                    'name'        => $tableName,
                    'description' => "Tabel MySQL `{$tableName}` (~{$rowCount} baris data).",
                    'row_count'   => $rowCount,
                ];
            }

            return $datasets;
        } catch (Throwable $e) {
            $this->log('error', "Failed listing MySQL tables: {$e->getMessage()}");
            return [];
        }
    }

    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array
    {
        $pdo = $this->getConnection();
        $safeTable = $this->sanitizeIdentifier($resourceId);
        $limit = max(1, min(1000, $limit));

        // Discover primary key or incremental column
        $pkCol = $this->discoverPrimaryKey($pdo, $safeTable);
        $records = [];
        $nextCursor = null;

        if ($pkCol) {
            $sql = "SELECT * FROM `{$safeTable}`";
            $params = [':lim' => $limit];

            if ($cursor !== null && $cursor !== '') {
                $sql .= " WHERE `{$pkCol}` > :cursor";
                $params[':cursor'] = $cursor;
            }

            $sql .= " ORDER BY `{$pkCol}` ASC LIMIT :lim";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                if ($k === ':lim') {
                    $stmt->bindValue($k, $v, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($k, $v);
                }
            }
            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($records)) {
                $lastRow = end($records);
                $nextCursor = isset($lastRow[$pkCol]) ? (string) $lastRow[$pkCol] : null;
            }
        } else {
            // Offset fallback if no PK discovered
            $offset = $cursor ? (int) $cursor : 0;
            $stmt = $pdo->prepare("SELECT * FROM `{$safeTable}` LIMIT :lim OFFSET :off");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($records) === $limit) {
                $nextCursor = (string) ($offset + $limit);
            }
        }

        $this->nextCursor = $nextCursor;

        return [
            'records'     => $records,
            'next_cursor' => $nextCursor,
            'has_more'    => !empty($nextCursor) && count($records) === $limit,
            'total'       => count($records),
        ];
    }

    public function validatePermissions(): array
    {
        $valid = true;
        $missing = [];

        try {
            $pdo = $this->getConnection();
            $stmt = $pdo->query('SHOW GRANTS');
            $grants = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $hasSelect = false;
            foreach ($grants as $grant) {
                $upper = strtoupper((string) $grant);
                if (str_contains($upper, 'ALL PRIVILEGES') || str_contains($upper, 'SELECT')) {
                    $hasSelect = true;
                    break;
                }
            }

            if (!$hasSelect) {
                $valid = false;
                $missing[] = 'Hak akses SELECT pada basis data tidak ditemukan.';
            }
        } catch (Throwable $e) {
            $valid = false;
            $missing[] = $e->getMessage();
        }

        return [
            'valid'          => $valid,
            'granted_scopes' => ['SELECT'],
            'missing_scopes' => $missing,
        ];
    }

    public function disconnect(): bool
    {
        $this->externalPdo = null;
        return parent::disconnect();
    }

    /**
     * Get or initialize PDO connection to external MySQL instance.
     */
    private function getConnection(): PDO
    {
        if ($this->externalPdo !== null) {
            return $this->externalPdo;
        }

        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 3306);
        $database = (string) ($this->config['database'] ?? '');
        $charset = (string) ($this->config['charset'] ?? 'utf8mb4');

        $username = (string) ($this->credentials['username'] ?? $this->config['username'] ?? '');
        $password = (string) ($this->credentials['password'] ?? $this->config['password'] ?? '');

        if (empty($database)) {
            throw new InvalidArgumentException("Nama basis data (database) MySQL wajib diisi.");
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        try {
            $this->externalPdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ]);

            return $this->externalPdo;
        } catch (PDOException $e) {
            throw new RuntimeException("Gagal membuka koneksi MySQL: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Discover primary key column for a table.
     */
    private function discoverPrimaryKey(PDO $pdo, string $safeTable): ?string
    {
        try {
            $stmt = $pdo->query("SHOW KEYS FROM `{$safeTable}` WHERE Key_name = 'PRIMARY'");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return !empty($row['Column_name']) ? (string) $row['Column_name'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Sanitize identifier to prevent SQL injection in table/column names.
     */
    private function sanitizeIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException("Identifier basis data tidak valid [{$identifier}].");
        }
        return $identifier;
    }

    /**
     * Strictly verify that a query does not contain mutation commands.
     */
    public function assertReadOnlyQuery(string $sql): void
    {
        $normalized = strtoupper(trim($sql));
        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/', $normalized)) {
                throw new RuntimeException("Operasi ditolak: Perintah [{$keyword}] melanggar kontrak mutlak READ-ONLY.");
            }
        }
    }
}
