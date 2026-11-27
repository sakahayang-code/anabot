# Spesifikasi Skema Database SQLite AnaBot

Database: `anabot.sqlite`  
Engine: SQLite 3 via PDO SQLite  
Encoding: UTF-8  
Aturan Integritas:
- `PRAGMA foreign_keys = ON;`
- `PRAGMA busy_timeout = 5000;`
- `PRAGMA journal_mode = WAL;` (dengan fallback aman ke `TRUNCATE` jika filesystem hosting tidak mendukung WAL)

---

## 1. Arsitektur Tabel (28 Tabel Inti)

### Grup 1: Autentikasi, Pengguna & Konfigurasi Sistem
1. `users`
   - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
   - `username`: TEXT UNIQUE NOT NULL
   - `email`: TEXT UNIQUE NOT NULL
   - `password_hash`: TEXT NOT NULL
   - `two_factor_secret`: TEXT DEFAULT NULL (AES-256-GCM encrypted)
   - `two_factor_enabled`: INTEGER DEFAULT 0 (0: False, 1: True)
   - `status`: TEXT DEFAULT 'active' ('active', 'suspended')
   - `failed_login_attempts`: INTEGER DEFAULT 0
   - `locked_until`: TEXT DEFAULT NULL (ISO 8601)
   - `last_login_at`: TEXT DEFAULT NULL (ISO 8601)
   - `last_login_ip`: TEXT DEFAULT NULL
   - `created_at`: TEXT NOT NULL (ISO 8601)
   - `updated_at`: TEXT NOT NULL (ISO 8601)

2. `user_settings`
   - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
   - `user_id`: INTEGER NOT NULL, FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
   - `setting_key`: TEXT NOT NULL
   - `setting_value`: TEXT NOT NULL (JSON string)
   - `updated_at`: TEXT NOT NULL (ISO 8601)
   - UNIQUE(`user_id`, `setting_key`)

3. `system_settings`
   - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
   - `key`: TEXT UNIQUE NOT NULL
   - `value`: TEXT NOT NULL (JSON string)
   - `is_encrypted`: INTEGER DEFAULT 0
   - `description`: TEXT DEFAULT NULL
   - `updated_at`: TEXT NOT NULL (ISO 8601)

### Grup 2: Multi-Provider AI & Manajemen Model
4. `ai_providers`
   - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
   - `provider_code`: TEXT UNIQUE NOT NULL ('gemini', 'openai', 'claude', 'compatible')
   - `name`: TEXT NOT NULL
   - `api_endpoint`: TEXT DEFAULT NULL
   - `api_key_encrypted`: TEXT DEFAULT NULL (AES-256-GCM)
   - `is_active`: INTEGER DEFAULT 0
   - `is_primary`: INTEGER DEFAULT 0
   - `is_fallback`: INTEGER DEFAULT 0
   - `monthly_token_budget`: INTEGER DEFAULT 0 (0: Unlimited)
   - `created_at`: TEXT NOT NULL (ISO 8601)
   - `updated_at`: TEXT NOT NULL (ISO 8601)

5. `ai_models`
   - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
   - `provider_id`: INTEGER NOT NULL, FOREIGN KEY (`provider_id`) REFERENCES `ai_providers`(`id`) ON DELETE CASCADE
   - `model_name`: TEXT NOT NULL (e.g. 'gemini-1.5-pro', 'gpt-4o', 'claude-3-5-sonnet')
   - `display_name`: TEXT NOT NULL
   - `context_window`: INTEGER DEFAULT 128000
   - `input_cost_per_million_cents`: INTEGER DEFAULT 0 (satuan terkecil sen)
   - `output_cost_per_million_cents`: INTEGER DEFAULT 0
   - `is_default`: INTEGER DEFAULT 0
   - `created_at`: TEXT NOT NULL (ISO 8601)
   - UNIQUE(`provider_id`, `model_name`)

