# Changelog AnaBot (AI Analytics Robot)

Seluruh perubahan penting pada proyek AnaBot dicatat dalam dokumen ini. Format mengikuti standar [Keep a Changelog](https://keepachangelog.com/id/1.0.0/).

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
