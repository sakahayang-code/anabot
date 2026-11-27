# Panduan Deployment & Pembaruan Produksi AnaBot

Dokumen ini adalah acuan resmi arsitektur untuk melakukan instalasi awal dan pembaruan (deployment) aplikasi AnaBot pada lingkungan **Shared Hosting** (cPanel / DirectAdmin / Custom Linux) secara aman tanpa risiko kehilangan data.

---

## 1. Topologi Pemisahan Direktori Fisik

Untuk melindungi data pengguna dan database SQLite dari penimpaan saat update source code (misal via Git pull atau upload ZIP), struktur direktori produksi **wajib** dipisahkan menjadi 3 komponen:

```
/home/username/
│
├── anabot_app/                  <--- DIREKTORI SOURCE CODE (Bisa ditimpa saat update)
│   ├── app/
│   ├── config/
│   ├── database/migrations/
│   ├── public/
│   ├── resources/
│   ├── routes/
│   └── vendor/
│
├── anabot_data/                 <--- DIREKTORI DATA PERSISTEN (DILARANG DITIMPA)
│   ├── anabot.sqlite            (Database Utama SQLite)
│   ├── backups/                 (Snapshot Backup otomatis & terjadwal)
│   ├── logs/                    (Log error dan log sinkronisasi)
│   ├── reports/                 (File PDF, XLSX, CSV laporan pengguna)
│   └── queue/                   (Antrean tugas background)
│
├── anabot_secrets/              <--- DIREKTORI RAHASIA (DILARANG DITIMPA)
│   ├── .env                     (Konfigurasi Environment)
│   └── encryption.key           (Kunci AES-256-GCM)
│
└── public_html/                 <--- PUBLIC DOCUMENT ROOT WEB SERVER
    ├── index.php                (Front Controller yang me-require anabot_app/public/index.php)
    ├── .htaccess                (Routing Apache & Proteksi file sensitif)
    └── assets/                  (CSS, JS, Fonts, Images statis)
```

---

## 2. Sepuluh Aturan Mutlak Deployment (Zero Data Loss Policy)

Ketika melakukan pembaruan versi (update deployment):
1. **Dilarang** menimpa direktori data (`anabot_data/`).
2. **Dilarang** menimpa atau menyalin ulang file database `anabot.sqlite`.
3. **Dilarang** menghapus atau mengganti file `.env` yang berada di `anabot_secrets/`.
4. **Dilarang** menghapus atau meregenerasi `encryption.key` karena kunci ini dibutuhkan untuk mendekripsi token konektor yang sudah tersimpan.
5. **Dilarang** menghapus file laporan dan unduhan pengguna di `storage/reports/`.
6. **Dilarang** menghapus file cadangan lama di `storage/backups/`.
7. **Dilarang** menjalankan ulang wizard installer (`install.php`) jika database telah memiliki tabel `users`.
8. **Dilarang** menjalankan seeder database yang bersifat destruktif (`TRUNCATE` / `DELETE`).
9. **Dilarang** membuat file database kosong baru jika database produksi tidak terdeteksi; sistem harus menghentikan proses (fail-safe) dan menampilkan instruksi konfigurasi path.
10. **Dilarang** melakukan reset database sebagai bagian dari alur build atau deployment.

---

## 3. Konfigurasi Web Server Apache (.htaccess)

File `.htaccess` di root DocumentRoot (`public_html/.htaccess` atau `anabot_app/public/.htaccess`):

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Blokir akses langsung ke file sensitif
    RewriteRule ^(\.env|\.git|composer\.(json|lock)|.*\.sqlite.*|.*\.key|.*\.log)$ - [F,L,NC]

    # Arahkan seluruh request ke Front Controller
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>

# Keamanan Header Tambahan
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-XSS-Protection "1; mode=block"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# Matikan directory browsing
Options -Indexes
```

---

## 4. Alur Kerja Pembaruan Aplikasi (Safe Update Workflow)

Setiap pembaruan aplikasi wajib mengikuti langkah berurutan berikut:

```
[Mulai Update]
      |
      v
[1. Pre-Flight Check] -----> Cek eksistensi database & permission folder
      |
      v
[2. Snapshot Backup] ------> Jalankan VACUUM INTO anabot_YYYY-MM-DD_HH-mm-ss.sqlite
      |                      dan verifikasi PRAGMA integrity_check = ok
      v
[3. Update Source Code] ---> Ekstrak file / git pull hanya ke direktori anabot_app/
      |
      v
[4. Run Migrations] -------> Jalankan migrasi baru via CLI migration runner
      |                      (Cek tabel schema_migrations dan catat batch baru)
      v
[5. Clear Cache] ----------> Bersihkan cache view / compiled routes di storage/cache/
      |
      v
[6. Post-Flight Verify] ---> Verifikasi endpoint /api/v1/auth/health & integrity_check
      |
      v
[Update Selesai]
```
