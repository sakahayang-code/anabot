<?php
declare(strict_types=1);

use AnaBot\Core\Database;

return new class {
    public function up(Database $db): void
    {
        // 1. Users Table (Single User Account)
        $db->execute("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            two_factor_secret TEXT DEFAULT NULL,
            two_factor_enabled INTEGER DEFAULT 0,
            status TEXT DEFAULT 'active',
            failed_login_attempts INTEGER DEFAULT 0,
            locked_until TEXT DEFAULT NULL,
            last_login_at TEXT DEFAULT NULL,
            last_login_ip TEXT DEFAULT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );");

        $db->execute("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);");
        $db->execute("CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);");

        // 2. User Settings Table
        $db->execute("CREATE TABLE IF NOT EXISTS user_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            setting_key TEXT NOT NULL,
            setting_value TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id, setting_key)
        );");

        // 3. System Settings Table
        $db->execute("CREATE TABLE IF NOT EXISTS system_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key TEXT UNIQUE NOT NULL,
            value TEXT NOT NULL,
            is_encrypted INTEGER DEFAULT 0,
            description TEXT DEFAULT NULL,
            updated_at TEXT NOT NULL
        );");

        // 4. Audit Logs Table
        $db->execute("CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER DEFAULT NULL,
            action TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id TEXT DEFAULT NULL,
            old_values TEXT DEFAULT NULL,
            new_values TEXT DEFAULT NULL,
            ip_address TEXT DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            created_at TEXT NOT NULL
        );");

        $db->execute("CREATE INDEX IF NOT EXISTS idx_audit_logs_action_date ON audit_logs(action, created_at);");
    }
};
