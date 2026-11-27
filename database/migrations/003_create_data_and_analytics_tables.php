<?php
declare(strict_types=1);

use AnaBot\Core\Database;

return new class {
    public function up(Database $db): void
    {
        // 12. Sync Jobs Table
        $db->execute("CREATE TABLE IF NOT EXISTS sync_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connection_id INTEGER NOT NULL,
            dataset_id INTEGER DEFAULT NULL,
            job_type TEXT DEFAULT 'manual',
            status TEXT DEFAULT 'pending',
            records_processed INTEGER DEFAULT 0,
            records_added INTEGER DEFAULT 0,
            records_updated INTEGER DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            started_at TEXT DEFAULT NULL,
            finished_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (connection_id) REFERENCES platform_connections(id) ON DELETE CASCADE
        );");

        $db->execute("CREATE INDEX IF NOT EXISTS idx_sync_jobs_status ON sync_jobs(status, created_at);");

        // 13. Sync Logs Table
        $db->execute("CREATE TABLE IF NOT EXISTS sync_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            connection_id INTEGER NOT NULL,
            log_level TEXT NOT NULL,
            message TEXT NOT NULL,
            context_json TEXT DEFAULT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (job_id) REFERENCES sync_jobs(id) ON DELETE CASCADE
        );");

        // 14. Sync Cursors Table
        $db->execute("CREATE TABLE IF NOT EXISTS sync_cursors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connection_id INTEGER NOT NULL,
            resource_identifier TEXT NOT NULL,
            cursor_value TEXT NOT NULL,
            cursor_type TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (connection_id) REFERENCES platform_connections(id) ON DELETE CASCADE,
            UNIQUE(connection_id, resource_identifier)
        );");

        // 15. Data Sources Table
        $db->execute("CREATE TABLE IF NOT EXISTS data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connection_id INTEGER NOT NULL,
            external_source_id TEXT NOT NULL,
            source_name TEXT NOT NULL,
            source_metadata TEXT DEFAULT NULL,
            is_active INTEGER DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (connection_id) REFERENCES platform_connections(id) ON DELETE CASCADE
        );");

        // 16. Datasets Table
        $db->execute("CREATE TABLE IF NOT EXISTS datasets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            data_source_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT DEFAULT NULL,
            record_count INTEGER DEFAULT 0,
            first_record_at TEXT DEFAULT NULL,
            latest_record_at TEXT DEFAULT NULL,
            last_analyzed_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE CASCADE
        );");

        // 17. Dataset Columns Table
        $db->execute("CREATE TABLE IF NOT EXISTS dataset_columns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dataset_id INTEGER NOT NULL,
            column_name TEXT NOT NULL,
            data_type TEXT NOT NULL,
            is_primary_date INTEGER DEFAULT 0,
            is_metric INTEGER DEFAULT 0,
            is_dimension INTEGER DEFAULT 0,
            is_nullable INTEGER DEFAULT 1,
            column_order INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE CASCADE,
            UNIQUE(dataset_id, column_name)
        );");

        // 18. Data Records Table
        $db->execute("CREATE TABLE IF NOT EXISTS data_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dataset_id INTEGER NOT NULL,
            external_id TEXT DEFAULT NULL,
            record_date TEXT DEFAULT NULL,
            record_hash TEXT NOT NULL,
            data_json TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE CASCADE,
            UNIQUE(dataset_id, record_hash)
        );");

        $db->execute("CREATE INDEX IF NOT EXISTS idx_data_records_dataset_date ON data_records(dataset_id, record_date);");
        $db->execute("CREATE INDEX IF NOT EXISTS idx_data_records_hash ON data_records(dataset_id, record_hash);");

        // 19. Analysis Sessions Table
        $db->execute("CREATE TABLE IF NOT EXISTS analysis_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT DEFAULT NULL,
            provider_id INTEGER DEFAULT NULL,
            model_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (provider_id) REFERENCES ai_providers(id) ON DELETE SET NULL,
            FOREIGN KEY (model_id) REFERENCES ai_models(id) ON DELETE SET NULL
        );");

        // 20. Analysis Messages Table
        $db->execute("CREATE TABLE IF NOT EXISTS analysis_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            sender_type TEXT NOT NULL,
            message_content TEXT NOT NULL,
            context_summary TEXT DEFAULT NULL,
            tokens_input INTEGER DEFAULT 0,
            tokens_output INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY (session_id) REFERENCES analysis_sessions(id) ON DELETE CASCADE
        );");

        $db->execute("CREATE INDEX IF NOT EXISTS idx_analysis_messages_session ON analysis_messages(session_id, created_at);");

        // 21. Analysis Results Table
        $db->execute("CREATE TABLE IF NOT EXISTS analysis_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER DEFAULT NULL,
            dataset_id INTEGER NOT NULL,
            metric_name TEXT NOT NULL,
            analysis_type TEXT NOT NULL,
            calculated_value_json TEXT NOT NULL,
            ai_interpretation TEXT DEFAULT NULL,
            calculated_at TEXT NOT NULL,
            FOREIGN KEY (session_id) REFERENCES analysis_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE CASCADE
        );");

        $db->execute("CREATE INDEX IF NOT EXISTS idx_analysis_results_dataset ON analysis_results(dataset_id, analysis_type);");

        // 22. Analysis Sources Table
        $db->execute("CREATE TABLE IF NOT EXISTS analysis_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            dataset_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (session_id) REFERENCES analysis_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE CASCADE,
            UNIQUE(session_id, dataset_id)
        );");

        // 23. Reports Table
        $db->execute("CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            report_type TEXT NOT NULL,
            file_path TEXT NOT NULL,
            file_size_bytes INTEGER DEFAULT 0,
            date_range_start TEXT DEFAULT NULL,
            date_range_end TEXT DEFAULT NULL,
            summary_content TEXT DEFAULT NULL,
            status TEXT DEFAULT 'generated',
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );");

        // 24. Scheduled Tasks Table
        $db->execute("CREATE TABLE IF NOT EXISTS scheduled_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_type TEXT NOT NULL,
            task_name TEXT NOT NULL,
            target_id INTEGER DEFAULT NULL,
            cron_expression TEXT NOT NULL,
            is_active INTEGER DEFAULT 1,
            last_run_at TEXT DEFAULT NULL,
            next_run_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL
        );");

        // 25. Notifications Table
        $db->execute("CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            type TEXT DEFAULT 'info',
            is_read INTEGER DEFAULT 0,
            action_url TEXT DEFAULT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );");

        $db->execute("CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read);");

        // 26. AI Usage Logs Table
        $db->execute("CREATE TABLE IF NOT EXISTS ai_usage_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL,
            model_id INTEGER DEFAULT NULL,
            session_id INTEGER DEFAULT NULL,
            prompt_tokens INTEGER NOT NULL,
            completion_tokens INTEGER NOT NULL,
            total_tokens INTEGER NOT NULL,
            estimated_cost_cents INTEGER DEFAULT 0,
            execution_time_ms INTEGER DEFAULT 0,
            status TEXT DEFAULT 'success',
            error_message TEXT DEFAULT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (provider_id) REFERENCES ai_providers(id) ON DELETE CASCADE,
            FOREIGN KEY (model_id) REFERENCES ai_models(id) ON DELETE SET NULL
        );");

        $db->execute("CREATE INDEX IF NOT EXISTS idx_ai_usage_provider_date ON ai_usage_logs(provider_id, created_at);");

        // 27. App Cache Table
        $db->execute("CREATE TABLE IF NOT EXISTS app_cache (
            cache_key TEXT PRIMARY KEY,
            cache_value TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        );");

        $db->execute("CREATE INDEX IF NOT EXISTS idx_app_cache_expires ON app_cache(expires_at);");
    }
};
