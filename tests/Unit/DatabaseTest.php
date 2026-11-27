<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Core\Database;

class DatabaseTest
{
    public function run(): void
    {
        echo "Testing Database Manager (Isolated Test DB)..." . PHP_EOL;

        // Use isolated testing SQLite database
        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $db = new Database($testDbPath, [
            'foreign_keys' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
        ]);

        // Test 1: Foreign keys enabled
        $fk = (int) $db->queryOne("PRAGMA foreign_keys")['foreign_keys'];
        assert($fk === 1, 'Foreign keys must be enabled (1)');
        echo "  [PASS] Foreign keys enabled" . PHP_EOL;

        // Test 2: Busy timeout set
        $timeout = (int) $db->queryOne("PRAGMA busy_timeout")['timeout'];
        assert($timeout === 5000, 'Busy timeout must be 5000ms');
        echo "  [PASS] Busy timeout is 5000ms" . PHP_EOL;

        // Test 3: Table creation and prepared statements
        $db->execute("CREATE TABLE test_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, value INTEGER NOT NULL);");
        $db->execute("INSERT INTO test_items (name, value) VALUES (:n, :v)", [':n' => 'item1', ':v' => 100]);
        $row = $db->queryOne("SELECT * FROM test_items WHERE name = :n", [':n' => 'item1']);
        assert($row['value'] === 100, 'Inserted value must be 100');
        echo "  [PASS] Prepared statement execution and queryOne" . PHP_EOL;

        // Test 4: Transactions & Rollback
        $rolledBack = false;
        try {
            $db->transaction(function (Database $d) {
                $d->execute("INSERT INTO test_items (name, value) VALUES ('rollback_item', 200)");
                throw new \Exception("Trigger rollback");
            });
        } catch (\Exception) {
            $rolledBack = true;
        }
        assert($rolledBack, 'Transaction must throw exception');
        $checkRollback = $db->queryOne("SELECT * FROM test_items WHERE name = 'rollback_item'");
        assert($checkRollback === null, 'Rolled back item must not exist in table');
        echo "  [PASS] Atomic transaction rollback" . PHP_EOL;

        // Test 5: Integrity check
        assert($db->integrityCheck() === true, 'PRAGMA integrity_check must return true');
        echo "  [PASS] PRAGMA integrity_check is OK" . PHP_EOL;

        // Test 6: Atomic Backup (VACUUM INTO)
        $backupPath = dirname(__DIR__, 2) . '/storage/backups/test_backup.sqlite';
        if (file_exists($backupPath)) {
            @unlink($backupPath);
        }
        $db->createBackup($backupPath);
        assert(file_exists($backupPath), 'Backup file must exist');
        $backupDb = new Database($backupPath);
        assert($backupDb->integrityCheck() === true, 'Backup DB integrity must be OK');
        $backupItem = $backupDb->queryOne("SELECT * FROM test_items WHERE name = 'item1'");
        assert($backupItem['value'] === 100, 'Backup data must match original');
        @unlink($backupPath);
        echo "  [PASS] Atomic backup and backup verification" . PHP_EOL;

        // Cleanup test db
        unset($db, $backupDb);
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }
    }
}
