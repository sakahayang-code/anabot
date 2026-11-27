# Changelog AnaBot (AI Analytics Robot)

Seluruh perubahan penting pada proyek AnaBot dicatat dalam dokumen ini. Format mengikuti standar [Keep a Changelog](https://keepachangelog.com/id/1.0.0/).

## [0.12.0-dev] - 2026-11-27 (FASE 11 — Automation & Monitoring)

### Ditambahkan (Added)
- **Sistem CLI Cron Runner Terpadu (`bin/cron/`):**
  - `bin/cron/cron_runner.php`: Master scheduler runner untuk mengevaluasi dan mengeksekusi tugas terjadwal due pada menit berjalan secara otomatis.
  - `bin/cron/sync.php`: Runner sinkronisasi otomatis konektor platform data dengan evaluasi jadwal per koneksi (`sync_interval_minutes`) dan deteksi anomali pasca-sinkronisasi.
  - `bin/cron/process_queue.php`: Background queue worker runner ramah shared hosting dengan batasan waktu eksekusi (*timeout guard*) dan pemrosesan sekuensial.
  - `bin/cron/backup.php`: Pencadangan basis data SQLite otomatis berkala dengan retensi bergulir (*rolling retention*).
  - Mekanisme Penguncian Berkas Konkurensi (`flock`): Proteksi eksklusif non-blocking (`LOCK_EX | LOCK_NB`) pada seluruh skrip cron untuk mencegah eksekusi ganda atau tumpang tindih proses (*overlapping execution*) di shared hosting.
- **Model Penjadwalan & Notifikasi Sistem (`app/Models/`):**
  - `ScheduledTask.php`: Manajemen tabel `scheduled_tasks`, evaluasi ekspresi cron 5 bagian murni PHP (`minute hour day_of_month month day_of_week`), resolusi alias standar (`@daily`, `@hourly`, `@weekly`, `@monthly`), kalkulasi waktu eksekusi berikutnya (`calculateNextRun`), pencegahan duplikasi dalam menit yang sama (`isDue`), dan seeding otomatis 6 tugas default platform.
  - `Notification.php`: Manajemen tabel `notifications` untuk peringatan in-app pengguna, pelacakan status baca/belum baca (`is_read`), aksi tindak lanjut kontekstual (`action_url`), dan penyiaran peringatan sistem (`broadcastToAllUsers`).
- **Layanan Pemantauan Kesehatan & Pemeliharaan Sistem (`app/Services/SystemMonitor.php`):**
  - Diagnostik Kesehatan Sistem Komprehensif (`checkSystemHealth`): evaluasi integritas basis data SQLite (`PRAGMA integrity_check`), mode jurnal (WAL), timeout busy, ukuran berkas DB dan WAL, metrik penyimpanan disk, pemantauan antrean (pekerjaan pending, running, stalled > 30 menit, dan riwayat kegagalan 24 jam), serta status detak jantung cron (*heartbeat*).
  - Rotasi & Kompresi Berkas Log (`rotateLogs`): rotasi otomatis berkas log aktif yang melebihi batas ukuran (10 MB) serta pembersihan berkas log lama yang melampaui batas hari retensi (14 hari).
  - Retensi Cadangan Bergulir (`pruneBackups`): pemangkasan otomatis berkas cadangan SQLite lama untuk mempertahankan $N$ cadangan terbaru (default: 7 cadangan).
- **Mesin Peringatan Anomali & Notifikasi (`app/Services/AlertService.php`):**
  - Evaluasi Anomali Dataset Otomatis (`evaluateDatasetAnomalies`): mendeteksi anomali pasca-sinkronisasi atau terjadwal via `AnomalyDetector`, memicu notifikasi tingkat risiko (`CRITICAL` / `MODERATE`), dan mencatat audit log.
  - Proteksi Anti-Fatigue (`hasRecentAnomalyNotification`): menekan peringatan duplikat untuk dataset yang sama dalam jendela waktu 6 jam.
  - Deteksi Kegagalan Sinkronisasi Berulang (`checkRepeatedSyncFailures`): mendeteksi 3 kegagalan sinkronisasi berturut-turut pada koneksi dan mengirimkan peringatan darurat ke administrator.
- **Pengontrol Web & REST API Automation (`app/Controllers/AutomationController.php`):**
  - Rute Web:
    - `GET /automation/schedules`: Dashboard jadwal otomasi, panduan copy-paste crontab cPanel, status cron, dan tabel tugas terjadwal.
    - `POST /automation/schedules/create`: Pembuatan tugas terjadwal kustom.
    - `POST /automation/schedules/{id}/toggle`: Pengalihan status aktif/non-aktif tugas.
    - `POST /automation/schedules/{id}/run`: Eksekusi manual instan tugas terjadwal.
    - `POST /automation/schedules/{id}/delete`: Penghapusan tugas terjadwal.
    - `GET /automation/tasks`: Pemantau antrean background (*Sync Queue*) dengan bento KPI dan tabel riwayat pekerjaan.
    - `POST /automation/tasks/{id}/retry`: Percobaan ulang pekerjaan sinkronisasi yang gagal.
    - `GET /automation/health`: Diagnostik kesehatan sistem, kapasitas disk, status SQLite, dan tabel berkas cadangan.
    - `POST /automation/backup/now`: Pembuatan cadangan database manual instan.
    - `POST /automation/logs/rotate`: Pemicu rotasi dan pembersihan log manual.
    - `GET /notifications`: Pusat notifikasi dan peringatan sistem in-app pengguna.
    - `POST /notifications/{id}/read` & `POST /notifications/read-all`: Manajemen penandaan status baca notifikasi.
  - Endpoint Web Cron Aman:
    - `GET /cron/run?token={TOKEN}`: Endpoint cron berbasis web untuk hosting tanpa terminal/CLI cron, terproteksi token rahasia anti-akses tidak sah.
  - REST API Endpoints (`/api/v1/automation/`):
    - `GET /api/v1/automation/health`: Data metrik kesehatan sistem format JSON.
    - `GET /api/v1/automation/schedules`: Daftar tugas terjadwal dan status cron.
    - `POST /api/v1/automation/schedules`: Pembuatan jadwal baru via REST API.
    - `POST /api/v1/automation/schedules/{id}/run`: Pemicu eksekusi tugas via API.
    - `GET /api/v1/automation/jobs`: Daftar pekerjaan antrean sinkronisasi terpaginasi.
    - `GET /api/v1/automation/notifications`: Daftar notifikasi in-app pengguna via API.
- **Antarmuka Pengguna Otomasi & Pemantauan (`resources/views/automation/`):**
  - `resources/views/automation/schedules.php`: Visualisasi status cron, panduan crontab cPanel, dan tabel tugas.
  - `resources/views/automation/tasks.php`: Dasbor pemantau antrean dengan kartu bento metrik antrean dan badge status pekerjaan.
  - `resources/views/automation/health.php`: Dasbor pemantauan kesehatan sistem dengan banner diagnostik interaktif dan tabel cadangan database.
  - `resources/views/automation/notifications.php`: Antarmuka notifikasi in-app dengan pembedaan warna keparahan (*danger*, *warning*, *info*) dan tombol tandai telah dibaca.
- **Automated Test Suite Fase 11 (`tests/Unit/AutomationTest.php`):**
  - 10 skenario pengujian komprehensif pada isolated test database: cron expression parsing, isDue evaluation, next run timestamp calculation, ScheduledTask CRUD & toggle, concurrency file locking (`flock`), queue worker execution, atomic SQLite backup & integrity check, rolling backup retention pruning, log rotation & purging mechanics, system health diagnostics, dan anomaly alerting anti-fatigue notification lifecycle.
  - Seluruh 16/16 automated test suites AnaBot lulus 100%.

---

## [0.11.0-dev] - 2026-11-27 (FASE 10 — Report Engine)

### Ditambahkan (Added)
- **Mesin Pembuat Dokumen Multi-Format Mandiri (`app/Services/ReportEngine.php`):**
  - Kompilasi data analitis deterministik terpadu (`compileReportData`): mengintegrasikan statistik deskriptif, laju pertumbuhan, arah tren OLS, deteksi anomali, proyeksi masa depan 95% CI, dan ringkasan eksekutif AI terverifikasi tanpa halusinasi matematis.
  - Generator Dokumen HTML5 Mandiri (`generateHtml`): dokumen laporan eksekutif mandiri lengkap dengan CSS bertema cetak (`@media print`), kartu metrik bento, tabel terstruktur, dan grafik SVG tersemat (*embedded vector chart*).
  - Generator Spreadsheet CSV Streaming Ramah Memori (`generateCsv`): pembuatan berkas CSV terstruktur dengan UTF-8 BOM (`\xEF\xBB\xBF`) untuk kompatibilitas sempurna dengan Microsoft Excel, pemisah seksi komprehensif, dan penggunaan format parameter escape standar PHP 8.4.
  - Generator Buku Kerja OpenXML XLSX Murni (`generateXlsx`): pembuatan berkas spreadsheet multi-lembar kerja (`Ringkasan Eksekutif`, `Statistik Deskriptif`, `Deteksi Anomali`, `Proyeksi Peramalan`) menggunakan ekstensi bawaan PHP `ZipArchive` tanpa dependensi pihak ketiga atau Node/Python.
  - Generator Dokumen Standar PDF 1.4 Biner Asli (`generatePdf`): pembuatan berkas PDF biner resmi (struktur catalog, page tree, Helvetica font object, content streams, cross-reference table xref, dan trailer EOF) murni dalam bahasa PHP tanpa ketergantungan library eksternal (wkhtmltopdf/Dompdf/Node).
  - Orkestrator Pembangun Laporan Master (`buildAndSave`): mendukung pembuatan format tunggal maupun pembuatan serentak seluruh 4 format (`all`) sekaligus dalam satu transaksi penyimpanan.
  - Pembersih Sampah Laporan Otomatis (`cleanupOldReports`): sistem *garbage collection* otomatis untuk menghapus berkas laporan sementara atau kedaluwarsa berdasarkan ambang batas waktu.
- **Model Manajemen Laporan Terstruktur (`app/Models/Report.php`):**
  - Operasi CRUD lengkap pada tabel `reports`: `create`, `find`, `getByUser` (dengan paginasi dan filter format), `countByUser`, `delete`, `deleteWithFile` (pembersihan rekaman DB dan berkas fisik aman), `getStorageUsage` (perhitungan total kuota penyimpanan dan rincian per format), dan `getRecent`.
  - Dukungan resolusi path penyimpanan absolut dan relatif yang dijangkarkan ke direktori proyek utama.
- **Pengontrol Web & REST API Report Center (`app/Controllers/ReportController.php`):**
  - Rute Tampilan Web:
    - `GET /reports`: Dashboard Pusat Laporan dengan kartu bento metrik penyimpanan, tab filter format dokumen, tabel riwayat pembuatan laporan, tombol pratinjau cepat, dan unduhan langsung.
    - `GET /reports/create`: Wizard pembuatan laporan interaktif (pemilihan dataset, penentuan metrik kalkulasi, opsi format dokumen, rentang tanggal, dan saklar interpretasi AI).
    - `POST /reports/generate`: Pemrosesan pembuatan laporan berbasis form web dengan token anti-CSRF dan pengalihan dinamis ke halaman pratinjau.
    - `GET /reports/{id}/preview`: Penampil laporan layar penuh dengan iframe terisolasi untuk HTML dan kartu ringkasan dokumen untuk berkas biner (PDF/XLSX/CSV).
    - `GET /reports/{id}/download`: Mekanisme pengunduhan privat aman dengan header HTTP strictly-controlled (`Content-Disposition: attachment`, `Cache-Control: private, no-store`).
    - `POST /reports/{id}/delete`: Penghapusan laporan dan berkas fisiknya secara aman.
  - REST API Endpoints:
    - `GET /api/v1/reports`: Daftar laporan terpaginasi pengguna beserta statistik agregasi penyimpanan.
    - `POST /api/v1/reports`: Pembangkitan laporan on-demand melalui antarmuka REST API.
    - `GET /api/v1/reports/{id}`: Detail metadata laporan individual.
    - `DELETE /api/v1/reports/{id}`: Penghapusan laporan via API.
    - `GET /api/v1/reports/{id}/download`: Pengunduhan streaming berkas via API.
  - Sistem Keamanan & Proteksi Akses:
    - Penegakan isolasi single-user ownership (pengguna hanya dapat mengakses laporan miliknya).
    - Proteksi traversal path fisik ketat (`validateFilePath`) memastikan seluruh akses berkas terkunci di dalam direktori penyimpanan laporan (`storage/reports`).
- **Antarmuka Pengguna Pusat Laporan Studio (`resources/views/reports/`):**
  - `resources/views/reports/index.php`: Tata letak modern SaaS dengan indikator visual kuota kapasitas penyimpanan, kartu ringkasan format, dan tabel status laporan.
  - `resources/views/reports/create.php`: Form perancang laporan satu-klik dengan validasi interaktif.
  - `resources/views/reports/preview.php`: Antarmuka penampil dokumen dengan tombol cetak dokumen langsung (*browser native print API*).
- **Automated Test Suite Fase 10 (`tests/Unit/ReportEngineTest.php`):**
  - 10 skenario pengujian komprehensif pada database pengujian terisolasi: Report CRUD & kuota storage, kompilasi data deterministik, generasi HTML dengan grafik SVG, streaming CSV dengan UTF-8 BOM, OpenXML multi-sheet XLSX via ZipArchive, binary PDF 1.4 murni PHP, multi-format master generation, path traversal security blocking, single-user ownership privacy enforcement, dan automated garbage collection.
  - Seluruh 15/15 automated test suites AnaBot lulus 100%.

---

## [0.10.0-dev] - 2026-11-27 (FASE 9 — Predictive Analytics & Anomaly Detection)

### Ditambahkan (Added)
- **Mesin Deteksi Anomali Matematis Murni (`app/Services/AnomalyDetector.php`):**
  - Deteksi outlier berbasis Interquartile Range (IQR) John Tukey: pemisahan pagar dalam ($1.5 \times IQR$, *mild anomaly*) dan pagar luar ($3.0 \times IQR$, *extreme anomaly*).
  - Deteksi deviasi terstandarisasi Z-Score ($z = \frac{x - \mu}{\sigma}$) dengan Bessel's sample correction $n-1$ serta proteksi varians nol ($\sigma = 0$).
  - Deteksi pergeseran tajam sekuensial (*sudden shifts*) pada deret waktu: identifikasi lonjakan mendadak (*sudden spike*) dan penurunan tajam (*steep drop*).
  - Analisis anomali dataset komprehensif (`detectDatasetAnomalies`) yang mengintegrasikan metode IQR, Z-Score, dan sudden shifts dengan penentuan tingkat risiko dataset (`LOW`, `MODERATE`, `CRITICAL`).
  - Persistensi deterministik ke tabel `analysis_results` dengan tipe `anomaly_detection`.
- **Mesin Proyeksi & Peramalan Deterministik (`app/Services/ForecastEngine.php`):**
  - Proyeksi tren linear Ordinary Least Squares (OLS) dengan evaluasi $R^2$ dan gradien tren.
  - Interval Kepercayaan 95% (*95% Confidence Intervals*) berbasis galat baku prediksi ($SE_{pred}$) dengan dekomposisi varians residual dan jarak dari rata-rata historis.
  - Holt's Linear Exponential Smoothing (Double Exponential Smoothing) dengan pembaruan dinamis level $\alpha$ dan tren $\beta$ untuk menangani deret waktu bervariasi.
  - Proyeksi Simple Moving Average (SMA) sebagai alternatif proyeksi jangka pendek adaptif.
  - Evaluasi akurasi backtesting peramalan: Mean Absolute Percentage Error (MAPE %) dan Root Mean Square Error (RMSE).
  - Penegakan klausul disclaimer mutlak: mewajibkan pernyataan tertulis bahwa proyeksi dihasilkan secara matematis murni dengan asumsi kondisi historis berlanjut dan bukan jaminan masa depan.
  - Persistensi deterministik ke tabel `analysis_results` dengan tipe `forecast_projection`.
- **Pengontrol Web & REST API Predictive Analytics (`app/Controllers/PredictiveController.php`):**
  - Web views: `predictivePage` (`/analytics/predictive`, `/analytics/forecast`, `/analytics/anomalies`, `/predictions`).
  - REST API Endpoints:
    - `GET /api/v1/analytics/datasets/{id}/anomalies`: Eksekusi deteksi anomali matematis lengkap, batas IQR, deviasi Z-score, dan ringkasan risiko.
    - `GET /api/v1/analytics/datasets/{id}/forecast`: Eksekusi proyeksi multi-metode (OLS, Holt, SMA) dengan interval kepercayaan 95% dan metrik akurasi.
    - `POST /api/v1/analytics/datasets/{id}/interpret-predictive`: Interpretasi AI berbasis fakta matematis aktual (analisis anomali & proyeksi masa depan).
- **Antarmuka Interaktif Visual Forecaster & Anomaly Studio (`resources/views/analytics/predictive.php`):**
  - Visualisasi grafik Chart.js multi-lapisan: garis aktual historis, garis proyeksi terputus-putus (*dashed line*), area arsiran interval kepercayaan 95% (*shaded confidence band*), dan titik anomali berkedip merah/kuning (*glowing warning dots*).
  - Bento KPI Cards instan: Skor Tingkat Risiko (*Risk Level*), Nilai Proyeksi Target, Rentang Batas 95% Confidence, dan Akurasi Model (MAPE/RMSE).
  - Tabel rincian anomali interaktif dengan badge klasifikasi keparahan (*Mild Outlier*, *Extreme Outlier*, *Sudden Spike*, *Steep Drop*).
  - Panel AI Predictive Advisory: Interpretasi on-demand yang terikat kuat pada data komputasi murni tanpa halusinasi.
- **Automated Test Suite Fase 9 (`tests/Unit/PredictiveAnalyticsTest.php`):**
  - 10 skenario pengujian matematis komprehensif (IQR fences, Z-score zero-variance protection, sudden shifts, unified risk leveling, OLS projection exactness, 95% CI math bounds, Holt's smoothing formulas, SMA forecast, backtesting MAPE/RMSE, deterministik DB persistence).
  - Seluruh 14/14 automated test suites AnaBot lulus 100%.

---

## [0.9.0-dev] - 2026-11-27 (FASE 8 — AI Analyst & Grounded Conversational Intelligence)

### Ditambahkan (Added)
- **Mesin Kompilasi Fakta Data Terverifikasi (`app/Services/AIContextBuilder.php`):**
  - Mengompilasi fakta data matematis dari SQLite dan `AnalyticsEngine` secara deterministik untuk satu atau lebih dataset tertaut.
  - Ekstraksi otomatis statistik deskriptif (Mean, Median, Min, Max, StdDev, IQR), laju pertumbuhan (DoD, WoW, MoM, YoY, CAGR), arah tren OLS ($R^2$ dan normalized slope), korelasi Pearson $r$ antar-metrik, dan rincian dimensi Pareto 80/20.
  - Deteksi titik puncak (*peak*), titik terendah (*valley*), lonjakan pertumbuhan terbesar, dan penurunan tercuram.
  - Format output markdown terstruktur dan ringkasan kartu KPI untuk disuntikkan ke prompt LLM dan widget dashboard.
- **Model Manajemen Sesi & Pesan Analisis (`app/Models/AnalysisSession.php` & `app/Models/AnalysisMessage.php`):**
  - `AnalysisSession`: CRUD sesi analisis, penautan dataset sumber multi-platform via `analysis_sources`, pembaruan timestamp, dan pembersihan sesi dengan integritas cascade deletion.
  - `AnalysisMessage`: Penyimpanan percakapan berurutan (user & assistant), pelacakan penggunaan token input/output per giliran bicara, dan ekstraksi memori konteks percakapan multi-turn (`getRecentConversationHistory`).
- **Orkestrator Percakapan AI Analyst (`app/Services/AIOrchestrator.php`):**
  - Menghubungkan sesi percakapan, context builder, prompt manager, dan multi-provider inference `AIManager`.
  - Penegakan Anti-Hallucination Guardrail: AI dilarang keras mengarang angka yang tidak ada dalam konteks komputasi deterministik.
  - Eksekusi alur percakapan dinamis (`ask`) dan penanganan aksi analisis cepat satu-klik (`executeQuickAction`).
  - Fitur pratinjau bukti fakta data terverifikasi (`getSessionFactsPreview`) untuk transparansi 100%.
- **Pengembangan Prompt Analitik Khusus (`app/AI/PromptManager.php`):**
  - `buildMultiTurnPrompt`: Menyusun pesan riwayat percakapan dengan penyuntikan fakta data terkini pada system instruction.
  - `buildQuickActionPrompt`: Prompt khusus untuk 4 mode analisis eksekutif:
    1. *Ringkasan Eksekutif Terpadu* (`executive_summary`)
    2. *Diagnosis Akar Masalah & Pola Kinerja* (`root_cause_diagnosis`)
    3. *Rekomendasi Aksi Strategis & Taktis* (`strategic_recommendations`)
    4. *Investigasi Anomali & Fluktuasi Ekstrem* (`anomaly_detection`)
- **Pengontrol Web & REST API AI Analyst (`app/Controllers/AIAnalystController.php`):**
  - Web views: `chatPage` (`/ai`, `/ai/chat`, `/ai/chat/{id}`, `/ai/history`) dan `newSession` (`POST /ai/new`).
  - REST API Endpoints:
    - `GET /api/v1/ai/sessions`: Daftar seluruh sesi analisis pengguna.
    - `POST /api/v1/ai/sessions`: Membuat sesi analisis baru dengan penautan dataset.
    - `GET /api/v1/ai/sessions/{id}`: Rincian sesi dan riwayat pesan obrolan.
    - `PUT /api/v1/ai/sessions/{id}`: Memperbarui judul sesi atau dataset tertaut.
    - `DELETE /api/v1/ai/sessions/{id}`: Menghapus sesi beserta seluruh pesannya.
    - `POST /api/v1/ai/sessions/{id}/messages`: Mengirim pertanyaan analisis dan memperoleh interpretasi AI grounded.
    - `POST /api/v1/ai/sessions/{id}/quick-action`: Menjalankan analisis cepat 1-klik.
    - `GET /api/v1/ai/sessions/{id}/context`: Pratinjau fakta data matematis murni yang disuntikkan ke AI.
    - `POST /api/v1/ai/sessions/{id}/clear`: Membersihkan riwayat pesan dalam sesi.
- **Antarmuka Interaktif AI Analyst Workspace (`resources/views/ai/chat.php`):**
  - Desain modern SaaS responsive dengan tema gelap/terang, panel riwayat sesi di sidebar kiri, dan pemilih dataset multi-select instan.
  - Bilah kartu ringkasan bento KPI instan dari data terverifikasi.
  - Alur obrolan interaktif dengan markdown renderer, status pengetikan real-time, badge konsumsi token, dan starter prompt suggestions.
  - Modal inspeksi bukti data terverifikasi (*Grounded Data Transparency Inspector*) untuk melihat angka komputasi matematis yang digunakan AI.
- **Automated Test Suite Phase 8 (`tests/Unit/AIAnalystTest.php`):**
  - 10 skenario pengujian komprehensif pada database SQLite terisolasi: CRUD sesi, penautan dataset, persistensi pesan, riwayat percakapan multi-turn, kompilasi fakta `AIContextBuilder`, prompt guardrail anti-halusinasi, aksi analisis cepat, eksekusi query grounded `AIOrchestrator::ask`, dan integritas cascade deletion.
  - **13/13 automated test suites lulus 100%**.

---

## [0.8.0-dev] - 2026-11-27 (FASE 7 — Multi-Provider AI Framework)

### Ditambahkan (Added)
- **Kontrak Standar Multi-Provider AI (`app/AI/Contracts/AIProviderInterface.php` & `app/AI/Providers/BaseProvider.php`):**
  - Antarmuka generik yang mendefinisikan metode `generateText()`, `generateChat()`, `estimateCost()`, `isConfigured()`, dan `getSupportedModels()`.
  - Kelas abstrak `BaseProvider` dengan estimasi token heuristik, pemfilteran parameter suhu/token, dan masking kunci sensitif.
- **Implementasi Driver Multi-Provider Resmi PHP Native 8.4 cURL:**
  - `GeminiProvider.php`: Driver Google Gemini API v1beta (`gemini-1.5-pro`, `gemini-1.5-flash`, `gemini-2.0-flash`) dengan pemisahan `systemInstruction` dan penanganan autentikasi query parameter `?key=`.
  - `OpenAIProvider.php`: Driver OpenAI Chat Completions API (`gpt-4o`, `gpt-4o-mini`, `gpt-4-turbo`) dengan bearer token authentication dan parsing response terstandardisasi.
  - `ClaudeProvider.php`: Driver Anthropic Messages API (`claude-3-5-sonnet-20241022`, `claude-3-5-haiku-20241022`, `claude-3-opus-20240229`) dengan parameter `system` tingkat atas, header `x-api-key`, dan `anthropic-version: 2023-06-01`.
  - `CompatibleProvider.php`: Driver fleksibel protokol OpenAI-compatible untuk penyedia open-source dan alternatif (Groq, DeepSeek, Mistral, Ollama, OpenRouter).
- **Model Manajemen Konfigurasi AI & Enkripsi Kunci (`app/Models/AIProvider.php` & `app/Models/AIModel.php`):**
  - Penyimpanan API Key terenkripsi aman secara otomatis menggunakan **AES-256-GCM** dengan rotasi IV per catatan di tabel `ai_providers`.
  - Registrasi model dinamis, pelacakan kapabilitas konteks token (`max_context_tokens`), serta pemisahan biaya input/output per sejuta token (`cost_per_1k_input` dan `cost_per_1k_output`).
- **Orkestrator Cerdas Failover & Anggaran Token (`app/AI/AIManager.php`):**
  - Mekanisme failover otomatis berantai: jika provider utama mengalami HTTP 429 (Rate Limit) atau kendala jaringan, secara cerdas beralih ke provider cadangan (fallback).
  - Penegakan pagu anggaran token bulanan (`monthly_token_budget`): Memblokir konsumsi berlebih sebelum pemanggilan API luar.
  - Pencatatan jejak audit komprehensif ke tabel SQLite `ai_usage_logs` mencakup provider ID, model, prompt tokens, completion tokens, latency (ms), estimasi biaya ($), dan status transaksi.
- **Pengelola Prompt Bergaransi Anti-Halusinasi (`app/AI/PromptManager.php`):**
  - Penegakan kepatuhan aturan Aturan 1.14 & 2.3: AI dilarang keras mengarang atau menginterpolasi angka statistik.
  - Injeksi otomatis fakta komputasi deterministik dari `AnalyticsEngine` (Mean, CAGR, Tren OLS, Matriks Korelasi Pearson, Pareto 80/20) langsung ke System Prompt.
  - Templating struktur respons analitis terstandar (Ringkasan Eksekutif, Bukti Data Numerik, Diagnosis & Pola, Rekomendasi Aksi Konkret).
- **Pengontrol Web & REST API AI (`app/Controllers/AIController.php`):**
  - Halaman antarmuka manajemen: `settingsPage` (`/settings/ai`, `/ai/providers`).
  - REST API endpoint:
    - `GET /api/v1/ai/providers`: Mengambil daftar provider, model, status konfigurasi, dan saldo token.
    - `POST /api/v1/ai/providers/{id}`: Memperbarui API key terenkripsi, status aktif/nonaktif, provider default, dan batas anggaran token.
    - `POST /api/v1/ai/test/{code}`: Pengujian koneksi API langsung (ping test) ke provider terkait.
    - `POST /api/v1/ai/generate`: Endpoint pengujian inferensi prompt langsung via AIManager.
    - `GET /api/v1/ai/usage`: Riwayat log audit pemakaian token dan ringkasan metrik biaya AI.
- **Antarmuka Pengaturan AI Terpadu (`resources/views/ai/settings.php`):**
  - Panel konfigurasi kartu bento untuk Gemini, OpenAI, Claude, dan OpenAI-Compatible dengan indikator status aktif/nonaktif dan badge default.
  - Modal penyimpanan API Key terenkripsi dengan proteksi masking password dan pengujian koneksi instan.
  - Monitor anggaran token bulanan interaktif dengan visual progress bar persentase penggunaan kuota.
  - Tabel riwayat audit transaksi log token real-time dengan status status badge, token count, dan biaya desimal mikro.
- **Automated Test Suite Phase 7 (`tests/Unit/AIFrameworkTest.php`):**
  - 10 skenario pengujian komprehensif pada database SQLite terisolasi: kontrak interface provider, formatting payload Gemini/OpenAI/Claude/Compatible, enkripsi & dekripsi kunci AES-256-GCM, failover provider otomatis saat simulasi HTTP 429, penegakan ambang batas anggaran token, audit logging `ai_usage_logs`, dan guardrail anti-halusinasi `PromptManager`.
  - **12/12 automated test suites lulus 100%**.

---

## [0.7.0-dev] - 2026-11-27 (FASE 6 — Analytics Engine)

### Ditambahkan (Added)
- **Mesin Statistik & Analitik Deterministik PHP Native 8.4 (`app/Services/AnalyticsEngine.php`):**
  - Perhitungan statistik deskriptif murni: Mean ($\bar{x}$), Median, Modus, Min, Max, Range, Sample Variance ($s^2$ dengan koreksi Bessel $n-1$), Standard Deviation ($s$), Kuartil ($Q1$, $Q3$), dan Interquartile Range ($IQR$).
  - Agregasi rangkaian waktu multi-interval langsung via SQLite C-level speed (`strftime`) untuk interval Harian (`day`), Mingguan (`week`), Bulanan (`month`), dan Tahunan (`year`) tanpa pemborosan memori PHP.
  - Perhitungan laju pertumbuhan periode ke periode (DoD, WoW, MoM, YoY) dengan penanganan aman terhadap basis nol ($0 \rightarrow X$) dan compound growth rate (CAGR / CMGR).
  - Indikator perataan Simple Moving Average (SMA) dan Exponential Moving Average (EMA) dengan faktor pembobotan $\alpha = \frac{2}{window + 1}$.
  - Deteksi arah tren otomatis berbasis Ordinary Least Squares (OLS) linear regression: Klasifikasi *Uptrend*, *Downtrend*, atau *Sideways* dengan slope ternormalisasi dan koefisien determinasi $R^2$.
  - Perhitungan koefisien korelasi Pearson ($r \in [-1.0, 1.0]$) dengan penanganan varians nol, klasifikasi kekuatan korelasi 5 tingkat (*Sangat Kuat Positif*, *Moderat Positif*, *Lemah/Tidak Berkorelasi*, *Moderat Negatif*, *Sangat Kuat Negatif*), dan perangkingan pasangan variabel terkuat.
  - Analisis kontribusi dimensi dan prinsip Pareto 80/20 (`breakdownByDimension`): Perhitungan persentase kontribusi per kategori dan identifikasi kategori penentu 80% volume metrik.
  - Orkestrator analisis otomatis menyeluruh (`runFullAnalysis`) dan penyimpanan hasil perhitungan ke tabel SQLite `analysis_results`.
- **Model Persistensi Hasil Analisis (`app/Models/AnalysisResult.php`):**
  - Pengelolaan pencatatan hasil komputasi deterministik pada tabel `analysis_results` dengan garansi single-canonical record per dataset/metrik/tipe.
- **Pengontrol Analitik & REST API (`app/Controllers/AnalyticsController.php`):**
  - Web views: `trendsPage` (`/analytics`, `/analytics/trends`) dan `comparisonPage` (`/analytics/comparison`).
  - REST API:
    - `GET /api/v1/analytics/datasets/{id}/summary`: Statistik deskriptif seluruh kolom metrik.
    - `GET /api/v1/analytics/datasets/{id}/timeseries`: Data rangkaian waktu dengan moving averages dan laju pertumbuhan.
    - `GET /api/v1/analytics/datasets/{id}/correlation`: Matriks korelasi Pearson lengkap.
    - `GET /api/v1/analytics/datasets/{id}/breakdown`: Rincian kontribusi dimensi dan Pareto.
    - `POST /api/v1/analytics/datasets/{id}/recalculate`: Pemicu hitung ulang seluruh analitik dataset secara instan.
- **Antarmuka Visualisasi Analitik Interaktif (`resources/views/analytics/`):**
  - `trends.php`: Dasbor Tren & Pertumbuhan dengan pemilih dataset/metrik/interval, bento cards metrik deskriptif, grafik interaktif Chart.js (Aktual, SMA, EMA), dan tabel rincian pertumbuhan periode.
  - `comparison.php`: Dasbor Komparasi & Korelasi dengan tabel matriks heatmap Pearson, peringkat hubungan variabel terkuat, diagram batang horizontal Chart.js, dan tabel kontribusi Pareto 80/20.
- **Automated Test Suite Phase 6 (`tests/Unit/AnalyticsEngineTest.php`):**
  - 9 skenario pengujian matematis ketat pada database SQLite terisolasi: statistik deskriptif pada distribusi angka terverifikasi, perhitungan pertumbuhan DoD/MoM/CAGR, moving averages SMA/EMA, regresi linear tren, korelasi Pearson ($r=1.0, r=-1.0, r\approx 0$), agregasi SQLite, matriks korelasi, analisis Pareto, dan persistensi `analysis_results`.
  - **11/11 test suites automated runner lulus 100%**.

---

## [0.6.0-dev] - 2026-11-27 (FASE 5 — Integrasi Pertama: Google Sheets)

### Ditambahkan (Added)
- **Engine Normalisasi & Inferensi Skema Data (`app/Services/DataNormalizer.php`):**
  - Sanitasi nama kolom header otomatis ke snake_case (`sanitizeColumnName`) yang aman untuk SQLite dan JSON path extraction.
  - Inferensi tipe data cerdas (`inferColumnType`): `DATETIME`, `INTEGER`, `NUMERIC`, `BOOLEAN`, dan `TEXT` dengan analisis sampel multi-baris.
  - Pembersihan dan normalisasi mata uang & persentase (`cleanCurrency`): Mengubah format `Rp 1.500.000`, `$1,250.50`, atau `15%` menjadi nilai numerik murni.
  - Normalisasi tanggal ke format ISO-8601 (`normalizeDate`): Mendukung format `YYYY-MM-DD`, `DD/MM/YYYY`, `DD-MM-YYYY`, dan timestamp.
  - Klasifikasi peran kolom cerdas (`classifyColumnRole`): Menentukan `is_primary_date` (kolom tanggal utama), `is_metric` (nilai terukur/kuantitatif), dan `is_dimension` (atribut kategorikal/kualitatif).
- **Pembantu Autentikasi Google (`app/Connectors/GoogleSheets/GoogleAuthHelper.php`):**
  - Ekstraksi Spreadsheet ID cerdas (`extractSpreadsheetId`) dari berbagai format URL (URL edit browser, URL publikasi web, atau ID 44-karakter langsung).
  - Pembuatan Assertion JWT Google Service Account mandiri dengan algoritma **RS256** menggunakan ekstensi native PHP OpenSSL (`openssl_sign` dengan `OPENSSL_ALGO_SHA256`) — zero dependency terhadap library eksternal.
  - Pertukaran token akses Google OAuth 2.0 (`exchangeJwtForAccessToken`) langsung ke endpoint resmi `https://oauth2.googleapis.com/token`.
- **Pengembangan Driver Google Sheets API v4 (`app/Connectors/GoogleSheets/GoogleSheetsConnector.php`):**
  - Penegakan mutlak izin **READ-ONLY** dengan scope tunggal `https://www.googleapis.com/auth/spreadsheets.readonly`.
  - Fitur `getAvailableDatasets()` untuk mengambil seluruh daftar tab sheet dalam file spreadsheet via endpoint `sheets.get`.
  - Fitur `previewSheet()` untuk pratinjau instan 5 baris pertama spreadsheet dengan deteksi baris header dan inferensi skema kolom langsung.
  - Fitur `fetchData()` dengan paginasi range berbasis row-offset (`Sheet1!A{start}:ZZ{end}`) dan filter otomatis baris kosong (`isRowEmpty`).
- **Orkestrator Impor Atomik (`app/Services/GoogleSheetsImporter.php`):**
  - Pipeline impor atomik menyeluruh dari Google Sheets langsung ke tabel SQLite internal AnaBot (`data_sources`, `datasets`, `dataset_columns`, `data_records`, dan `sync_jobs`).
  - Pencegahan duplikasi data mutlak (idempotent upsert) menggunakan komposit `external_id = "{sheetName}_{rowNumber}"` dan SHA-256 hash.
  - Perhitungan statistik ringkasan dataset seketika (`record_count`, rentang tanggal data `first_record_at` dan `latest_record_at`).
  - Integrasi pencatatan tugas sinkronisasi otomatis ke `sync_jobs` dan pembaruan cursor ke `sync_cursors`.
- **Studio Impor Google Sheets Interaktif (`resources/views/connectors/google_sheets/import.php`):**
  - Antarmuka web ramah pengguna untuk pemilihan tab sheet secara dinamis.
  - Pratinjau interaktif tabel data aktual sebelum konfirmasi impor.
  - Penanda badge peran kolom (Primary Date, Metric, Dimension) dengan pemilihan tipe data yang transparan.
  - Indikator status progres sinkronisasi real-time.
- **Rute Web & API Endpoint Google Sheets (`routes/web.php` & `routes/api.php`):**
  - Rute web: `GET /connectors/{id}/google-sheets` dan alias `GET /connectors/{id}/sheets`.
  - API endpoint: `GET /api/v1/connectors/google-sheets/{id}/preview` dan `POST /api/v1/connectors/google-sheets/{id}/import`.
- **Automated Test Suite Google Sheets (`tests/Unit/GoogleSheetsIntegrationTest.php`):**
  - 8 skenario pengujian komprehensif: ekstraksi URL/ID spreadsheet, penandatanganan OpenSSL RS256 JWT, inferensi tipe data, pembersihan mata uang/tanggal, klasifikasi peran kolom, pratinjau live dengan filter baris kosong, impor atomik end-to-end ke SQLite, dan penjaminan idempotensi anti-duplikasi baris.
  - **10/10 test suites automated runner lulus 100%**.

---

## [0.5.0-dev] - 2026-11-27 (FASE 4 — Connector Framework)

### Ditambahkan (Added)
- **Standard Connector Interface & Base Connector (`app/Connectors/`):**
  - `ConnectorInterface.php`: Kontrak standar seluruh konektor platform eksternal dengan penegakan mutlak **READ-ONLY** (Aturan 1.16 & 1.17). Tidak ada konektor yang diizinkan mengubah/menghapus data pada platform eksternal.
  - `BaseConnector.php`: Kelas abstrak fondasi konektor yang menyediakan request throttling, penanganan backoff rate limit (HTTP 429), sensor data sensitif otomatis, dan integrasi logging ke `sync_logs`.
- **Resilient & Secure HTTP Client (`app/Services/HttpClient.php`):**
  - Mesin HTTP client berbasis PHP Native 8.4 cURL murni (zero external dependency, kompatibel dengan Shared Hosting).
  - Batas timeout ketat: 10 detik connect timeout, 30 detik total execution timeout.
  - Proteksi memori hosting: Guard ukuran response maksimum 10 MB untuk mencegah memory exhaustion.
  - **SSRF Protection:** Blokir mutlak terhadap seluruh range IP privat RFC1918 (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), loopback (`127.0.0.0/8`), link-local / cloud metadata (`169.254.169.254`), dan IPv6 internal (`::1`, ULA).
  - Exponential backoff otomatis dengan jitter acak pada respons rate limit HTTP 429 dan HTTP 503.
  - Verifikasi SSL ketat wajib (`CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2`).
  - Redaksi otomatis kredensial sensitif pada header dan URL log audit.
- **Credential Manager Terenkripsi (`app/Services/CredentialManager.php`):**
  - Penyimpanan kredensial API dan OAuth token terenkripsi dua arah dengan algoritma **AES-256-GCM** pada tabel `connection_credentials`.
  - Deteksi token kadaluarsa (`isExpired()`) dan peringatan pembaruan otomatis (`needsRefresh()`).
  - Mekanisme rotasi token (`updateTokens()`).
  - Pencabutan dan pembersihan kredensial aman saat sambungan diputus (`revoke()`).
- **OAuth 2.0 Anti-CSRF State Manager (`app/Services/OAuthStateManager.php`):**
  - Pembuatan token state anti-CSRF acak kriptografis 64-karakter hex dengan batas waktu kadaluarsa (15 menit).
  - Garansi konsumsi sekali pakai (single-use): Token langsung dihapus saat verifikasi pertama berhasil, menggagalkan segala bentuk replay attack.
- **Sync Queue & Incremental Cursor Engine (`app/Services/SyncQueueManager.php`):**
  - Pengelolaan siklus hidup tugas sinkronisasi (`pending` -> `running` -> `completed` / `failed` / `partial`).
  - Penyimpanan dan pemulihan cursor pagination berbasis resource pada tabel `sync_cursors`.
  - Audit log per-langkah detail pada tabel `sync_logs` dengan penyensoran token otomatis (`***REDACTED***`).
  - Pemulihan parsial (Partial sync recovery) saat koneksi terputus di tengah jalan.
- **Orkestrator Konektor (`app/Services/ConnectorManager.php`):**
  - Registri driver konektor dengan resolusi dinamis.
  - Penyelaras katalog otomatis (`syncCatalogToDatabase`) untuk 10 platform digital resmi.
  - Pengujian konektivitas live (`testConnection`) dan eksekusi batch sinkronisasi inkremental ber-cursor.
  - Pemutusan sambungan aman (`disconnect`).
- **Implementasi Driver Konektor Konkret:**
  - `CustomAPIConnector.php`: Driver REST API universal dengan autentikasi API Key (Bearer / Header) dan paginasi cursor.
  - `GoogleSheetsConnector.php`: Driver Google Sheets API v4 dengan scope `spreadsheets.readonly` dan row-offset cursor.
- **Model Data (`app/Models/`):**
  - `PlatformConnection.php`, `Connector.php`, `SyncJob.php`.
- **Antarmuka Pengguna Modul Konektor (`resources/views/connectors/`):**
  - `index.php`: Manajemen koneksi aktif, indikator status live, tombol test koneksi, pemicu sinkronisasi instan, dan katalog 10 platform didukung.
  - `create.php`: Form penambahan koneksi baru dengan pengaturan interval sync, input kredensial terenkripsi, dan banner keamanan READ-ONLY.
  - `detail.php`: Dasbor inspeksi mendalam status enkripsi AES-256-GCM, nilai cursor inkremental, tabel riwayat sync, dan live log inspector stream.
  - `history.php`: Riwayat global audit sinkronisasi lintas seluruh platform terhubung.
- **Rute Web & REST API (`routes/web.php` & `routes/api.php`):**
  - Rute web: `/connectors`, `/connectors/create`, `/connectors/history`, `/connectors/{id}`, `/connectors/{id}/settings`, `/connectors/{id}/disconnect`, `/connectors/oauth/authorize/{code}`, `/connectors/oauth/callback`.
  - REST API: `GET /api/v1/connectors`, `GET /api/v1/connections`, `POST /api/v1/connections`, `GET /api/v1/connections/{id}`, `POST /api/v1/connections/{id}/test`, `POST /api/v1/connections/{id}/sync`, `POST /api/v1/connections/{id}/disconnect`, `GET /api/v1/connections/{id}/history`, `GET /api/v1/connections/logs/{job_id}`.
- **Automated Test Suite (`tests/Unit/ConnectorFrameworkTest.php`):**
  - 10 unit & integration test cases mencakup enkripsi credential, deteksi token kadaluarsa, proteksi anti-CSRF state token, pemblokiran SSRF, sensor log sensitif, lifecycle sync queue, resume cursor inkremental, resolusi driver katalog, safe disconnect, dan penegakan READ-ONLY (9/9 test suites lulus 100%).

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
