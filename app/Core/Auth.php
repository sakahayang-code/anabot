<?php
declare(strict_types=1);

namespace AnaBot\Core;

/**
 * AnaBot Single-User Authentication & Session Service
 */
class Auth
{
    private static ?array $cachedUser = null;

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function id(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            App::getInstance()->startSession();
        }
        $id = $_SESSION['anabot_user_id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    public static function user(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }

        if (self::$cachedUser !== null && (int) self::$cachedUser['id'] === $id) {
            return self::$cachedUser;
        }

        $db = Database::getInstance();
        $user = $db->queryOne("SELECT id, username, email, status, last_login_at, created_at FROM users WHERE id = :id AND status = 'active'", [
            ':id' => $id,
        ]);

        self::$cachedUser = $user;
        return $user;
    }

    public static function attempt(string $identifier, string $password): array
    {
        $db = Database::getInstance();
        $user = $db->queryOne(
            "SELECT id, username, email, password_hash, status, failed_login_attempts, locked_until FROM users WHERE (username = :u OR email = :e) LIMIT 1",
            [':u' => $identifier, ':e' => $identifier]
        );

        if (!$user) {
            self::logAudit('auth_failure', 'users', null, ['identifier' => $identifier, 'reason' => 'user_not_found']);
            return ['success' => false, 'message' => 'Kredensial tidak valid.'];
        }

        // Check if locked
        if (!empty($user['locked_until'])) {
            $lockedUntilTime = strtotime($user['locked_until']);
            if ($lockedUntilTime > time()) {
                $waitMinutes = ceil(($lockedUntilTime - time()) / 60);
                self::logAudit('auth_locked', 'users', (string) $user['id'], ['wait_minutes' => $waitMinutes]);
                return [
                    'success' => false,
                    'message' => "Akun terkunci sementara karena terlalu banyak percobaan gagal. Silakan coba lagi dalam {$waitMinutes} menit."
                ];
            }
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $maxAttempts = (int) config('security.throttle.max_attempts', 5);
            $decayMinutes = (int) config('security.throttle.decay_minutes', 15);
            $newAttempts = (int) $user['failed_login_attempts'] + 1;
            $lockedUntil = null;

            if ($newAttempts >= $maxAttempts) {
                $lockedUntil = date('c', time() + ($decayMinutes * 60));
            }

            $db->execute(
                "UPDATE users SET failed_login_attempts = :attempts, locked_until = :locked, updated_at = :now WHERE id = :id",
                [
                    ':attempts' => $newAttempts,
                    ':locked' => $lockedUntil,
                    ':now' => date('c'),
                    ':id' => $user['id'],
                ]
            );

            self::logAudit('auth_failure', 'users', (string) $user['id'], ['reason' => 'invalid_password', 'attempts' => $newAttempts]);

            if ($lockedUntil) {
                return [
                    'success' => false,
                    'message' => "Terlalu banyak percobaan gagal. Akun dikunci selama {$decayMinutes} menit."
                ];
            }

            $remaining = max(0, $maxAttempts - $newAttempts);
            return [
                'success' => false,
                'message' => "Password salah. Sisa kesempatan sebelum terkunci: {$remaining} kali."
            ];
        }

        // Login successful - Reset failures and set session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            App::getInstance()->startSession();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['anabot_user_id'] = $user['id'];
        $_SESSION['anabot_logged_at'] = time();

        $db->execute(
            "UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = :now, last_login_ip = :ip, updated_at = :now WHERE id = :id",
            [
                ':now' => date('c'),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ':id' => $user['id'],
            ]
        );

        self::$cachedUser = null;
        self::logAudit('auth_success', 'users', (string) $user['id']);

        return ['success' => true, 'message' => 'Login berhasil.'];
    }

    public static function logout(): void
    {
        $userId = self::id();
        if ($userId) {
            self::logAudit('auth_logout', 'users', (string) $userId);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            App::getInstance()->startSession();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
        self::$cachedUser = null;
    }

    public static function changePassword(int $userId, string $oldPassword, string $newPassword): array
    {
        $db = Database::getInstance();
        $user = $db->queryOne("SELECT id, password_hash FROM users WHERE id = :id", [':id' => $userId]);

        if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Password lama tidak sesuai.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->execute(
            "UPDATE users SET password_hash = :hash, updated_at = :now WHERE id = :id",
            [':hash' => $newHash, ':now' => date('c'), ':id' => $userId]
        );

        self::logAudit('password_changed', 'users', (string) $userId);
        return ['success' => true, 'message' => 'Password berhasil diperbarui.'];
    }

    private static function logAudit(string $action, string $entityType, ?string $entityId = null, ?array $newValues = null): void
    {
        try {
            $db = Database::getInstance();
            $db->execute(
                "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, ip_address, user_agent, created_at) 
                 VALUES (:uid, :act, :type, :eid, :vals, :ip, :ua, :now)",
                [
                    ':uid' => self::id(),
                    ':act' => $action,
                    ':type' => $entityType,
                    ':eid' => $entityId,
                    ':vals' => $newValues ? json_encode($newValues) : null,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI', 0, 255),
                    ':now' => date('c'),
                ]
            );
        } catch (\Throwable) {
            // Silently continue if audit log table not ready during early migrations
        }
    }
}
