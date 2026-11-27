<?php
declare(strict_types=1);

namespace AnaBot\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * AnaBot Secure PDO SQLite Database Manager
 */
class Database
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;
    private string $databasePath;
    private array $config;

    public function __construct(?string $customPath = null, ?array $customConfig = null)
    {
        $this->config = $customConfig ?? (array) config('database.connections.sqlite', []);
        $this->databasePath = $customPath ?? (string) ($this->config['database'] ?? '');

        if (empty($this->databasePath)) {
            throw new RuntimeException('Database path is not defined in configuration.');
        }

        $this->connect();
    }

    public static function getInstance(?string $customPath = null): self
    {
        if (self::$instance === null || $customPath !== null) {
            self::$instance = new self($customPath);
        }
        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    private function connect(): void
    {
        $isMemory = $this->databasePath === ':memory:';

        if (!$isMemory) {
            $dir = dirname($this->databasePath);
            $appEnv = (string) config('app.env', 'development');

            // Safety Rule 5.1: In production, do NOT silently create a blank database if missing!
            if ($appEnv === 'production' && !file_exists($this->databasePath)) {
                throw new RuntimeException(
                    "Production database not found at [{$this->databasePath}]. " .
                    "Automatic blank database creation is prohibited in production to prevent data loss. " .
                    "Please verify your DB_DATABASE or ANABOT_DATA_PATH configuration."
                );
            }

            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
        }

        $dsn = "sqlite:{$this->databasePath}";
        $options = $this->config['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->pdo = new PDO($dsn, null, null, $options);

            // Enable Foreign Keys
            if (!empty($this->config['foreign_keys'] ?? true)) {
                $this->pdo->exec('PRAGMA foreign_keys = ON;');
            }

            // Set Busy Timeout (default 5000ms)
            $busyTimeout = (int) ($this->config['busy_timeout'] ?? 5000);
            $this->pdo->exec("PRAGMA busy_timeout = {$busyTimeout};");

            // Configure Journal Mode with safe fallback
            if (!$isMemory) {
                $requestedMode = strtoupper((string) ($this->config['journal_mode'] ?? 'WAL'));
                $modeResult = $this->pdo->query("PRAGMA journal_mode = {$requestedMode};")->fetchColumn();
                $currentMode = strtoupper((string) $modeResult);

                // If WAL requested but filesystem doesn't support it, fallback safely
                if ($requestedMode === 'WAL' && $currentMode !== 'WAL') {
                    $fallbackResult = $this->pdo->query("PRAGMA journal_mode = TRUNCATE;")->fetchColumn();
                    if (class_exists(Logger::class)) {
                        (new Logger())->warning(
                            "SQLite WAL journal mode not supported by filesystem. Fallback to TRUNCATE mode ({$fallbackResult})."
                        );
                    }
                }
            }
        } catch (PDOException $e) {
            throw new RuntimeException("SQLite connection failed: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }

    public function getDatabasePath(): string
    {
        return $this->databasePath;
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function queryAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->getPdo()->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->getPdo();
        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function integrityCheck(): bool
    {
        $result = $this->getPdo()->query('PRAGMA integrity_check;')->fetchColumn();
        return strtolower((string) $result) === 'ok';
    }

    public function createBackup(string $destinationPath): bool
    {
        $dir = dirname($destinationPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        if (file_exists($destinationPath)) {
            @unlink($destinationPath);
        }

        // VACUUM INTO creates a clean, atomic snapshot including WAL data
        $stmt = $this->getPdo()->prepare("VACUUM INTO :dest");
        $stmt->execute([':dest' => $destinationPath]);

        // Verify the created backup
        $backupPdo = new PDO("sqlite:{$destinationPath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $check = $backupPdo->query('PRAGMA integrity_check;')->fetchColumn();

        if (strtolower((string) $check) !== 'ok') {
            @unlink($destinationPath);
            throw new RuntimeException("Backup integrity check failed: {$check}");
        }

        return true;
    }
}
