# Dokumen Arsitektur Teknis AnaBot (AI Analytics Robot)

## 1. Ikhtisar Sistem
AnaBot adalah **Personal Data Intelligence Platform** berbasis web yang dirancang khusus untuk berjalan di lingkungan **Shared Hosting** tanpa ketergantungan pada server Node.js, daemon Python, kontainer Docker, atau Redis in-memory cache. 

Sistem ini memadukan:
1. **PHP Native 8.4** sebagai engine backend modular berkinerja tinggi.
2. **SQLite 3 via PDO SQLite** sebagai satu-satunya database internal yang terisolasi aman dari direktori deployment.
3. **Analytics Engine (PHP)** untuk perhitungan matematika, statistik deskriptif, korelasi, deteksi anomali, dan proyeksi tren yang 100% deterministik dan dapat diverifikasi.
4. **AI Orchestrator (Multi-Provider)** untuk menginterpretasikan angka-angka hasil analisis statistik, memberikan narasi kontekstual, dan merumuskan rekomendasi operasional.

```
+---------------------------------------------------------------------------------------+
|                                    ANABOT PLATFORM                                    |
+---------------------------------------------------------------------------------------+
|  FRONTEND LAYER                                                                       |
|  - Modern SaaS Responsive Dashboard (Desktop & Mobile/Android)                       |
|  - HTML5, CSS3 Custom Modern Styling (Light/Dark Mode), Lightweight Chart JS        |
+---------------------------------------------------------------------------------------+
                                          | HTTP / AJAX / Session Auth
+---------------------------------------------------------------------------------------+
|  PRESENTATION & ROUTING LAYER                                                         |
|  - Front Controller (public/index.php) -> Core\Router -> Controllers                  |
|  - Security Middleware: Auth, CSRF, Rate Limiter (Throttler), Input Sanitizer         |
+---------------------------------------------------------------------------------------+
                                          |
+---------------------------------------------------------------------------------------+
|  APPLICATION SERVICE LAYER                                                            |
|  +---------------------+  +---------------------+  +-------------------------------+  |
|  |  ConnectorManager   |  |   DataCollector     |  |       DataProcessor           |  |
|  |  (OAuth/API/Creds)  |  |   (Fetch & Cursor)  |  |       (Normalize & Hash)      |  |
|  +---------------------+  +---------------------+  +-------------------------------+  |
|  +---------------------+  +---------------------+  +-------------------------------+  |
|  |   AnalyticsEngine   |  |   AIOrchestrator    |  |        ReportEngine           |  |
|  |   (Pure Math/Stats) |  |   (Multi-Provider)  |  |   (PDF, XLSX, CSV, HTML)      |  |
|  +---------------------+  +---------------------+  +-------------------------------+  |
+---------------------------------------------------------------------------------------+
            |                                                      |
            v (Persist Normalized Records)                         v (Context Prompts)
+------------------------------------------+       +------------------------------------+
|  DATA ACCESS & STORAGE LAYER             |       |  EXTERNAL SERVICES LAYER           |
|  - Core\Database (PDO SQLite Driver)     |       |  - Read-Only Connectors            |
|  - Foreign Keys, WAL Journal, Timeout    |       |    (Google Sheets, MySQL, APIs)    |
|  - Isolated Path: anabot_data/           |       |  - Multi-Provider AI (cURL HTTPS)  |
|  - Backup & Integrity Verification       |       |    (Gemini, OpenAI, Claude, etc.)  |
+------------------------------------------+       +------------------------------------+
```

---

## 2. Prinsip Pemisahan Komputasi vs. Interpretasi (Strict Separation of Concerns)
Salah satu prinsip arsitektur paling fundamental dalam AnaBot adalah **tidak pernah membiarkan AI mengarang angka statistik**:

1. **Mesin Analitik PHP (Analytics Engine):**
   - Menghitung nilai agregat: Total, Mean, Median, Min, Max, Modus.
   - Menghitung dispersi: Variance, Standar Deviasi, Interquartile Range (IQR).
   - Menghitung dinamika waktu: Growth Rate, Month-over-Month (MoM), Year-over-Year (YoY), Moving Average (SMA/EMA).
   - Menghitung relasi: Korelasi Pearson, Spearman rank.
   - Deteksi anomali: Metode Z-Score ($|z| > 2.5/3.0$) dan Tukey's Fences ($Q_1 - 1.5 \times IQR$, $Q_3 + 1.5 \times IQR$).
   - Proyeksi & Prediksi: Holt-Winters / Single Exponential Smoothing dengan rentang kepercayaan (confidence interval) dan batas asumsi eksplisit.
   - Semua perhitungan menghasilkan audit trail angka yang dapat ditelusuri ke record sumber (`data_records`).