### Grup 3: Konektor Platform Eksternal (Read-Only)
6. `connectors`
   - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
   - `connector_code`: TEXT UNIQUE NOT NULL ('google_sheets', 'mysql_external', 'custom_api', 'google_analytics', dsb.)
   - `name`: TEXT NOT NULL
   - `category`: TEXT NOT NULL ('spreadsheet', 'database', 'api', 'analytics', 'social', 'ecommerce')
   - `auth_type`: TEXT NOT NULL ('oauth2', 'api_key', 'basic', 'db_connection')
   - `version`: TEXT NOT NULL DEFAULT '1.0.0'
   - `is_available`: INTEGER DEFAULT 1
   - `created_at`: TEXT NOT NULL (ISO 8601)

7. `platform_connections`
   - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
   - `connector_id`: INTEGER NOT NULL, FOREIGN KEY (`connector_id`) REFERENCES `connectors`(`id`) ON DELETE RESTRICT
   - `user_id`: INTEGER NOT NULL, FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
   - `name`: TEXT NOT NULL
   - `status`: TEXT DEFAULT 'connected' ('connected', 'disconnected', 'error', 'needs_reauth')
   - `last_sync_at`: TEXT DEFAULT NULL (ISO 8601)
   - `last_sync_status`: TEXT DEFAULT NULL ('success', 'partial', 'failed')
   - `sync_interval_minutes`: INTEGER DEFAULT 60
   - `auto_sync_enabled`: INTEGER DEFAULT 1
   - `created_at`: TEXT NOT NULL (ISO 8601)
   - `updated_at`: TEXT NOT NULL (ISO 8601)

8. `connection_credentials`
   - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
   - `connection_id`: INTEGER UNIQUE NOT NULL, FOREIGN KEY (`connection_id`) REFERENCES `platform_connections`(`id`) ON DELETE CASCADE
   - `credentials_encrypted`: TEXT NOT NULL (AES-256-GCM: tokens, passwords, keys)
   - `token_type`: TEXT DEFAULT 'Bearer'
   - `expires_at`: TEXT DEFAULT NULL (ISO 8601)
   - `updated_at`: TEXT NOT NULL (ISO 8601)

9. `connection_scopes`
   - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
   - `connection_id`: INTEGER NOT NULL, FOREIGN KEY (`connection_id`) REFERENCES `platform_connections`(`id`) ON DELETE CASCADE
   - `scope_name`: TEXT NOT NULL
   - `is_granted`: INTEGER DEFAULT 1
   - `created_at`: TEXT NOT NULL (ISO 8601)
   - UNIQUE(`connection_id`, `scope_name`)

10. `oauth_states`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `state_token`: TEXT UNIQUE NOT NULL
    - `connector_code`: TEXT NOT NULL
    - `redirect_uri`: TEXT NOT NULL
    - `expires_at`: TEXT NOT NULL (ISO 8601)
    - `created_at`: TEXT NOT NULL (ISO 8601)

### Grup 4: Sinkronisasi Data & Antrean
11. `sync_jobs`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `connection_id`: INTEGER NOT NULL, FOREIGN KEY (`connection_id`) REFERENCES `platform_connections`(`id`) ON DELETE CASCADE
    - `dataset_id`: INTEGER DEFAULT NULL, FOREIGN KEY (`dataset_id`) REFERENCES `datasets`(`id`) ON DELETE SET NULL
    - `job_type`: TEXT DEFAULT 'manual' ('manual', 'scheduled', 'retry')
    - `status`: TEXT DEFAULT 'pending' ('pending', 'running', 'completed', 'failed', 'cancelled')
    - `records_processed`: INTEGER DEFAULT 0
    - `records_added`: INTEGER DEFAULT 0
    - `records_updated`: INTEGER DEFAULT 0
    - `error_message`: TEXT DEFAULT NULL
    - `started_at`: TEXT DEFAULT NULL (ISO 8601)
    - `finished_at`: TEXT DEFAULT NULL (ISO 8601)
    - `created_at`: TEXT NOT NULL (ISO 8601)

