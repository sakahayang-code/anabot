# Panduan Penerapan Produksi AnaBot (Deployment Guide)
**Versi Platform:** 1.0.0  
**Lingkungan Target:** Shared Hosting (cPanel / DirectAdmin / CyberPanel / LiteSpeed / Apache / Nginx)  
**Persyaratan Sistem:** PHP Native >= 8.4 (Zero Docker, Zero Redis, Zero Node.js)

---

## 1. Persyaratan Server Minimum

| Komponen | Spesifikasi Minimal | Keterangan |
| :--- | :--- | :--- |
| **PHP Runtime** | PHP 8.4.0 atau lebih baru | Native CLI & Web SAPI |
| **Ekstensi PHP Wajib** | `pdo_sqlite`, `openssl`, `curl`, `json`, `mbstring`, `zip`, `zlib` | Standard shared hosting bundle |
| **Basis Data** | SQLite 3 (v3.35.0+) | Terintegrasi langsung dalam PHP |
| **Alokasi Memori** | Minimum 128 MB (`memory_limit = 128M`) | Cukup untuk streaming report & sync |
| **Penyimpanan Disk** | 200 MB - 1 GB | Tergantung volume dataset dan cadangan |

---

## 2. Pemeriksaan Lingkungan Otomatis (Diagnostic Tool)

Sebelum melakukan instalasi penuh, Anda dapat menjalankan alat diagnostik otomatis yang telah disediakan:

### Melalui Terminal / SSH:
```bash
php bin/hosting_check.php
```

### Melalui Web Browser:
Buka browser ke `https://domainanda.com/bin/hosting_check.php` (hapus atau batasi akses berkas ini setelah verifikasi berhasil).

---

## 3. Langkah Instalasi pada cPanel / Shared Hosting

### Langkah 3.1: Ekstraksi Berkas Proyek
1. Unggah arsip ZIP proyek ke direktori akun hosting Anda, misalnya:  
   `/home/username/anabot` (disarankan berada satu level di atas `public_html`).
2. Ekstrak seluruh berkas ke folder tersebut.

### Langkah 3.2: Penataan DocumentRoot Web (Struktur Ideal)
Untuk keamanan maksimal, arahkan DocumentRoot domain utama atau subdomain ke folder `public/`:
- **Path Proyek:** `/home/username/anabot/`
- **DocumentRoot Domain:** `/home/username/anabot/public/`

> [!NOTE]
> **Jika hosting Anda tidak mengizinkan mengubah DocumentRoot:**  
> Jika DocumentRoot terkunci pada `/home/username/public_html`, letakkan seluruh berkas proyek di `/home/username/public_html/`. Berkas `.htaccess` pada root AnaBot secara otomatis akan meneruskan lalu lintas web ke folder `public/` dan memblokir akses langsung ke folder privat (`app/`, `storage/`, `config/`, `.env`, dll).

### Langkah 3.3: Konfigurasi Izin Direktori (Chmod)
Pastikan folder `storage/` dan sub-direktorinya dapat ditulis oleh proses web server:
```bash
chmod 755 storage/
chmod 755 storage/database/
chmod 755 storage/logs/
chmod 755 storage/cache/
chmod 755 storage/reports/
chmod 755 storage/backups/
```

### Langkah 3.4: Konfigurasi Berkas `.env`
Salin template `.env.example` menjadi `.env`:
```bash
cp .env.example .env
```
Sesuaikan parameter penting berikut:
```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://analytics.domainanda.com

# Hasilkan 64 karakter heksadesimal acak untuk kunci enkripsi kredensial
APP_KEY=masukkan_64_karakter_acak_disini

# Token rahasia pemanggilan web cron
CRON_SECRET_TOKEN=buat_token_acak_aman_disini
```

### Langkah 3.5: Menjalankan Migrasi Basis Data Awal
Jalankan migrasi skema SQLite melalui CLI:
```bash
php bin/migrate.php
```
Basis data SQLite akan otomatis dibuat di `storage/database/anabot_development.sqlite` (atau `anabot_production.sqlite` sesuai `APP_ENV`) dengan mode **WAL (Write-Ahead Logging)** dan `busy_timeout=5000` aktif.

---

## 4. Konfigurasi Otomasi & Penjadwalan (Cron Job)

AnaBot menggunakan master cron runner tunggal yang sangat ringan dan ramah shared hosting.

### Opsi A: Cron CLI cPanel (Direkomendasikan)
Masuk ke menu **Cron Jobs** di cPanel, atur frekuensi ke **Every Minute (`* * * * *`)**, dan masukkan perintah:
```bash
* * * * * /usr/local/bin/php /home/username/anabot/bin/cron/cron_runner.php >/dev/null 2>&1
```
*(Sesuaikan path binary PHP dan path direktori hosting Anda)*

### Opsi B: Web Cron (Untuk Hosting Tanpa Terminal/SSH)
Jika shared hosting Anda hanya menyediakan opsi URL Web Cron, panggil URL berikut setiap 1-5 menit:
```http
GET https://analytics.domainanda.com/cron/run?token=TOKEN_RAHASIA_ANDA
```
*Ganti `TOKEN_RAHASIA_ANDA` dengan nilai `CRON_SECRET_TOKEN` dari berkas `.env` Anda.*

---

## 5. Keamanan & Pengerasan Produksi (Security Hardening)

1. **Proteksi Berkas Terenkripsi**:
   - Seluruh kredensial API dan OAuth token disimpan dengan enkripsi **AES-256-GCM** menggunakan `APP_KEY`. Pastikan mencadangkan berkas `.env` dengan aman.
2. **Pencegahan Directory Browsing & Akses Privat**:
   - Berkas `storage/.htaccess` dan `public/.htaccess` telah mengaktifkan `Options -Indexes`, `Header set X-Content-Type-Options "nosniff"`, `Header set X-Frame-Options "SAMEORIGIN"`, dan `Header set Referrer-Policy "strict-origin-when-cross-origin"`.
3. **Pencadangan Bergulir Otomatis**:
   - Sistem secara terjadwal membuat salinan atomik SQLite (`VACUUM INTO`) dan mempertahankan 7 cadangan terbaru di `storage/backups/`.
4. **Rotasi Log Otomatis**:
   - Berkas log dirotasi otomatis ketika mencapai 10 MB dan dibersihkan setelah 14 hari untuk mencegah kepenuhan disk hosting.

---

## 6. Prosedur Pembaruan Sistem (Zero Data Loss)

Untuk memperbarui AnaBot ke versi baru di lingkungan produksi:
1. Cadangkan basis data SQLite Anda:
   ```bash
   php bin/cron/backup.php
   ```
2. Unggah berkas kode terbaru (timpa berkas `app/`, `resources/`, `config/`, `public/`).
3. **JANGAN PERNAH** menimpa berkas `.env` atau folder `storage/database/`.
4. Jalankan migrasi:
   ```bash
   php bin/migrate.php
   ```
   *Runner migrasi AnaBot bersifat idempoten: tabel dan data yang sudah ada akan tetap 100% utuh tanpa kehilangan data.*
