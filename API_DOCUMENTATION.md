# Dokumentasi Internal API AnaBot

Seluruh endpoint REST API AnaBot menggunakan prefix URL: `/api/v1/`.  
Format pertukaran data: `application/json` (kecuali file binary download).  
Autentikasi: Berbasis Session Cookie / CSRF Token (Single User).

---

## 1. Format Respons Standar (Response Envelope)

### 1.1 Respons Berhasil
```json
{
  "success": true,
  "data": {
    ...
  },
  "message": "Operasi berhasil dilakukan",
  "meta": {
    "timestamp": "2026-11-27T03:00:00Z",
    "execution_time_ms": 14.2
  }
}
```

### 1.2 Respons Gagal / Error
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Data masukan tidak valid",
    "details": {
      "email": ["Format email tidak valid"]
    }
  },
  "meta": {
    "timestamp": "2026-11-27T03:00:00Z"
  }
}
```

---

## 2. Daftar Endpoint Utama

### 2.1 Modul Autentikasi (`/api/v1/auth`)
| Method | Endpoint | Deskripsi |
| :--- | :--- | :--- |
| `POST` | `/api/v1/auth/login` | Login pengguna (username/password, throttling, CSRF). |
| `POST` | `/api/v1/auth/logout` | Menghancurkan session dan cookie autentikasi. |
| `GET` | `/api/v1/auth/me` | Mengambil info profil pengguna yang sedang login. |
| `POST` | `/api/v1/auth/change-password` | Mengganti password akun (verifikasi password lama). |

### 2.2 Modul Konektor Platform (`/api/v1/connectors` & `/api/v1/connections`)
| Method | Endpoint | Deskripsi |
| :--- | :--- | :--- |
| `GET` | `/api/v1/connectors` | Mendapatkan daftar konektor resmi yang tersedia. |
| `GET` | `/api/v1/connectors/{id}` | Detail konfigurasi dan scope konektor tertentu. |
| `GET` | `/api/v1/connections` | Daftar platform eksternal yang sudah terhubung. |
| `POST` | `/api/v1/connections` | Menambahkan koneksi platform baru. |
| `GET` | `/api/v1/connections/{id}` | Detail koneksi dan status token. |
| `POST` | `/api/v1/connections/{id}/test` | Menguji konektivitas API pihak ketiga (Read-only). |
| `POST` | `/api/v1/connections/{id}/sync` | Memicu sinkronisasi data langsung (manual sync). |
| `PATCH` | `/api/v1/connections/{id}` | Memperbarui nama atau jadwal sinkronisasi otomatis. |
| `DELETE` | `/api/v1/connections/{id}` | Menghapus koneksi lokal (tidak menghapus data platform asli). |

### 2.3 Modul Data Explorer (`/api/v1/datasets`)
| Method | Endpoint | Deskripsi |
| :--- | :--- | :--- |
| `GET` | `/api/v1/data/overview` | Ringkasan statistik dataset, total baris, tanggal sinkronisasi. |
| `GET` | `/api/v1/datasets` | Daftar seluruh dataset yang tersimpan di SQLite. |
| `GET` | `/api/v1/datasets/{id}` | Detail metadata dataset tertentu. |
| `GET` | `/api/v1/datasets/{id}/schema` | Struktur kolom, tipe data, dan klasifikasi metrik/dimensi. |
| `GET` | `/api/v1/datasets/{id}/records` | Pembacaan baris data dengan pagination, search, sorting, dan filter. |
| `GET` | `/api/v1/datasets/{id}/export` | Streaming ekspor data ke format CSV. |

### 2.4 Modul Multi-Provider AI & AI Analyst (`/api/v1/ai`)
| Method | Endpoint | Deskripsi |
| :--- | :--- | :--- |
| `GET` | `/api/v1/ai/providers` | Daftar provider AI (Gemini, OpenAI, Claude, Compatible). |
| `POST` | `/api/v1/ai/providers` | Mengonfigurasi API key dan status provider aktif. |
| `POST` | `/api/v1/ai/providers/{id}/test` | Menguji panggilan API model AI terpilih. |
| `GET` | `/api/v1/ai/models` | Daftar model AI yang didukung dan estimasi biaya per token. |
| `POST` | `/api/v1/ai/analyze` | Menjalankan pipeline analitik statistik + sintesis AI pada dataset. |
| `POST` | `/api/v1/ai/chat` | Mengirim prompt percakapan analitik dengan grounding konteks data. |
| `GET` | `/api/v1/ai/history` | Riwayat sesi percakapan dan ringkasan insight tersimpan. |
| `GET` | `/api/v1/ai/usage` | Log penggunaan token dan akumulasi estimasi biaya. |

### 2.5 Modul Laporan (`/api/v1/reports`)
| Method | Endpoint | Deskripsi |
| :--- | :--- | :--- |
| `POST` | `/api/v1/reports/generate` | Menghasilkan dokumen laporan baru (PDF, XLSX, CSV, HTML). |
| `GET` | `/api/v1/reports` | Daftar riwayat laporan yang telah dibuat. |
| `GET` | `/api/v1/reports/{id}` | Metadata laporan dan ringkasan isi eksekutif. |
| `GET` | `/api/v1/reports/{id}/download` | Mengunduh file laporan privat dengan verifikasi session. |
| `DELETE` | `/api/v1/reports/{id}` | Menghapus file laporan dari storage privat. |
