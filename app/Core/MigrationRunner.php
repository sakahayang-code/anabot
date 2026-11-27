<?php
declare(strict_types=1);

namespace AnaBot\Core;

use RuntimeException;
use Throwable;

/**
 * AnaBot Safe Database Migration Runner
 * 
 * Strict non-destructive migration engine with pre-migration backup,
 * SHA-256 checksum tracking, and batch versioning.
 */
class MigrationRunner
{
    private Database $db;
    private string $migrationsPath;

    public function __construct(?Database $db = null, ?string $migrationsPath = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->migrationsPath = $migrationsPath ?? dirname(__DIR__, 2) . '/database/migrations';
        $this->ensureSchemaMigrationsTable();
    }

    private function ensureSchemaMigrationsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name TEXT UNIQUE NOT NULL,
            batch INTEGER NOT NULL,
            executed_at TEXT NOT NULL,
            checksum TEXT NOT NULL,
            execution_time_ms INTEGER DEFAULT 0
        );";
        $this->db->execute($sql);
    }

    public function getExecutedMigrations(): array
    {
        return $this->db->query("SELECT * FROM schema_migrations ORDER BY id ASC");
    }

    public function getPendingMigrations(): array
    {
        $executed = array_column($this->getExecutedMigrations(), 'checksum', 'migration_name');
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.php');
        sort($files);

        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $checksum = hash_file('sha256', $file);

            if (isset($executed[$name])) {
                // Verify checksum hasn't been maliciously or accidentally altered
                if ($executed[$name] !== $checksum) {
                    if (class_exists(Logger::class)) {
                        (new Logger())->warning(
                            "Migration [{$name}] file checksum changed after execution. Expected: {$executed[$name]}, Got: {$checksum}"
                        );
                    }
                }
                continue;
            }

            $pending[] = [
                'name' => $name,
                'path' => $file,
                'checksum' => $checksum,
            ];
        }

        return $pending;
    }

    public function run(): array
    {
        $pending = $this->getPendingMigrations();
        if (empty($pending)) {
            return [
                'executed_count' => 0,
                'migrations' => [],
                'message' => 'Database is already up to date.',
            ];
        }

        // Section 5.4: Automatic backup before running migrations
        $this->performPreMigrationBackup();

        // Calculate next batch number
        $lastBatch = $this->db->queryOne("SELECT MAX(batch) as max_batch FROM schema_migrations");
        $nextBatch = ((int) ($lastBatch['max_batch'] ?? 0)) + 1;

        $applied = [];

        foreach ($pending as $item) {
            $startTime = microtime(true);
            $migration = require $item['path'];

            if (!is_object($migration) || !method_exists($migration, 'up')) {
                throw new RuntimeException("Migration [{$item['name']}] does not return an object with an up() method.");
            }

            // Run migration inside transaction
            $this->db->transaction(function (Database $db) use ($migration, $item, $nextBatch, $startTime, &$applied) {
                // Execute migration
                $migration->up($db);

                $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);

                // Record in schema_migrations
                $db->execute(
                    "INSERT INTO schema_migrations (migration_name, batch, executed_at, checksum, execution_time_ms)
                     VALUES (:name, :batch, :now, :checksum, :time)",
                    [
                        ':name' => $item['name'],
                        ':batch' => $nextBatch,
                        ':now' => date('c'),
                        ':checksum' => $item['checksum'],
                        ':time' => $elapsedMs,
                    ]
                );

                $applied[] = [
                    'name' => $item['name'],
                    'batch' => $nextBatch,
                    'time_ms' => $elapsedMs,
                ];
            });
        }

        return [
            'executed_count' => count($applied),
            'migrations' => $applied,
            'message' => 'Migrations executed successfully.',
        ];
    }

    private function performPreMigrationBackup(): void
    {
        $dbPath = $this->db->getDatabasePath();
        if ($dbPath === ':memory:' || !file_exists($dbPath)) {
            return;
        }

        $backupDir = (string) config('app.paths.backups', dirname(__DIR__, 2) . '/storage/backups');
        $backupName = 'pre_migration_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.sqlite';
        $dest = rtrim($backupDir, '/') . '/' . $backupName;

        try {
            $this->db->createBackup($dest);
            if (class_exists(Logger::class)) {
                (new Logger())->info("Pre-migration backup created successfully at [{$dest}].");
            }
        } catch (Throwable $e) {
            throw new RuntimeException("Pre-migration backup failed: " . $e->getMessage(), 0, $e);
        }
    }
}