12. `sync_logs`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `job_id`: INTEGER NOT NULL, FOREIGN KEY (`job_id`) REFERENCES `sync_jobs`(`id`) ON DELETE CASCADE
    - `connection_id`: INTEGER NOT NULL
    - `log_level`: TEXT NOT NULL ('INFO', 'WARNING', 'ERROR')
    - `message`: TEXT NOT NULL
    - `context_json`: TEXT DEFAULT NULL
    - `created_at`: TEXT NOT NULL (ISO 8601)

13. `sync_cursors`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `connection_id`: INTEGER NOT NULL, FOREIGN KEY (`connection_id`) REFERENCES `platform_connections`(`id`) ON DELETE CASCADE
    - `resource_identifier`: TEXT NOT NULL
    - `cursor_value`: TEXT NOT NULL
    - `cursor_type`: TEXT NOT NULL ('page_token', 'offset', 'timestamp', 'id')
    - `updated_at`: TEXT NOT NULL (ISO 8601)
    - UNIQUE(`connection_id`, `resource_identifier`)

### Grup 5: Dataset & Record Normal
14. `data_sources`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `connection_id`: INTEGER NOT NULL, FOREIGN KEY (`connection_id`) REFERENCES `platform_connections`(`id`) ON DELETE CASCADE
    - `external_source_id`: TEXT NOT NULL (e.g. spreadsheet ID, table name)
    - `source_name`: TEXT NOT NULL
    - `source_metadata`: TEXT DEFAULT NULL (JSON string)
    - `is_active`: INTEGER DEFAULT 1
    - `created_at`: TEXT NOT NULL (ISO 8601)
    - `updated_at`: TEXT NOT NULL (ISO 8601)

15. `datasets`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `data_source_id`: INTEGER NOT NULL, FOREIGN KEY (`data_source_id`) REFERENCES `data_sources`(`id`) ON DELETE CASCADE
    - `name`: TEXT NOT NULL
    - `description`: TEXT DEFAULT NULL
    - `record_count`: INTEGER DEFAULT 0
    - `first_record_at`: TEXT DEFAULT NULL (ISO 8601)
    - `latest_record_at`: TEXT DEFAULT NULL (ISO 8601)
    - `last_analyzed_at`: TEXT DEFAULT NULL (ISO 8601)
    - `created_at`: TEXT NOT NULL (ISO 8601)
    - `updated_at`: TEXT NOT NULL (ISO 8601)

16. `dataset_columns`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `dataset_id`: INTEGER NOT NULL, FOREIGN KEY (`dataset_id`) REFERENCES `datasets`(`id`) ON DELETE CASCADE
    - `column_name`: TEXT NOT NULL
    - `data_type`: TEXT NOT NULL ('TEXT', 'INTEGER', 'NUMERIC', 'DATETIME', 'BOOLEAN')
    - `is_primary_date`: INTEGER DEFAULT 0
    - `is_metric`: INTEGER DEFAULT 0
    - `is_dimension`: INTEGER DEFAULT 0
    - `is_nullable`: INTEGER DEFAULT 1
    - `column_order`: INTEGER DEFAULT 0
    - `created_at`: TEXT NOT NULL (ISO 8601)
    - UNIQUE(`dataset_id`, `column_name`)

17. `data_records`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `dataset_id`: INTEGER NOT NULL, FOREIGN KEY (`dataset_id`) REFERENCES `datasets`(`id`) ON DELETE CASCADE
    - `external_id`: TEXT DEFAULT NULL
    - `record_date`: TEXT DEFAULT NULL (ISO 8601, untuk pengindeksan time series cepat)
    - `record_hash`: TEXT NOT NULL (SHA-256 dari data ternormalisasi untuk deteksi duplikasi)
    - `data_json`: TEXT NOT NULL (Payload baris data dalam format JSON)
    - `created_at`: TEXT NOT NULL (ISO 8601)
    - `updated_at`: TEXT NOT NULL (ISO 8601)
    - UNIQUE(`dataset_id`, `record_hash`)

