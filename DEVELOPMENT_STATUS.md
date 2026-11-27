# Status Pengembangan AnaBot (Development Status)

Terakhir diperbarui: 2026-11-27  
Fase Saat Ini: **FASE 2 — Selesai (Menunggu Persetujuan Masuk FASE 3)**  
Status Integritas Data: **Aman (Seluruh Pengujian Menggunakan Database Testing Terisolasi)**

---

## 1. Roadmap Pengembangan Bertahap (Phased Roadmap)

| Fase | Nama Fase | Status | Keterangan |
| :---: | :--- | :---: | :--- |
| **FASE 0** | **Pemeriksaan Lingkungan & Perencanaan** | **SELESAI** | Runtime PHP 8.4, arsitektur, skema DB, mitigasi risiko, skeleton direktori, dan dokumentasi lengkap. |
| **FASE 1** | **Core System & Database Engine** | **SELESAI** | Bootstrap, Router, Database PDO SQLite, Auth, Security, Migration runner (`schema_migrations`), Logger, 6/6 test suites lulus. |
| **FASE 2** | **Dashboard & UI Framework** | **SELESAI** | Layout modern SaaS, responsive desktop & mobile, dark/light mode, Chart.js lokal, 10 menu modul, bento KPI aktual, 7/7 test suites lulus. |
| **FASE 3** | **Database & Data Explorer** | *Menunggu Persetujuan* | Dataset manager, schema discovery, data records browser, search, filter, pagination, streaming CSV export. |
| **FASE 4** | **Connector Framework** | *Belum Mulai* | Interface konektor standar, Credential manager, AES-256-GCM, rate limiter, queue & sync log. |
| **FASE 5** | **Integrasi Pertama (Google Sheets)** | *Belum Mulai* | OAuth/Service auth Google Sheets, test connection, normalisasi data, incremental sync via SQLite. |
| **FASE 6** | **Analytics Engine (PHP)** | *Belum Mulai* | Mesin statistik deskriptif murni PHP, growth, korelasi, pergerakan tren, audit trail deterministik. |
| **FASE 7** | **Multi-Provider AI Framework** | *Belum Mulai* | Adapter Gemini, OpenAI, Claude, OpenAI-compatible, usage tracking & budgeting, provider fallback. |
| **FASE 8** | **AI Analyst** | *Belum Mulai* | Context builder berbasis hasil AnalyticsEngine, chat analitis grounded data, sesi percakapan, rekomendasi. |
| **FASE 9** | **Predictive Analytics & Anomaly** | *Belum Mulai* | Deteksi anomali IQR & Z-score, forecasting moving average/exponential smoothing dengan disclaimer asumsi. |
| **FASE 10** | **Report Engine** | *Belum Mulai* | Generator laporan PDF, XLSX, CSV, HTML dengan ringkasan AI dan grafik statistik, unduhan privat terproteksi. |
| **FASE 11** | **Automation & Monitoring** | *Belum Mulai* | Cron job runner (`sync.php`, `process_queue.php`, `backup.php`), rotasi log, audit trail, notifikasi anomali. |
| **FASE 12** | **Integrasi Platform Tambahan** | *Belum Mulai* | Konektor MySQL eksternal, Custom REST API, Google Analytics, YouTube, Meta, TikTok, WooCommerce, Shopify. |
| **FASE 13** | **Final Testing & Deployment Sim** | *Belum Mulai* | Pengujian end-to-end, simulasi update zero-data-loss, audit keamanan ketat, verifikasi kompatibilitas hosting. |

---

## 2. Rincian Capaian FASE 2 (Dashboard & UI Framework)
- [x] Sistem desain SaaS modern dengan palet Cobalt Blue & Deep Navy di `public/assets/css/anabot.css`.
- [x] Pengatur tema Dark Mode dan Light Mode dengan penyimpanan preferensi di `localStorage` (`public/assets/js/anabot.js`).
- [x] Bundling library Chart.js mandiri secara lokal di `public/assets/js/chart.min.js` (bebas dependensi CDN eksternal).
- [x] Master layout responsif (`resources/views/layouts/main.php`), header interaktif (`header.php`), dan sidebar navigasi 10 modul (`sidebar.php`).
- [x] Responsive mobile experience dengan tombol hamburger drawer dan backdrop sentuh overlay.
- [x] Bento Grid 8 kartu metrik KPI dari database SQLite aktual di `resources/views/dashboard/index.php`.
- [x] Grafik pertumbuhan volume baris data (Area Chart) dan donat distribusi token AI (Donut Chart).
- [x] Kepatuhan pelabelan transparan: data aktual ditandai `DATA AKTUAL` dan preview visual saat data kosong ditandai `DEMO` (Rule 1.12 & 1.13).
- [x] Controller ringkasan statistik `app/Controllers/DashboardController.php` dan endpoint API `GET /api/v1/dashboard/stats`.
- [x] Pengendali rute placeholder modul `app/Controllers/ModulePlaceholderController.php` untuk seluruh navigasi di sidebar tanpa error 404.
- [x] Pengujian unit otomatis `tests/Unit/DashboardTest.php` (7/7 test suites lulus 100%).

---

## 3. Rencana Tugas untuk FASE 3 (Database & Data Explorer)
Setelah pengguna memberikan persetujuan untuk FASE 3:
1. Pembangunan antarmuka **Data Explorer** (`/explorer/datasets`, `/explorer/records`, `/explorer/schema`).
2. Implementasi **Dataset Manager**: Menampilkan daftar dataset yang tersimpan, jumlah baris, rentang waktu, dan tanggal sinkronisasi terakhir.
3. Implementasi **Schema Discovery**: Membaca kolom dataset secara dinamis, menentukan tipe data (TEXT, INTEGER, NUMERIC, DATETIME), serta menetapkan peran metrik atau dimensi.
4. Implementasi **Records Browser**: Menampilkan data baris ternormalisasi dengan fitur pencarian teks, filter kolom, sorting naik/turun, dan pagination cepat SQLite (LIMIT/OFFSET).
5. Implementasi **Streaming CSV Exporter**: Ekspor dataset ke format CSV menggunakan generator PHP (`yield`) untuk mencegah penggunaan memori berlebih pada shared hosting.
