# Prosedur Backup & Restore Database SQLite AnaBot

Dokumen ini memuat panduan komprehensif terkait strategi perlindungan, pencadangan (backup), dan pemulihan (restore) database `anabot.sqlite` sesuai aturan Bagian 5 spesifikasi arsitektur AnaBot.

---

## 1. Prinsip Utama Perlindungan Database
1. **Tidak Boleh Menimpa Database Produksi:** Update source code, migrasi baru, atau perbaikan antarmuka tidak boleh menghapus atau menimpa database produksi yang telah berisi data.
2. **Konsistensi WAL:** Karena database menggunakan mode Write-Ahead Logging (`PRAGMA journal_mode = WAL;`), penyalinan file mentah (`cp anabot.sqlite`) saat transaksi sedang berjalan dapat menghasilkan file yang korup atau tidak sinkron dengan `.wal`. AnaBot menggunakan **SQLite Online Backup API** (melalui `\SQLite3::open` / SQLite backup method) atau `VACUUM INTO 'destination.sqlite'` yang menjamin backup konsisten 100%.
3. **Verifikasi Integritas:** Setiap backup wajib diverifikasi menggunakan `PRAGMA integrity_check;`. Hasil harus tepat `"ok"`.

---

## 2. Format & Konvensi Nama File Backup
Backup disimpan di direktori terisolasi (`storage/backups/` atau `/home/username/anabot_data/backups/`).
Format penamaan:
```
anabot_YYYY-MM-DD_HH-mm-ss.sqlite
```
Contoh:
`anabot_2026-11-27_03-00-00.sqlite`

---

## 3. Pemicu Backup Otomatis
Backup dilakukan secara otomatis dalam kondisi:
1. **Pre-Migration:** Tepat sebelum script migrasi baru dijalankan. Jika migrasi gagal, sistem membatalkan dan mencatat status tanpa merusak kondisi sebelum migrasi.
2. **Pre-Update / Pre-Deployment:** Sebelum proses pembaruan source code.
3. **Terjadwal (Cron Jobs):** Dilakukan setiap hari pada pukul 02:00 malam via `cron/backup.php`.
4. **Pre-Restore:** Sebelum file database ditimpa oleh file restore, kondisi terkini wajib di-backup terlebih dahulu sebagai jaring pengaman darurat.

---

## 4. Mekanisme Teknis Backup via PHP Native

### 4.1 Menggunakan SQLite `VACUUM INTO` (Metode Utama - Atomik & Bersih)
Di SQLite 3.27+, perintah `VACUUM INTO` membuat salinan database yang bersih, terdefragmentasi, dan mengintegrasikan seluruh transaksi WAL secara atomik:

```php
$db = new \PDO("sqlite:" . $productionDbPath);
$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$backupFilename = "anabot_" . date('Y-m-d_H-i-s') . ".sqlite";
$targetBackupPath = $backupDir . '/' . $backupFilename;

// Eksekusi VACUUM INTO yang thread-safe dan konsisten
$stmt = $db->prepare("VACUUM INTO :targetPath");
$stmt->execute([':targetPath' => $targetBackupPath]);

// Verifikasi Integritas
$backupPdo = new \PDO("sqlite:" . $targetBackupPath);
$check = $backupPdo->query("PRAGMA integrity_check")->fetchColumn();

if ($check !== 'ok') {
    unlink($targetBackupPath);
    throw new \RuntimeException("Backup integrity check failed: " . $check);
}
```

---

## 5. Prosedur Pemulihan Data (Restore Procedure)

### Langkah Pemulihan Bertahap:
1. **Kunci Akses Web (Maintenance Mode):** Buat file maintenance `storage/temp/down` agar tidak ada request baru yang mengakses database.
2. **Buat Snapshot Darurat:** Buat cadangan database saat ini (`anabot_emergency_pre_restore_*.sqlite`).
3. **Verifikasi File Backup Target:**
   - Jalankan `PRAGMA integrity_check;` terhadap file backup yang dipilih.
   - Periksa keberadaan tabel inti (`users`, `schema_migrations`, `datasets`).
4. **Ganti Database:**
   - Putus seluruh koneksi PDO aktif (close connection handle).
   - Salin file backup yang valid ke lokasi target produksi `anabot.sqlite`.
   - Hapus file temporary WAL/SHM lama (`anabot.sqlite-wal`, `anabot.sqlite-shm`) jika ada.
5. **Verifikasi Akhir:**
   - Buka koneksi baru ke `anabot.sqlite`.
   - Jalankan `PRAGMA integrity_check;`.
   - Periksa jumlah record.
6. **Buka Akses Web:** Hapus file maintenance `storage/temp/down`.

---

## 6. Prosedur Manual Pemulihan CLI
Jika antarmuka dashboard tidak dapat diakses, administrator dapat menjalankan restore via CLI:

```bash
php /home/username/anabot_app/cron/backup.php --restore=anabot_2026-11-27_03-00-00.sqlite
```

Proses ini akan otomatis meminta konfirmasi ganda sebelum melanjutkan dan melakukan backup kondisi saat ini terlebih dahulu.