### Grup 6: Sesi Analisis & Hasil Analytics
18. `analysis_sessions`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `user_id`: INTEGER NOT NULL, FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    - `title`: TEXT NOT NULL
    - `description`: TEXT DEFAULT NULL
    - `provider_id`: INTEGER DEFAULT NULL, FOREIGN KEY (`provider_id`) REFERENCES `ai_providers`(`id`) ON DELETE SET NULL
    - `model_id`: INTEGER DEFAULT NULL, FOREIGN KEY (`model_id`) REFERENCES `ai_models`(`id`) ON DELETE SET NULL
    - `created_at`: TEXT NOT NULL (ISO 8601)
    - `updated_at`: TEXT NOT NULL (ISO 8601)

19. `analysis_messages`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `session_id`: INTEGER NOT NULL, FOREIGN KEY (`session_id`) REFERENCES `analysis_sessions`(`id`) ON DELETE CASCADE
    - `sender_type`: TEXT NOT NULL ('user', 'ai', 'system')
    - `message_content`: TEXT NOT NULL
    - `context_summary`: TEXT DEFAULT NULL (Rangkuman statistik PHP yang diinjeksikan)
    - `tokens_input`: INTEGER DEFAULT 0
    - `tokens_output`: INTEGER DEFAULT 0
    - `created_at`: TEXT NOT NULL (ISO 8601)

20. `analysis_results`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `session_id`: INTEGER DEFAULT NULL, FOREIGN KEY (`session_id`) REFERENCES `analysis_sessions`(`id`) ON DELETE CASCADE
    - `dataset_id`: INTEGER NOT NULL, FOREIGN KEY (`dataset_id`) REFERENCES `datasets`(`id`) ON DELETE CASCADE
    - `metric_name`: TEXT NOT NULL
    - `analysis_type`: TEXT NOT NULL ('descriptive', 'growth', 'trend', 'anomaly_iqr', 'anomaly_zscore', 'forecast')
    - `calculated_value_json`: TEXT NOT NULL (Angka pasti hasil komputasi PHP Analytics Engine)
    - `ai_interpretation`: TEXT DEFAULT NULL (Penjelasan dan narasi dari AI)
    - `calculated_at`: TEXT NOT NULL (ISO 8601)

21. `analysis_sources`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `session_id`: INTEGER NOT NULL, FOREIGN KEY (`session_id`) REFERENCES `analysis_sessions`(`id`) ON DELETE CASCADE
    - `dataset_id`: INTEGER NOT NULL, FOREIGN KEY (`dataset_id`) REFERENCES `datasets`(`id`) ON DELETE CASCADE
    - `created_at`: TEXT NOT NULL (ISO 8601)
    - UNIQUE(`session_id`, `dataset_id`)

### Grup 7: Laporan & Penjadwalan
22. `reports`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `user_id`: INTEGER NOT NULL, FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    - `title`: TEXT NOT NULL
    - `report_type`: TEXT NOT NULL ('pdf', 'xlsx', 'csv', 'html')
    - `file_path`: TEXT NOT NULL (relatif terhadap direktori reports privat)
    - `file_size_bytes`: INTEGER DEFAULT 0
    - `date_range_start`: TEXT DEFAULT NULL (ISO 8601)
    - `date_range_end`: TEXT DEFAULT NULL (ISO 8601)
    - `summary_content`: TEXT DEFAULT NULL
    - `status`: TEXT DEFAULT 'generated' ('generated', 'archived')
    - `created_at`: TEXT NOT NULL (ISO 8601)

23. `scheduled_tasks`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `task_type`: TEXT NOT NULL ('sync', 'report', 'backup', 'cleanup')
    - `task_name`: TEXT NOT NULL
    - `target_id`: INTEGER DEFAULT NULL (connection_id atau report config)
    - `cron_expression`: TEXT NOT NULL (e.g. '0 * * * *')
    - `is_active`: INTEGER DEFAULT 1
    - `last_run_at`: TEXT DEFAULT NULL (ISO 8601)
    - `next_run_at`: TEXT DEFAULT NULL (ISO 8601)
    - `created_at`: TEXT NOT NULL (ISO 8601)

