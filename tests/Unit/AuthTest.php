<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Core\App;
use AnaBot\Core\Auth;
use AnaBot\Core\Database;
use AnaBot\Core\MigrationRunner;

class AuthTest
{
    public function run(): void
    {
        echo "Testing Authentication, Session & Throttling (Isolated Test DB)..." . PHP_EOL;

        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $db = new Database($testDbPath);
        Database::setInstance($db);

        // Run migrations
        $runner = new MigrationRunner($db);
        $runner->run();

        // Seed test user
        $password = 'CorrectPassword123!';
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $now = date('c');

        $db->execute(
            "INSERT INTO users (username, email, password_hash, status, created_at, updated_at) 
             VALUES ('testuser', 'test@anabot.local', :hash, 'active', :now, :now)",
            [':hash' => $hash, ':now' => $now]
        );
        $userId = (int) $db->lastInsertId();

        // Test 1: Successful Login
        $successResult = Auth::attempt('testuser', $password);
        assert($successResult['success'] === true, 'Login with correct password must succeed');
        assert(Auth::check() === true, 'Auth::check must return true after login');
        assert(Auth::id() === $userId, 'Auth::id must return seeded user id');
        echo "  [PASS] Authentication successful with password_verify" . PHP_EOL;

        // Test 2: Logout
        Auth::logout();
        assert(Auth::check() === false, 'Auth::check must return false after logout');
        assert(Auth::id() === null, 'Auth::id must return null after logout');
        echo "  [PASS] Session logout and destruction" . PHP_EOL;

        // Test 3: Failed Login Attempts & Throttling
        for ($i = 1; $i <= 4; $i++) {
            $failResult = Auth::attempt('testuser', 'WrongPassword');
            assert($failResult['success'] === false, 'Login with wrong password must fail');
        }

        $userAfterFails = $db->queryOne("SELECT failed_login_attempts, locked_until FROM users WHERE id = :id", [':id' => $userId]);
        assert((int) $userAfterFails['failed_login_attempts'] === 4, 'Failed attempts must be 4');
        assert(empty($userAfterFails['locked_until']), 'Must not be locked yet at 4 attempts');
        echo "  [PASS] Failed login attempts tracking (4 attempts)" . PHP_EOL;

        // 5th attempt locks the account
        $lockResult = Auth::attempt('testuser', 'WrongPassword');
        assert($lockResult['success'] === false, '5th attempt must fail');
        $userLocked = $db->queryOne("SELECT failed_login_attempts, locked_until FROM users WHERE id = :id", [':id' => $userId]);
        assert(!empty($userLocked['locked_until']), 'Account must be locked at 5 attempts');
        echo "  [PASS] Brute-force throttling (Account locked at 5 failed attempts)" . PHP_EOL;

        // Reset lock for change password test
        $db->execute("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id", [':id' => $userId]);

        // Test 4: Change Password
        $changeFail = Auth::changePassword($userId, 'IncorrectOldPass', 'NewPass123456');
        assert($changeFail['success'] === false, 'Change password with wrong old password must fail');

        $changeSuccess = Auth::changePassword($userId, $password, 'NewPass123456');
        assert($changeSuccess['success'] === true, 'Change password with correct old password must succeed');

        $reLoginNew = Auth::attempt('testuser', 'NewPass123456');
        assert($reLoginNew['success'] === true, 'Login with new password must succeed');
        echo "  [PASS] Secure password change verification" . PHP_EOL;

        // Test 5: Verify Audit Logs recorded
        $logs = $db->query("SELECT action FROM audit_logs WHERE user_id = :uid", [':uid' => $userId]);
        assert(!empty($logs), 'Audit logs must have recorded auth actions');
        echo "  [PASS] Audit log recording for auth and password events" . PHP_EOL;

        unset($db);
        Database::setInstance(null);
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }
    }
}
