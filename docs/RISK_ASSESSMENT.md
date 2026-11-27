# Analisis Risiko Teknis & Strategi Mitigasi (Shared Hosting & SQLite)

Dokumen ini memetakan seluruh risiko operasional, teknis, dan infrastruktur terkait penggunaan **PHP Native 8.4**, **SQLite 3**, dan **Shared Hosting** untuk platform **AnaBot**, disertai dengan langkah mitigasi arsitektural konkret.

---

## 1. Matriks Risiko Lingkungan Shared Hosting

| Kategori Risiko | Deskripsi Risiko | Tingkat Dampak | Probabilitas | Solusi & Strategi Mitigasi Arsitektur |
| :--- | :--- | :--- | :--- | :--- |
| **Batas Waktu Eksekusi (Max Execution Time)** | Shared hosting biasanya membatasi `max_execution_time` antara 30 hingga 60 detik. Proses sinkronisasi data besar dapat terputus di tengah jalan. | **TINGGI** | **TINGGI** | 1. Implementasi **Incremental Sync** berbasis cursor/pagination.<br>2. Pemotongan batch fetch per halaman (misal 50-100 record per request API).<br>3. Penyimpanan cursor state (`sync_cursors`) setelah setiap batch berhasil di-commit.<br>4. Penggunaan background chunking melalui script CLI Cron (`cron/process_queue.php`) yang memiliki batas waktu lebih longgar. |
| **Batas Memori PHP (Memory Limit)** | Shared hosting membatasi RAM PHP per proses (umumnya 128MB - 256MB). Memuat ribuan record ke memori dapat memicu `Fatal Error: Allowed memory size exhausted`. | **TINGGI** | **SEDANG** | 1. **Streaming & Cursor Iteration:** Jangan pernah melakukan `fetchAll()` pada tabel besar.<br>2. Menggunakan SQLite cursor fetching (`fetch(PDO::FETCH_ASSOC)`) baris demi baris.<br>3. Generator PHP (`yield`) pada proses normalisasi dan ekspor CSV/XLSX.<br>4. Agregasi statistik dilakukan langsung via SQL query (`COUNT`, `AVG`, `SUM`, `MIN`, `MAX`) sebelum diserahkan ke PHP. |
| **Penguncian Database (Database Locking)** | SQLite menggunakan penguncian file tingkat database untuk operasi tulis. Dua proses simultan (misal sync cron dan aktivitas web user) dapat menghasilkan error `database is locked`. | **TINGGI** | **SEDANG** | 1. Konfigurasi `PRAGMA busy_timeout = 5000;` (menunggu hingga 5 detik sebelum melempar exception).<br>2. Aktifkan `PRAGMA journal_mode = WAL;` (Write-Ahead Logging) sehingga pembaca (readers) tidak memblokir penulis (writers) dan sebaliknya.<br>3. Transaksi singkat: Buka transaksi `beginTransaction()` hanya tepat sebelum batch write, dan segera `commit()`. Hindari memanggil API eksternal saat transaksi database sedang terbuka. |
| **Filesystem WAL Incompatibility** | Beberapa filesystem shared hosting berbasis jaringan (seperti NFS atau network mounts tertentu) tidak mendukung locking memori bersama (`.shm`) yang dibutuhkan mode WAL. | **SEDANG** | **RENDAH** | 1. Deteksi otomatis saat inisialisasi koneksi.<br>2. Jika eksekusi `PRAGMA journal_mode = WAL;` gagal atau mengembalikan status non-WAL, sistem secara otomatis melakukan fallback ke `PRAGMA journal_mode = TRUNCATE;` atau `DELETE;` dan mencatat warning pada audit log tanpa merusak database. |
| **Paparan File Database ke Publik** | Jika file `anabot.sqlite` disimpan di dalam `public_html` atau DocumentRoot, pihak asing berpotensi mengunduh seluruh database via HTTP. | **KRITIS** | **SEDANG** | 1. **Pemisahan Jalur Fisik:** Database diletakkan di luar DocumentRoot (`/home/username/anabot_data/anabot.sqlite`).<br>2. Layer pertahanan ganda: Pada direktori lokal manapun, file `.htaccess` dan `web.config` menyertakan aturan `Deny from all` untuk ekstensi `.sqlite`, `.db`, `.log`, `.key`, `.env`.<br>3. Verifikasi startup: Aplikasi menolak boot jika mendeteksi file database berada langsung di dalam folder web public. |
| **Ketiadaan CLI Cron / Shell Access** | Pada beberapa paket shared hosting paling murah, akses SSH/CLI dimatikan, hanya tersedia web cron (HTTP request). | **SEDANG** | **SEDANG** | 1. AnaBot menyediakan dua opsi pemicu cron:<br>   - Jalur Utama (Rekomendasi): CLI `php /path/to/anabot/cron/sync.php`<br>   - Jalur Alternatif: Web endpoint dengan proteksi rahasia `GET /cron/run?token=SECRET_CRON_TOKEN` yang menerapkan rate-limit ketat dan IP restriction opsional. |

---

## 2. Risiko Integrasi Platform Eksternal (Connectors)