2. **Mesin AI (AI Orchestrator):**
   - Menerima rangkuman terstruktur (JSON/Markdown summary) hasil perhitungan `AnalyticsEngine`.
   - Mengubah angka menjadi narasi bisnis dan sintesis eksekutif.
   - Menjawab pertanyaan pengguna dalam sesi chat analitis berbasis data terukur (Data-Grounded QA).
   - Menyajikan rekomendasi strategis berdasarkan tren yang teridentifikasi.
   - **Batasan Ketat AI:** AI **tidak memiliki akses langsung** untuk mengeksekusi raw SQL terhadap SQLite, tidak memegang kredensial eksternal/kunci enkripsi, dan tidak diperkenankan menghasilkan klaim numerik yang tidak ada dalam konteks analitik yang diberikan.

---

## 3. Topologi Lingkungan Shared Hosting
Untuk memastikan kompatibilitas penuh dengan shared hosting (cPanel, DirectAdmin, dsb.), AnaBot mematuhi batasan lingkungan berikut:

| Aspek Lingkungan | Karakteristik Shared Hosting | Solusi Arsitektur AnaBot |
| :--- | :--- | :--- |
| **Server Daemon** | Tidak ada izin menjalankan daemon permanen / supervisord | Menggunakan eksekusi **On-Demand** dan **CLI Cron Jobs** standar cPanel. |
| **Antrean (Queue)** | Tidak ada Redis atau RabbitMQ | File-based / SQLite-based lightweight queue (`sync_jobs` / `storage/queue/`) dengan lock atomik. |
| **PHP Runtime** | PHP 8.4 CLI & FPM/CGI | Mengoptimalkan fitur native PHP 8.4 (property hooks, asymmetric visibility, typed constants, native cURL & OpenSSL). |
| **Filesystem & WAL** | Filesystem terkadang berbasis NFS/Gluster (jika clustered) | Default menggunakan `PRAGMA journal_mode = WAL;`. Jika shared hosting tidak mendukung shm/wal file locking, fallback otomatis ke `TRUNCATE` atau `DELETE` mode dengan busy timeout 5000ms. |
| **Web Root Exposure** | Folder `public_html` dapat diakses langsung oleh publik | Memisahkan source code dan database di luar `public_html`, hanya file di folder `public/` (index.php, css, js) yang berada di DocumentRoot atau diarahkan via `.htaccess`. |

---

## 4. Pola Desain Modul (Design Patterns)
1. **Front Controller Pattern:** Seluruh web request masuk melalui satu gerbang utama di `public/index.php`.
2. **Registry / Service Container:** `Core\App` mengelola singleton service dan resolusi dependency.
3. **Repository / Model Pattern:** Abstraksi akses tabel SQLite dengan prepared statements wajib.
4. **Adapter Pattern (Connectors & AI Providers):**
   - `Connectors\ConnectorInterface`: Standardisasi seluruh komunikasi dengan platform eksternal (`connect`, `testConnection`, `fetchData`, `validatePermissions`, `getNextCursor`, `disconnect`).
   - `AI\Contracts\AIProviderInterface`: Standardisasi komunikasi AI (`sendMessage`, `analyzeDataset`, `testConnection`, `getModelList`).
5. **Strategy & Pipeline Pattern:** Pemrosesan data mentah melalui pipeline validasi, normalisasi schema, hashing duplikasi, dan persistensi.

---

## 5. Standar Keamanan Sistem
1. **Enkripsi Kredensial:** Semua OAuth Token, API Key, dan data sensitif konektor disimpan terenkripsi dengan algoritma **AES-256-GCM** menggunakan `openssl_encrypt()` dengan authenticated tag dan Initialization Vector (IV) unik per entri.
2. **Kunci Enkripsi Terpisah:** Kunci enkripsi aplikasi (`APP_KEY`) disimpan di file `.env` yang berlokasi di direktori terpisah (`anabot_secrets/`), terisolasi dari database SQLite dan source code.
3. **Prepared Statements:** 100% query database menggunakan parameter binding PDO (`:param`). Tidak ada string concatenation pada query SQL.
4. **CSRF & Session Security:** Session cookie dikonfigurasi dengan flag `HttpOnly`, `SameSite=Lax` (atau `Strict`), dan `Secure` ketika HTTPS aktif. Setiap form dan mutasi AJAX wajib menyertakan validasi CSRF token.
5. **SSRF Protection:** Pada konektor `CustomAPI`, setiap URL tujuan divalidasi ketat untuk menolak alamat internal (localhost, 127.0.0.1, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, metadata cloud 169.254.169.254).
6. **Masking & Logging Hygiene:** Tidak ada token, API key, atau password yang dicatat dalam log file atau dimunculkan utuh di frontend UI.
