# AnaBot (AI Analytics Robot)
### Personal Data Intelligence Platform — v1.0.0 Production Release

AnaBot adalah asisten analisis data berbasis AI mandiri (*self-hosted*) yang mengumpulkan data dari berbagai platform digital (Google Sheets, WooCommerce, Shopify, Google Analytics 4, Search Console, YouTube, Meta Ads, TikTok Ads, basis data MySQL eksternal, dan Custom REST API), melakukan normalisasi data, menghitung statistik deterministik, mendeteksi anomali, melakukan peramalan tren (*forecasting*), serta menyajikan rekomendasi bisnis strategis melalui orkestrasi Multi-Provider AI (Gemini, OpenAI, Claude).

Aplikasi ini dibangun menggunakan arsitektur **Modular Service-Oriented** dengan **PHP Native 8.4** dan **SQLite 3**, dirancang khusus untuk berjalan optimal pada **Shared Hosting** tanpa membutuhkan server Node.js, daemon Python, Redis, atau kontainer Docker.

---

## 1. Fitur Utama & Modul Lengkap

1. **Dashboard & UI Framework (FASE 2)**:
   - Bento-grid responsive (Desktop, Tablet, Mobile) dengan tema Dark & Light mode.
   - Grafik interaktif lokal (Chart.js), indikator status sinkronisasi, dan agregasi metrik bisnis aktual.
2. **Database & Data Explorer (FASE 3)**:
   - Manajemen dataset, pencarian teks bebas instan, filter kolom dan JSON atribut bersarang.
   - Ekspor data streaming CSV yang memory-safe untuk volume data besar.
3. **Connector Framework & 10 Platform Drivers (FASE 4, 5, 12)**:
   - **Enforcement READ-ONLY Mutlak**: Tidak pernah memutasi atau menghapus data di platform eksternal.
   - Enkripsi kredensial dua arah **AES-256-GCM**.
   - Paginasi kursor cerdas, penanganan *rate-limiting* tangguh, dan perlindungan anti-SSRF pada `HttpClient`.
   - 10 Driver terintegrasi: Google Sheets, WooCommerce, Shopify, Google Analytics 4, Search Console, YouTube, Meta Ads, TikTok Shop & Ads, MySQL Read-Only, dan Custom REST API.