| Kategori Risiko | Deskripsi Risiko | Mitigasi Arsitektur |
| :--- | :--- | :--- |
| **Kedaluwarsa OAuth Token** | Refresh token Google / Meta dapat kedaluwarsa atau dicabut oleh pengguna dari akun eksternal. | 1. Sistem memeriksa timestamp masa berlaku token (`expires_at`) sebelum setiap request API.<br>2. Refresh token otomatis dijalankan jika masa berlaku tersisa kurang dari 5 menit.<br>3. Jika refresh gagal, koneksi ditandai `NEEDS_REAUTH` dan notifikasi dibuat di dashboard tanpa menghentikan modul lainnya. |
| **Rate Limiting Platform (HTTP 429)** | Melebihi kuota request per menit pada Google API, YouTube API, Meta Graph API. | 1. Deteksi header respons rate limit (`Retry-After`, `X-RateLimit-Reset`).<br>2. Pola Exponential Backoff dengan jitter acak pada level cURL client.<br>3. Maksimal retry terbatas (3 kali). Jika tetap gagal, job dijeda dan status cursor dicatat. |
| **Perubahan Skema Data Eksternal** | Kolom pada Google Sheets atau REST API eksternal diubah, dihapus, atau diubah tipe datanya oleh pengguna. | 1. `DataProcessor` menjalankan validasi skema sebelum normalisasi.<br>2. Kolom baru diidentifikasi dan dicatat pada `dataset_columns`.<br>3. Nilai yang tidak sesuai tipe divalidasi dan diisi `NULL` atau nilai default tanpa menggagalkan seluruh dataset. |
| **Akses Modifikasi Tidak Sengaja** | Risiko data pada platform eksternal terubah atau terhapus. | 1. **Prinsip READ-ONLY Mutlak:** Seluruh konektor hanya menggunakan OAuth scopes baca (misal: `spreadsheets.readonly`, `analytics.readonly`).<br>2. Ketiadaan method HTTP `POST`, `PUT`, `DELETE` ke resource data milik pihak ketiga dalam konektor. |

---

## 3. Risiko Multi-Provider AI

| Kategori Risiko | Deskripsi Risiko | Mitigasi Arsitektur |
| :--- | :--- | :--- |
| **Halusinasi Angka & Fakta** | Model AI dapat menghasilkan angka fiktif yang terdengar meyakinkan tetapi tidak akurat. | 1. **Aturan Mutlak:** AI dilarang melakukan perhitungan langsung. Semua statistik dihitung oleh PHP `AnalyticsEngine`.<br>2. Prompt grounding: AI hanya menerima prompt sistem yang membatasi analisis pada data tabel agregat yang telah divalidasi.<br>3. Sistem menyertakan instruksi tegas pada system prompt: *"Jangan pernah menciptakan angka yang tidak tercantum dalam data analitik yang disediakan."* |
| **Lonjakan Biaya API AI** | Pengguna menjalankan analisis data besar berulang kali yang menghabiskan token API (OpenAI/Claude/Gemini). | 1. Peringkasan kontekstual: Data dikirim dalam bentuk ringkasan statistik dan sampel representatif, bukan jutaan baris mentah.<br>2. Pencatatan pemakaian token (`ai_usage_logs`) secara terperinci setiap kali request selesai.<br>3. Fitur batas anggaran/kuota bulanan (Usage Limits) di dashboard yang memblokir request baru jika ambang batas terlampaui. |
| **Downtime / Gangguan Provider AI** | Salah satu provider AI (misal Gemini atau OpenAI) mengalami gangguan layanan (HTTP 500/503). | 1. Pola **Provider Fallback**: Jika provider utama gagal setelah retry, `AIOrchestrator` dapat secara otomatis beralih ke provider cadangan (secondary provider) yang telah dikonfigurasi pengguna.<br>2. Feedback error yang ramah dan transparan pada antarmuka pengguna. |
| **Prompt Injection dari Data Sumber** | Data mentah dari Google Sheets atau API eksternal yang diinput pihak ketiga mengandung teks injeksi prompt (misal: *"Ignore previous instructions..."*). | 1. Data eksternal di-encode dalam format terisolasi (JSON payload bertanda jelas).<br>2. Pemisahan tegas antara `System Instructions` dan `Data Context Block`.<br>3. Sanitasi karakter kontrol sebelum data diinjeksikan ke dalam payload prompt. |

---

## 4. Rencana Pemulihan Bencana (Disaster Recovery Plan)
1. **Pemeriksaan Integritas Berkala:** Cron harian menjalankan `PRAGMA integrity_check;`. Jika terdeteksi anomali pada database, status darurat diaktifkan dan administrator diberitahu.
2. **Rolling Backup Retention:** Sistem mempertahankan minimal 7 backup harian terakhir dan 4 backup mingguan di direktori `storage/backups/`.
3. **Mekanisme Pre-Migration Backup:** Setiap eksekusi migration baru selalu mendahului proses dengan snapshot backup SQLite penuh. Jika migration gagal di tengah jalan, database dapat dipulihkan ke snapshot tersebut secara instan.
