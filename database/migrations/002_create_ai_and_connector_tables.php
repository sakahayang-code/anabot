<?php
declare(strict_types=1);

use AnaBot\Core\Database;

return new class {
    public function up(Database $db): void
    {
        // 5. AI Providers Table
        $db->execute("CREATE TABLE IF NOT EXISTS ai_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            api_endpoint TEXT DEFAULT NULL,
            api_key_encrypted TEXT DEFAULT NULL,
            is_active INTEGER DEFAULT 0,
            is_primary INTEGER DEFAULT 0,
            is_fallback INTEGER DEFAULT 0,
            monthly_token_budget INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );");

        // 6. AI Models Table
        $db->execute("CREATE TABLE IF NOT EXISTS ai_models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL,
            model_name TEXT NOT NULL,
            display_name TEXT NOT NULL,
            context_window INTEGER DEFAULT 128000,
            input_cost_per_million_cents INTEGER DEFAULT 0,
            output_cost_per_million_cents INTEGER DEFAULT 0,
            is_default INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY (provider_id) REFERENCES ai_providers(id) ON DELETE CASCADE,
            UNIQUE(provider_id, model_name)
        );");

        // 7. Connectors Table
        $db->execute("CREATE TABLE IF NOT EXISTS connectors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connector_code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            category TEXT NOT NULL,
            auth_type TEXT NOT NULL,
            version TEXT NOT NULL DEFAULT '1.0.0',
            is_available INTEGER DEFAULT 1,
            created_at TEXT NOT NULL
        );");

        // 8. Platform Connections Table
        $db->execute("CREATE TABLE IF NOT EXISTS platform_connections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connector_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            status TEXT DEFAULT 'connected',
            last_sync_at TEXT DEFAULT NULL,
            last_sync_status TEXT DEFAULT NULL,
            sync_interval_minutes INTEGER DEFAULT 60,
            auto_sync_enabled INTEGER DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (connector_id) REFERENCES connectors(id) ON DELETE RESTRICT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );");

        $db->execute("CREATE INDEX IF NOT EXISTS idx_connections_status ON platform_connections(status);");

        // 9. Connection Credentials Table
        $db->execute("CREATE TABLE IF NOT EXISTS connection_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connection_id INTEGER UNIQUE NOT NULL,
            credentials_encrypted TEXT NOT NULL,
            token_type TEXT DEFAULT 'Bearer',
            expires_at TEXT DEFAULT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (connection_id) REFERENCES platform_connections(id) ON DELETE CASCADE
        );");

        // 10. Connection Scopes Table
        $db->execute("CREATE TABLE IF NOT EXISTS connection_scopes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connection_id INTEGER NOT NULL,
            scope_name TEXT NOT NULL,
            is_granted INTEGER DEFAULT 1,
            created_at TEXT NOT NULL,
            FOREIGN KEY (connection_id) REFERENCES platform_connections(id) ON DELETE CASCADE,
            UNIQUE(connection_id, scope_name)
        );");

        // 11. OAuth States Table
        $db->execute("CREATE TABLE IF NOT EXISTS oauth_states (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            state_token TEXT UNIQUE NOT NULL,
            connector_code TEXT NOT NULL,
            redirect_uri TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL
        );");
    }
};