24. `notifications`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `user_id`: INTEGER NOT NULL, FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    - `title`: TEXT NOT NULL
    - `message`: TEXT NOT NULL
    - `type`: TEXT DEFAULT 'info' ('info', 'warning', 'error', 'success')
    - `is_read`: INTEGER DEFAULT 0
    - `action_url`: TEXT DEFAULT NULL
    - `created_at`: TEXT NOT NULL (ISO 8601)

### Grup 8: Logging, Audit, Cache & Migrations
25. `ai_usage_logs`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `provider_id`: INTEGER NOT NULL, FOREIGN KEY (`provider_id`) REFERENCES `ai_providers`(`id`) ON DELETE CASCADE
    - `model_id`: INTEGER DEFAULT NULL, FOREIGN KEY (`model_id`) REFERENCES `ai_models`(`id`) ON DELETE SET NULL
    - `session_id`: INTEGER DEFAULT NULL
    - `prompt_tokens`: INTEGER NOT NULL
    - `completion_tokens`: INTEGER NOT NULL
    - `total_tokens`: INTEGER NOT NULL
    - `estimated_cost_cents`: INTEGER DEFAULT 0 (satuan terkecil sen)
    - `execution_time_ms`: INTEGER DEFAULT 0
    - `status`: TEXT DEFAULT 'success' ('success', 'error')
    - `error_message`: TEXT DEFAULT NULL
    - `created_at`: TEXT NOT NULL (ISO 8601)

26. `audit_logs`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `user_id`: INTEGER DEFAULT NULL
    - `action`: TEXT NOT NULL
    - `entity_type`: TEXT NOT NULL
    - `entity_id`: TEXT DEFAULT NULL
    - `old_values`: TEXT DEFAULT NULL (JSON)
    - `new_values`: TEXT DEFAULT NULL (JSON)
    - `ip_address`: TEXT DEFAULT NULL
    - `user_agent`: TEXT DEFAULT NULL
    - `created_at`: TEXT NOT NULL (ISO 8601)

27. `app_cache`
    - `cache_key`: TEXT PRIMARY KEY
    - `cache_value`: TEXT NOT NULL
    - `expires_at`: INTEGER NOT NULL (UNIX timestamp)
    - `created_at`: INTEGER NOT NULL (UNIX timestamp)

28. `schema_migrations`
    - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
    - `migration_name`: TEXT UNIQUE NOT NULL
    - `batch`: INTEGER NOT NULL
    - `executed_at`: TEXT NOT NULL (ISO 8601)
    - `checksum`: TEXT NOT NULL (SHA-256 dari isi file migration)
    - `execution_time_ms`: INTEGER DEFAULT 0

---

## 2. Indeks Kinerja Database (Indexes)
Untuk mempercepat pembacaan data, filter, sorting, dan time-series tanpa membebani disk:

```sql
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_connections_status ON platform_connections(status);
CREATE INDEX idx_sync_jobs_status ON sync_jobs(status, created_at);
CREATE INDEX idx_data_records_dataset_date ON data_records(dataset_id, record_date);
CREATE INDEX idx_data_records_hash ON data_records(dataset_id, record_hash);
CREATE INDEX idx_analysis_messages_session ON analysis_messages(session_id, created_at);
CREATE INDEX idx_analysis_results_dataset ON analysis_results(dataset_id, analysis_type);
CREATE INDEX idx_ai_usage_provider_date ON ai_usage_logs(provider_id, created_at);
CREATE INDEX idx_audit_logs_action_date ON audit_logs(action, created_at);
CREATE INDEX idx_notifications_user_read ON notifications(user_id, is_read);
CREATE INDEX idx_app_cache_expires ON app_cache(expires_at);
```
