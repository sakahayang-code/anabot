# Panduan Pengguna AnaBot (User Manual)
**Platform Inteligensi Data Pribadi & Robot Analisis AI**  
**Versi:** 1.0.0

---

## 1. Pengenalan AnaBot

AnaBot adalah platform analitis data pribadi mandiri (*self-hosted*) yang menggabungkan kekuatan **kalkulasi statistik matematis deterministik** (murni PHP 8.4) dengan kemampuan **penalaran kecerdasan buatan (AI Multi-Provider)** untuk memberikan wawasan bisnis mendalam tanpa halusinasi (*zero hallucination*).

Prinsip Utama:
- **Separation of Concerns**: Angka dihitung secara pasti oleh rumus statistik PHP; AI berfungsi sebagai analis eksekutif yang menjelaskan temuan, mendiagnosis penyebab, dan merekomendasikan strategi tindakan.
- **Strict Read-Only**: AnaBot hanya membaca data dari platform eksternal Anda dan tidak memiliki hak untuk menambah, mengubah, atau menghapus data di platform asal.
- **Privasi Penuh**: Data Anda tersimpan di basis data SQLite lokal Anda sendiri, terenkripsi dua arah (AES-256-GCM).

---

## 2. Navigasi & Modul Utama

| Modul | Menu | Fungsi Utama |
| :--- | :--- | :--- |
| **Dasbor Utama** | `/dashboard` | Ringkasan eksekutif KPI bisnis aktual, grafik performa, dan status sinkronisasi. |
| **Konektor Platform** | `/connectors` | Integrasi sumber data eksternal (Google Sheets, E-Commerce, Iklan, DB, dll). |
| **Eksplorasi Data** | `/data` | Penjelajah baris data, pencarian teks bebas, pemfilteran atribut JSON, dan ekspor CSV. |
| **Mesin Analitik** | `/analytics` | Statistik deskriptif lengkap, korelasi Pearson, tren OLS, dan kontribusi Pareto 80/20. |
| **AI Analyst** | `/analyst` | Percakapan analitis interaktif dengan injeksi fakta data nyata dan tombol aksi cepat. |
| **Prediksi & Anomali**| `/predictive` | Deteksi pencilan/anomali (IQR & Z-score) serta peramalan masa depan (Holt-Winters). |
| **Pusat Laporan** | `/reports` | Generator laporan eksekutif multi-format (HTML interaktif, PDF 1.4, XLSX, CSV). |
| **Otomasi & Jadwal** | `/automation` | Pemantauan tugas cron terjadwal, antrean sinkronisasi, kesehatan sistem, dan cadangan. |
| **Pusat Notifikasi** | `/notifications`| Peringatan in-app untuk anomali data, kegagalan sinkronisasi, dan laporan siap unduh. |
| **Pengaturan** | `/settings` | Konfigurasi profil, kunci enkripsi, preferensi AI, dan cadangan database. |

---

## 3. Menghubungkan Platform Data

Masuk ke menu **Konektor** (`/connectors`), klik **"Tambah Koneksi Platform Baru"**, lalu pilih platform yang diinginkan:

1. **Google Sheets**:
   - Masukkan ID Spreadsheet atau URL lengkap Google Docs.
   - Masukkan OAuth Token atau tempel JSON Google Service Account.
2. **WooCommerce**:
   - Masukkan URL Toko (contoh: `https://tokosaya.com`), Consumer Key (`ck_...`), dan Consumer Secret (`cs_...`).
3. **Shopify**:
   - Masukkan domain toko (contoh: `toko-saya.myshopify.com`) dan Admin Access Token (`shpat_...`).
4. **Google Analytics 4 (GA4)**:
   - Masukkan Property ID 9 digit (contoh: `123456789`) dan OAuth Token / Service Account.
5. **Google Search Console**:
   - Masukkan Site URL atau domain property (contoh: `https://example.com/`) dan OAuth Token.
6. **YouTube Analytics**:
   - Masukkan Channel ID (atau gunakan `mine` untuk channel akun sendiri) dan OAuth Token.
7. **Meta Ads & Graph**:
   - Masukkan Ad Account ID (contoh: `act_1234567890`) dan Access Token dengan scope `ads_read`.
8. **TikTok Shop & Ads**:
   - Masukkan Advertiser ID dan Access Token Marketing API.
9. **MySQL / MariaDB**:
   - Masukkan Host, Port (3306), Nama Basis Data, Username, dan Password akun basis data read-only Anda.
