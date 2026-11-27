# AnaBot (AI Analytics Robot)
### Personal Data Intelligence Platform

AnaBot adalah asisten analisis data berbasis AI yang dirancang untuk mengumpulkan data dari berbagai platform digital (Google Sheets, MySQL eksternal, REST API, e-commerce, dsb.), melakukan normalisasi, menghitung statistik deterministik, mendeteksi anomali, melakukan peramalan tren (forecasting), serta menyajikan rekomendasi bisnis melalui orkestrasi Multi-Provider AI (Gemini, OpenAI, Claude).

Aplikasi ini dibangun menggunakan arsitektur **Modular Service-Oriented** dengan **PHP Native 8.4** dan **SQLite 3**, dirancang khusus untuk berjalan optimal pada **Shared Hosting** tanpa membutuhkan server Node.js, daemon Python, Redis, atau kontainer Docker.

---

## 1. Spesifikasi Teknologi Inti

| Komponen | Teknologi | Keterangan |
| :--- | :--- | :--- |
| **Backend** | PHP Native 8.4 | Menggunakan standar PSR-4, modular, bersih, tanpa framework monolitik berat. |
| **Database** | SQLite 3 via PDO SQLite | Satu-satunya database internal (`anabot.sqlite`), WAL journal mode, busy timeout 5000ms. |
| **Frontend** | HTML5, CSS3, Vanilla JS | Responsive modern SaaS dashboard, Dark & Light mode, Mobile friendly (Android/iOS). |
| **HTTP Client** | PHP cURL | Eksekusi aman dengan timeout, limit ukuran respons, dan proteksi SSRF. |
| **Enkripsi** | OpenSSL AES-256-GCM | Kredensial API eksternal dan OAuth tokens disimpan terenkripsi secara aman. |
| **AI Engine** | Multi-Provider AI Adapter | Dukungan untuk Google Gemini, OpenAI, Anthropic Claude, dan compatible provider. |
| **Automasi** | CLI Cron Jobs | Sinkronisasi data terjadwal, pembuatan laporan, antrean tugas, dan backup otomatis. |
| **Target Hosting**| Shared Hosting (cPanel/DirectAdmin)| Kompatibel penuh dengan batasan memori, waktu eksekusi, dan tanpa dependensi root daemon. |

---

## 2. Prinsip Pemisahan Komputasi vs Interpretasi

AnaBot menerapkan aturan mutlak pemisahan tanggung jawab:
- **PHP Analytics Engine:** Menghitung total, rata-rata, median, standar deviasi, persentase pertumbuhan, moving average, korelasi Pearson, anomali IQR/Z-score, dan regresi proyeksi. Semua angka dihasilkan secara deterministik dan dapat diverifikasi.
- **AI Orchestrator:** Menerima hasil agregat dari engine PHP dan bertindak sebagai analis bisnis yang menjelaskan pola, mengidentifikasi faktor pemicu, menjawab pertanyaan pengguna, dan menyusun rekomendasi naratif.
- **AI DILARANG berhalusinasi atau mengarang angka** yang tidak berasal dari hasil perhitungan data aktual.

---

## 3. Struktur Direktori Source Code

```
anabot/
├── app/
│   ├── Core/                  # Router, Database, Auth, Validator, Encryption, Logger
│   ├── Controllers/           # HTTP Request/Response Handlers
│   ├── Models/                # Data Access Layer & Repositories
│   ├── Services/              # AnalyticsEngine, DataProcessor, DataCollector, QueueManager
│   ├── Connectors/            # GoogleSheets, MySQL, CustomAPI, Analytics, Social
│   ├── AI/                    # Multi-Provider Adapters (Gemini, OpenAI, Claude) & PromptManager
│   ├── Middleware/            # Auth, CSRF, RateLimiter
│   └── Helpers/               # Global helper functions
├── config/                    # Konfigurasi app, database, security, ai
├── database/
│   ├── migrations/            # Versioned migrations (schema_migrations)
│   └── seeders/               # Non-destructive test seeders
├── public/                    # Web root (index.php, CSS, JS, Assets, .htaccess)
├── resources/views/           # Layouts, dashboard, explorer, analytics, reports
├── storage/                   # Logs, reports, backups, cache, temp, queue
├── cron/                      # CLI Cron jobs (sync.php, process_queue.php, backup.php)
├── routes/                    # Definisi web.php dan api.php
├── tests/                     # Automated unit and integration tests
├── docs/                      # Dokumentasi arsitektur dan risiko hosting
├── .env.example               # Template variabel lingkungan
├── composer.json              # Autoloading PSR-4
└── README.md                  # Dokumentasi proyek utama
```

---

## 4. Perlindungan Database SQLite & Pemisahan Fisik Data

Sesuai aturan arsitektur AnaBot, database SQLite dan file konfigurasi sensitif dipisahkan dari folder deployment source code agar data pengguna tidak pernah tertimpa saat update aplikasi:

```
/home/username/
├── anabot_app/       # Source code (diperbarui via Git / upload ZIP)
├── anabot_data/      # anabot.sqlite, backups/, reports/, logs/ (TIDAK DITIMPA)
└── anabot_secrets/   # .env, encryption.key (TIDAK DITIMPA)
```

---

## 5. Indeks Dokumentasi Proyek

1. [Dokumen Arsitektur Teknis](file:///home/antigravity/anabot/docs/ARCHITECTURE.md) — Penjelasan detail arsitektur modular, alur data, dan prinsip desain.
2. [Analisis Risiko & Mitigasi Hosting](file:///home/antigravity/anabot/docs/RISK_ASSESSMENT.md) — Pemetaan batas waktu eksekusi, memori, penguncian SQLite, dan penanganan rate limit.
3. [Spesifikasi Skema Database](file:///home/antigravity/anabot/DATABASE_SCHEMA.md) — Rincian 28 tabel inti, indeks performa, foreign keys, dan field types.
4. [Panduan Deployment Produksi](file:///home/antigravity/anabot/DEPLOYMENT.md) — 10 Aturan Mutlak Zero Data Loss dan alur pembaruan aplikasi di Shared Hosting.
5. [Prosedur Backup & Restore](file:///home/antigravity/anabot/BACKUP_RESTORE.md) — Mekanisme SQLite VACUUM INTO atomik, integritas data, dan pemulihan darurat.
6. [Dokumentasi Internal REST API](file:///home/antigravity/anabot/API_DOCUMENTATION.md) — Format envelope standar dan endpoint `/api/v1/`.
7. [Status Pengembangan Proyek](file:///home/antigravity/anabot/DEVELOPMENT_STATUS.md) — Pelacakan progres fase demi fase (Roadmap Phase 0-13).
8. [Catatan Perubahan (Changelog)](file:///home/antigravity/anabot/CHANGELOG.md) — Riwayat perubahan kode dan pembaruan versi.

---

## 6. Status Saat Ini: FASE 0 Selesai
FASE 0 (Pemeriksaan Lingkungan & Perencanaan Teknis) telah tuntas diselesaikan. Semua pemeriksaan sistem dinyatakan lulus dan arsitektur siap diimplementasikan ke **FASE 1: Core System** setelah mendapatkan persetujuan pengguna.