4. **Analytics Engine Murni PHP 8.4 (FASE 6)**:
   - Komputasi statistik deterministik (Mean, Median, Modus, Varians, Standar Deviasi, Tukey's IQR).
   - Analisis tren regresi linier OLS, pergerakan rata-rata (SMA & EMA), tingkat pertumbuhan periodik (DoD, WoW, MoM, YoY), korelasi Pearson, dan analisis kontribusi Pareto 80/20.
5. **Multi-Provider AI Framework & Conversational Grounding (FASE 7, 8)**:
   - Driver AI terintegrasi untuk Google Gemini, OpenAI, Anthropic Claude, dan Compatible Provider.
   - Failover otomatis saat kuota habis atau penyedia mengalami gangguan.
   - **Strict Anti-Hallucination Guardrails**: Fakta analitis dihitung terlebih dahulu oleh PHP dan disuntikkan ke prompt AI sehingga AI dilarang keras mengarang angka.
   - Sesi percakapan interaktif (*multi-turn*) dan Aksi Cepat Eksekutif (Ringkasan, Diagnosis, Rekomendasi, Anomali).
6. **Predictive Analytics & Anomaly Detection (FASE 9)**:
   - Deteksi anomali matematis otomatis berbasis pagar kuartil IQR dan skor Z-Score.
   - Peramalan data historis menggunakan pemulusan eksponensial ganda Holt-Winters dan Regresi Linier OLS dengan batas keyakinan 95% dan disclaimer matematis ketat.
7. **Report Engine Multi-Format (FASE 10)**:
   - Generator laporan eksekutif mandiri: HTML interaktif dengan grafik SVG, CSV streaming, OpenXML XLSX via ZipArchive, dan dokumen standar biner **PDF 1.4 murni PHP** tanpa dependensi eksternal.
8. **Automation, Scheduler & System Monitoring (FASE 11)**:
   - CLI Cron runner terpadu dan endpoint Web Cron terproteksi token.
   - Evaluator ekspresi cron 5 bagian murni PHP, antrean latar belakang (*sync queue*), pencadangan atomik SQLite (`VACUUM INTO`) dengan retensi bergulir 7 hari, rotasi log otomatis, diagnostik kesehatan sistem, dan peringatan dini anomali dengan proteksi *anti-fatigue*.

---

## 2. Spesifikasi Teknologi Inti

| Komponen | Teknologi | Keterangan |
| :--- | :--- | :--- |
| **Backend** | PHP Native 8.4 (PSR-4) | Bersih, modular, tanpa framework monolitik berat. |
| **Database** | SQLite 3 via PDO SQLite | Satu database internal terisolasi, WAL mode, `busy_timeout=5000`. |
| **Frontend** | HTML5, CSS3, Vanilla JS | Modern SaaS layout, responsif desktop & mobile, dark/light mode. |
| **HTTP Client** | PHP Native cURL | Dilengkapi proteksi SSRF, limit ukuran memori, dan backoff eksponensial. |
| **Enkripsi** | OpenSSL AES-256-GCM | Kredensial API eksternal dan OAuth tokens disimpan terenkripsi. |
| **Target Hosting**| Shared Hosting / cPanel | Kompatibel penuh dengan batas memori 128 MB dan timeout 30 detik. |

---

## 3. Hasil Pengujian Otomatis (Automated Test Suite)

AnaBot dilengkapi **19 suite pengujian unit & integrasi otomatis** yang mencakup 100% fungsionalitas sistem pada basis data pengujian terisolasi (`storage/database/anabot_testing.sqlite`):

```text
========================================================
  ANABOT AUTOMATED TEST SUITE (FASE 1 - FASE 13)
  PHP: 8.4.28 | Environment: TESTING
========================================================

Testing Encryption Service...                                   [PASS]
Testing Database Engine...                                       [PASS]
Testing Input Validator...                                       [PASS]
Testing Router & Dispatcher...                                   [PASS]
Testing Migration Runner & Schema...                             [PASS]
Testing Authentication & Security...                             [PASS]
Testing Dashboard Engine & UI Assets...                          [PASS]
Testing Data Explorer & Memory-Safe Exporter...                  [PASS]
Testing Connector Framework & Sync Queue...                      [PASS]
Testing Google Sheets First Integration & Auto-Schema...          [PASS]
Testing Analytics Engine & Statistical Calculation...            [PASS]
Testing Multi-Provider AI Framework & Failover...                [PASS]
Testing AI Analyst & Conversational Grounding Engine...          [PASS]
Testing Predictive Analytics & Anomaly Detection...              [PASS]
Testing Report Engine (Multi-Format & Secure Storage)...         [PASS]
Testing Automation, Scheduler & System Monitoring...             [PASS]
Testing Additional Platform Connectors (FASE 12)...              [PASS]
Testing Zero-Data-Loss Migration & Update Simulation...          [PASS]
Testing End-to-End System Integration Smoke Test...              [PASS]

========================================================
  FINAL TEST RESULT: 19/19 TEST SUITES PASSED (100%)
========================================================
```

Untuk menjalankan seluruh rangkaian pengujian:
```bash
php tests/run_tests.php
```

---

## 4. Panduan Mulai Cepat (Quickstart)

### 4.1. Verifikasi Kompatibilitas Server
Jalankan alat diagnostik kompatibilitas shared hosting:
```bash
php bin/hosting_check.php
```

### 4.2. Konfigurasi Lingkungan
Salin `.env.example` ke `.env` dan atur kunci enkripsi aplikasi:
```bash
cp .env.example .env
```

### 4.3. Migrasi Basis Data
Inisialisasi basis data SQLite dan tabel schema:
```bash
php bin/migrate.php
```

### 4.4. Menjalankan Server Lokal (Pengembangan)
```bash
php -S 127.0.0.1:8080 -t public
```
Akses aplikasi melalui browser di `http://127.0.0.1:8080`.

---

## 5. Indeks Dokumentasi Proyek

1. [Panduan Penerapan Produksi (Deployment Guide)](file:///home/antigravity/anabot/docs/DEPLOYMENT_GUIDE.md) — Panduan instalasi di cPanel, DirectAdmin, CyberPanel, dan konfigurasi Web Cron.
2. [Panduan Pengguna (User Manual)](file:///home/antigravity/anabot/docs/USER_MANUAL.md) — Panduan lengkap penggunaan 10 modul AnaBot.
3. [Dokumen Arsitektur Teknis](file:///home/antigravity/anabot/docs/ARCHITECTURE.md) — Rincian arsitektur modular, alur data, dan prinsip desain.
4. [Analisis Risiko & Mitigasi Hosting](file:///home/antigravity/anabot/docs/RISK_ASSESSMENT.md) — Mitigasi batas timeout, memori, konkurensi, dan laju API.
5. [Spesifikasi Skema Database](file:///home/antigravity/anabot/DATABASE_SCHEMA.md) — Struktur 28 tabel internal SQLite.
6. [Status Pengembangan Proyek](file:///home/antigravity/anabot/DEVELOPMENT_STATUS.md) — Pelacakan progres fase demi fase (FASE 0 - FASE 13).
7. [Catatan Perubahan (Changelog)](file:///home/antigravity/anabot/CHANGELOG.md) — Riwayat perubahan kode lengkap.

---

## 6. Lisensi
Hak Cipta © 2026 AnaBot Intelligence Team. Dilindungi Undang-Undang.