10. **Custom REST API**:
    - Hubungkan endpoint JSON API kustom Anda dengan header autentikasi yang dapat disesuaikan.

Setelah mengisi form, klik tombol **"Uji Koneksi"** untuk memverifikasi kredensial secara live, kemudian klik **"Simpan & Sinkronkan"**.

---

## 4. Eksplorasi Data (Data Explorer)

Pada menu **Eksplorasi Data** (`/data`):
- Pilih dataset yang ingin ditinjau.
- Gunakan kolom **Pencarian** untuk mencari nilai tertentu secara instan di seluruh baris JSON.
- Gunakan **Filter Kolom** untuk menyaring data berdasarkan atribut tertentu (misalnya `category = 'Pakaian'`).
- Klik **"Ekspor Data (CSV)"** untuk mengunduh dataset lengkap secara instan dengan mekanisme streaming memori aman ramah hosting.

---

## 5. Berinteraksi dengan AI Analyst

AI Analyst pada AnaBot dilengkapi **Strict Anti-Hallucination Guardrails**. Sebelum menjawab, sistem menghitung fakta matematis dari dataset Anda dan menyuntikkannya ke prompt AI.

### Cara Menggunakan:
1. Masuk ke menu **AI Analyst** (`/analyst`).
2. Pilih dataset yang aktif untuk dianalisis.
3. Anda dapat mengetik pertanyaan bebas, seperti:
   - *"Bagaimana kinerja pendapatan bulan ini dibandingkan bulan lalu?"*
   - *"Apa produk dengan kontribusi penjualan tertinggi menurut Pareto 80/20?"*
   - *"Apakah ada anomali lonjakan biaya iklan minggu ini?"*
4. Atau gunakan tombol **Aksi Cepat Eksekutif (Quick Actions)**:
   - **Ringkasan Eksekutif**: Rangkuman KPI bisnis dan status tren saat ini.
   - **Diagnosis Akar Masalah**: Analisis mendalam faktor pendorong atau penghambat kinerja.
   - **Rekomendasi Strategis**: Langkah taktis yang disarankan berdasarkan data aktual.
   - **Peringatan Anomali**: Identifikasi fluktuasi data tidak wajar yang memerlukan perhatian.

---

## 6. Analitik Prediktif & Deteksi Anomali

Masuk ke menu **Prediksi & Anomali** (`/predictive`):
- **Deteksi Anomali**: Sistem mengevaluasi metrik menggunakan algoritma pagar kuartil **Tukey's IQR** dan skor standar **Z-Score** untuk mendeteksi pencilan ekstrem.
- **Peramalan Tren (Forecasting)**: Menggunakan model pemulusan eksponensial ganda **Holt-Winters** dan **Regresi Linier OLS** dengan batas interval keyakinan 95%.
- *Catatan Kepatuhan*: Seluruh ramalan dilengkapi disclaimer matematis ketat bahwa proyeksi adalah ekstrapolasi tren historis dan bukan jaminan kepastian masa depan.

---

## 7. Pembuatan & Ekspor Laporan

Masuk ke menu **Laporan** (`/reports`):
1. Klik **"Buat Laporan Baru"**.
2. Pilih dataset, metrik utama, dan rentang tanggal analisis.
3. Pilih format keluaran:
   - **HTML Interaktif**: Laporan visual modern lengkap dengan grafik SVG, KPI cards, dan ringkasan eksekutif.
   - **Dokumen PDF 1.4**: Berkas PDF resmi standar biner yang digenerasi murni oleh PHP tanpa dependensi alat eksternal.
   - **Spreadsheet XLSX**: Berkas lembar kerja Excel OpenXML multi-sheet via ZipArchive.
   - **Berkas CSV**: Data mentah terformat dengan UTF-8 BOM untuk kompatibilitas Excel global.
4. Klik **"Unduh Laporan"** untuk menyimpan dokumen secara aman.

---

## 8. Pemeliharaan & Monitoring Sistem

Melalui menu **Otomasi** (`/automation`):
- **Jadwal Otomasi**: Pantau status eksekusi cron dan jadwal tugas latar belakang.
- **Kesehatan Sistem**: Pantau kapasitas penyimpanan disk hosting, integritas basis data SQLite, dan mode WAL.
- **Cadangkan Sekarang**: Klik tombol **"Cadangkan Database"** kapan saja untuk menghasilkan berkas cadangan SQLite atomik instan.
