# Changelog AnaBot (AI Analytics Robot)

Seluruh perubahan penting pada proyek AnaBot dicatat dalam dokumen ini. Format mengikuti standar [Keep a Changelog](https://keepachangelog.com/id/1.0.0/).

---

## [0.4.0-dev] - 2026-11-27 (FASE 3 — Database & Data Explorer)

### Ditambahkan (Added)
- **Model Akses Data SQLite (`app/Models/`):**
  - `Dataset.php`: Query dataset dengan pagination, metadata relasi data source & konektor, serta cascade deletion aman.
  - `DatasetColumn.php`: Pengambilan struktur kolom per dataset dan pembaruan metadata (tipe data, primary date, metric, dimension).
  - `DataRecord.php`: Pembacaan data baris ternormalisasi dengan pagination cepat SQLite (LIMIT/OFFSET), pencarian teks bebas, penyaringan JSON (`json_extract`), pengurutan dinamis (ASC/DESC), dan cursor export.
- **Service Data Explorer (`app/Services/DataExplorerService.php`):**
  - Logika bisnis sentral pengelolaan dataset, detail skema, paginasi baris data, dan penghapusan lokal aman.
  - **Streaming CSV Generator:** Generator PHP berbasis `yield` (`streamCsvExport`) yang membaca baris demi baris via cursor PDO langsung ke output buffer tanpa membebani memori PHP (Memory-Safe untuk Shared Hosting).
  - Kepatuhan PHP 8.4: Penanganan parameter eksplisit `$escape` pada fungsi `fputcsv()`.
- **Controller Data Explorer (`app/Controllers/DataExplorerController.php`):**
  - Web handlers: `datasets()`, `records()`, `schema()`, `exportCsv()`, `updateColumn()`, `deleteDataset()`.
  - REST API handlers: `GET /api/v1/data/overview`, `GET /api/v1/datasets`, `GET /api/v1/datasets/{id}`, `GET /api/v1/datasets/{id}/records`, `GET /api/v1/datasets/{id}/schema`, `GET /api/v1/datasets/{id}/export`.
- **Antarmuka Data Explorer (`resources/views/explorer/`):**
  - `datasets.php`: Tabel ringkasan dataset, jumlah baris, rentang tanggal data, dan aksi cepat.
  - `records.php`: Browser baris data responsif dengan dropdown pemilih dataset, bilah pencarian teks bebas, filter kolom, kontrol sorting, dan paginasi interaktif.
  - `schema.php`: Inspeksi tipe kolom (TEXT, INTEGER, NUMERIC, DATETIME, BOOLEAN), penandaan tanggal linimasa (Primary Date), serta klasifikasi Metrik vs Dimensi untuk kebutuhan analitik.
- **CLI Development Seeder (`database/seeders/seed_sample_dataset.php`):**
  - Seeder data realistis 75 baris transaksi penjualan multi-kategori dengan 6 kolom terstruktur.
- **Automated Test Suite (`tests/Unit/DataExplorerTest.php`):**
  - Pengujian komprehensif paginasi SQLite, text search, filter kolom JSON, sorting dinamis, pembaruan metadata skema, streaming CSV export, overview stats, dan cascade deletion (8/8 test suites lulus 100%).

---

## [0.3.0-dev] - 2026-11-27 (FASE 2 — Dashboard & UI Framework)

### Ditambahkan (Added)
- **Design System SaaS Modern (`public/assets/css/anabot.css`):**
  - Palet warna profesional Cobalt Blue (`#0284c7`), Deep Navy (`#0b1120`), dan Cool Gray (`#f8fafc`).
  - Dukungan native **Dark Mode & Light Mode** dengan variabel CSS dinamis.
  - Bento KPI Grid cards dengan hover micro-interactions dan layout responsif desktop & mobile.
  - Tabular figures (`font-variant-numeric: tabular-nums`) untuk konsistensi angka dan statistik.
- **Frontend Interaktif & Offline Chart Bundle (`public/assets/js/anabot.js` & `chart.min.js`):**
  - Penyimpan preferensi tema otomatis ke `localStorage` (`anabot_theme`).
  - Mobile navigation drawer dengan slide-in menu dan backdrop touch overlay.
  - Bundle lokal mandiri Chart.js 4.4 (202 KB) di `public/assets/js/chart.min.js` tanpa ketergantungan CDN eksternal.
- **Master Layout & View Templates (`resources/views/layouts/`):**
  - Master layout `layouts/main.php` dengan deklarasi meta viewport, footer, dan aset lokal.
  - Sidebar hierarkis `layouts/sidebar.php` mencakup seluruh 10 modul Bagian 10 dengan indikator active route.
  - Top header `layouts/header.php` dengan tombol dark/light switcher, profil single-user, dan tombol logout aman ber-CSRF.
- **Dashboard Eksekutif (`resources/views/dashboard/index.php` & `app/Controllers/DashboardController.php`):**
  - 8 Kartu metrik KPI terhubung langsung ke SQLite: Platform terhubung, Datasets, Records, Sinkronisasi terakhir, Sesi analisis, Total laporan, Token AI, dan Estimasi biaya AI.
  - Grafik pertumbuhan time-series record data (Area Chart) dan donat distribusi token AI (Donut Chart).
  - Kepatuhan Aturan 1.12 & 1.13: Data aktual diberi label `DATA AKTUAL`, dan data demo/preview diberi badge transparan `DEMO`.
  - Tabel ringkasan platform terhubung dan pintasan aksi cepat ke modul koneksi.
- **Roadmap Placeholder Controller (`app/Controllers/ModulePlaceholderController.php`):**
  - Seluruh rute modul di sidebar terhubung ke halaman informasi fase berikutnya tanpa menghasilkan error 404 (Rules 1.12).
- **REST API Endpoint (`GET /api/v1/dashboard/stats`):**
  - Endpoint JSON terautentikasi untuk data real-time dashboard.
- **Automated Test Suite (`tests/Unit/DashboardTest.php`):**
  - Pengujian agregasi metrik, validasi aset lokal, dan pengujian bendera transparan `isGrowthDataDemo` (7/7 test suites lulus 100%).

---

## [0.2.0-dev] - 2026-11-27 (FASE 1 — Core System)

### Ditambahkan (Added)
- **Bootstrap Aplikasi & Service Container (`app/Core/App.php`):**
  - Inisialisasi runtime, manajemen session berkonfigurasi aman (`HttpOnly`, `SameSite=Lax`, `Secure`), dan service container singleton.
  - Global error handler & exception handler dengan proteksi kebocoran stack trace di mode produksi dan audit logging.
- **Database Engine PDO SQLite (`app/Core/Database.php`):**
  - Koneksi aman PDO SQLite dengan `PRAGMA foreign_keys = ON;`, `PRAGMA busy_timeout = 5000;`, dan `PRAGMA journal_mode = WAL;` (dengan fallback otomatis ke `TRUNCATE` jika filesystem tidak mendukung WAL).
  - Wrapper transaksi atomik (`transaction()`) dan snapshot backup konsisten atomik via `VACUUM INTO`.
  - Proteksi Rule 5.1: Mencegah pembuatan otomatis database kosong di lingkungan produksi jika database tidak ditemukan.
- **Layanan Enkripsi Kredensial (`app/Core/Encryption.php`):**
  - Enkripsi dan dekripsi dua arah **AES-256-GCM** via OpenSSL dengan IV unik per entri dan 128-bit authentication tag untuk deteksi tampering.
- **Structured Logger & Masking (`app/Core/Logger.php`):**
  - Rotasi log harian (`anabot_YYYY-MM-DD.log`) dengan penyaringan dan penyensoran token/password otomatis.
- **Router Modular & Fast Dispatcher (`app/Core/Router.php`):**
  - Routing clean URL untuk metode GET, POST, PATCH, DELETE, grouping prefix (`/api/v1`), middleware chaining (`auth`, `guest`, `csrf`), dan parameter dinamis `{id}`.
- **Autentikasi Single-User & Throttling (`app/Core/Auth.php`):**
  - Autentikasi berbasis session cookie aman dengan hashing `password_hash` (BCrypt cost 12) dan verifikasi `password_verify`.
  - Brute-force throttling: Pelacakan percobaan gagal (maksimal 5 kali) dan penguncian akun sementara selama 15 menit.
  - Audit log otomatis untuk event login berhasil, login gagal, akun terkunci, dan perubahan password.
- **Input Validator & CSRF (`app/Core/Validator.php`):**
  - Aturan validasi input: `required`, `email`, `min`, `max`, `numeric`, `integer`, `in`, `same`, dan `csrf`.
- **Response Helper (`app/Core/Response.php`):**
  - Standarisasi output HTTP JSON envelope (`success`, `data`, `message`, `error`, `meta`), view renderer, dan redirect.
- **Migration Engine (`app/Core/MigrationRunner.php`):**
  - Eksekusi migrasi berurutan terverifikasi dengan pencatatan tabel `schema_migrations` (id, migration_name, batch, executed_at, SHA-256 checksum, execution_time_ms).
  - Snapshot backup otomatis sebelum setiap proses migrasi baru dijalankan.
- **3 Migrasi Skema Baseline (`database/migrations/`):**
  - `001_create_core_tables.php` (`users`, `user_settings`, `system_settings`, `audit_logs`).
  - `002_create_ai_and_connector_tables.php` (`ai_providers`, `ai_models`, `connectors`, `platform_connections`, `connection_credentials`, `connection_scopes`, `oauth_states`).
  - `003_create_data_and_analytics_tables.php` (`sync_jobs`, `sync_logs`, `sync_cursors`, `data_sources`, `datasets`, `dataset_columns`, `data_records`, `analysis_sessions`, `analysis_messages`, `analysis_results`, `analysis_sources`, `reports`, `scheduled_tasks`, `notifications`, `ai_usage_logs`, `app_cache`).
- **Controllers & Entrypoints:**
  - `app/Controllers/AuthController.php` (login, logout, me, change-password untuk Web dan API).
  - `app/Controllers/HomeController.php` (landing redirect, dashboard transitional, health check API).
  - `routes/web.php` dan `routes/api.php`.
  - `public/index.php` front controller dan `public/.htaccess`.
  - `database/migrate.php` CLI migration runner & `database/seeders/create_user.php` single-user seeder.
- **Automated Test Suite (`tests/run_tests.php`):**
  - 6 unit & integration test suites komprehensif berjalan 100% lulus di lingkungan database testing terisolasi (`anabot_testing.sqlite`).

---

## [0.1.0-dev] - 2026-11-27 (FASE 0 — Pemeriksaan Lingkungan & Perencanaan)

### Ditambahkan (Added)
- **Verifikasi Lingkungan Sistem:**
  - Runtime terverifikasi menggunakan **PHP 8.4.28 CLI**.
  - Ekstensi terverifikasi: `pdo_sqlite`, `sqlite3` (Lib v3.45.1), `curl`, `openssl`, `json`, `mbstring`, `bcmath`, `intl`, `zip`.
  - Dukungan penjadwalan terverifikasi: CLI `cron` dan `crontab`.
  - Package manager terverifikasi: `composer` untuk autoloading PSR-4 (`AnaBot\`).
- **Arsitektur Teknis & Keamanan (`docs/ARCHITECTURE.md`):**
  - Pemisahan tanggung jawab tegas: PHP Analytics Engine untuk perhitungan deterministik vs. AI Orchestrator untuk interpretasi naratif.
  - Arsitektur modular berorientasi layanan (Service-Oriented Architecture) yang 100% kompatibel dengan Shared Hosting tanpa daemon server.
- **Analisis Risiko & Mitigasi (`docs/RISK_ASSESSMENT.md`):**
  - Pemetaan risiko eksekusi timeout, batas memori, penguncian SQLite, fallback WAL mode, dan penanganan rate limit API pihak ketiga.
- **Rancangan Skema Database (`DATABASE_SCHEMA.md`):**
  - Struktur 28 tabel inti dengan foreign key, indeks performa, deteksi duplikasi hash, audit log, dan tabel `schema_migrations`.
- **Prosedur Perlindungan & Pemulihan Database (`BACKUP_RESTORE.md`):**
  - Standarisasi SQLite `VACUUM INTO` atomik yang aman terhadap WAL.
  - Mekanisme pre-migration backup, rolling retention, dan verifikasi `PRAGMA integrity_check`.
- **Panduan Deployment & Pemisahan Fisik Data (`DEPLOYMENT.md`):**
  - Pemisahan tiga pilar direktori: `anabot_app/` (codebase), `anabot_data/` (database & reports), `anabot_secrets/` (kunci enkripsi & .env).
  - Sepuluh aturan mutlak deployment Zero Data Loss.
- **Dokumentasi Internal API (`API_DOCUMENTATION.md`):**
  - Spesifikasi endpoint REST API `/api/v1/` untuk Auth, Connectors, Datasets, AI, dan Reports.
- **Struktur Direktori Skeleton Proyek:**
  - Pembuatan hierarki folder lengkap sesuai standar Bagian 4 spesifikasi dengan file pelindung `.gitkeep` dan `.gitignore`.
- **Konfigurasi Lingkungan Template:**
  - File `.env.example` dengan parameter isolasi path data, timeout database, dan konfigurasi keamanan session.
