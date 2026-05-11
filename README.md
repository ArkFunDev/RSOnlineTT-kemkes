# RS Online Kemenkes - Kirim Data Tempat Tidur

Script PHP untuk mengirimkan data **occupancy tempat tidur (TT)** Rumah Sakit
ke sistem **RS Online Kemenkes** secara otomatis melalui API bridging.

Script ini dirancang **generik** — nama tabel dan kolom database dikonfigurasi
di `config.php` sehingga bisa dipakai di berbagai SIMRS yang berbeda.

---

## Fitur

- Kirim data tempat tidur (jumlah & terpakai) ke RS Online Kemenkes
- Method **PUT** sesuai dokumentasi API Kemenkes versi 3 (27-01-2026)
- **cURL Multi / paralel** — kirim ratusan ruang sekaligus tanpa timeout
- **Log harian** otomatis tersimpan di folder `log/`
- Nama tabel & kolom DB dikonfigurasi via `config.php` (tidak hardcode)
- Bisa dijalankan via **CLI (Task Scheduler)** maupun **browser**
- Tampilan hasil pengiriman per ruang (Sukses / Gagal)

---

## Struktur File

```
rsonline-kemkes/
├── kirimdatakamar.php       # Script utama pengiriman data TT
├── config.example.php       # Template konfigurasi (salin ke config.php)
├── kirim_kamar.bat          # Batch file untuk Windows Task Scheduler
├── scheduler/
|   └── RSOnline.xml         # Import ke Windows Task Scheduler (tiap 1 menit)
├── log/                     # Log harian otomatis (di-ignore git)
└── README.md
```

---

## Instalasi

### 1. Clone / Download

```bash
git clone https://github.com/username/rsonline-kemkes.git
```

### 2. Buat file konfigurasi

```bash
cp config.example.php config.php
```

Edit `config.php` dan isi semua nilai sesuai SIMRS Anda:

```php
// Database
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'password_db');
define('DB_NAME', 'nama_database_simrs');

// Kredensial Kemenkes
define('RS_ID',   'KODE_RS');
define('RS_PASS', 'PASSWORD_RSONLINE');

// Nama tabel di SIMRS Anda
define('TBL_MAPPING',  'nama_tabel_mapping_tt');
define('TBL_ROOM',     'nama_tabel_kamar');
define('TBL_WARD',     'nama_tabel_bangsal');
define('TBL_INPATIENT','nama_tabel_rawat_inap');

// Nama kolom masing-masing tabel
define('COL_MAP_ROOM',             'kd_kamar');
define('COL_ROOM_ID',              'kd_kamar');
define('COL_ROOM_WARD',            'kd_bangsal');
define('COL_WARD_ID',              'kd_bangsal');
define('COL_WARD_NAME',            'nm_bangsal');
define('COL_INP_ROOM',             'kd_kamar');
define('COL_INP_DISCHARGE_DATE',   'tgl_keluar');
define('COL_INP_DISCHARGE_STATUS', 'stts_pulang');
```

---

## Struktur Database yang Dibutuhkan

Script membutuhkan 4 tabel dengan relasi berikut:

```
[TBL_MAPPING]          [TBL_ROOM]           [TBL_WARD]
kd_kamar (FK) ──────> kd_kamar (PK)        kd_bangsal (PK)
id_t_tt                kd_bangsal (FK) ──> nm_bangsal
id_tt
tt
kode_siranap

[TBL_INPATIENT]
kd_kamar
tgl_keluar       -- NULL = pasien masih dirawat
stts_pulang      -- status pulang pasien
```

### Logika Terpakai

Pasien dihitung **masih menempati kamar** jika:
- `tgl_keluar IS NULL` (belum ada tanggal keluar), **DAN**
- `stts_pulang` bukan status keluar final

Status yang dianggap **sudah keluar** (tidak dihitung):
Sehat, Rujuk, APS, Meninggal, Sembuh, Membaik, Pulang Paksa,
Atas Persetujuan Dokter, Atas Permintaan Sendiri, Lain-lain, dll.

> Sesuaikan daftar status di query pada `kirimdatakamar.php` dengan nilai
> yang ada di database SIMRS Anda.

---

## Cara Penggunaan

### Jalankan Manual via Browser
```
http://localhost/rsonline/kirimdatakamar.php
```

### Jalankan via CLI
```bash
C:\xampp\php\php.exe C:\rsonline\kirimdatakamar.php
```

### Otomatis via Windows Task Scheduler
1. Sesuaikan path di `kirim_kamar.bat`
2. Buka **Task Scheduler** (`taskschd.msc`)
3. Klik **Action > Import Task**
4. Pilih file `scheduler/RSOnline.xml`
5. Masukkan password user Windows saat diminta

> Task akan berjalan **setiap 1 menit** sepanjang hari.

---

## Format Log Harian

Log tersimpan otomatis di `log/kirim_tt_YYYY-MM-DD.txt`.
Setiap eksekusi di-*append* ke file yang sama sehingga riwayat per jam tersimpan.

```
============================================================
KIRIM DATA KAMAR RS ONLINE
Waktu   : 2026-05-11 09:30:00
Total   : 133 ruang | Sukses: 130 | Gagal: 3
============================================================

[ SUKSES - 130 ruang ]
------------------------------------------------------------
  OK Bangsal A - Kamar C1 - C3          | id_t_tt: 11693 | Jumlah: 3 | Terpakai: 2

[ GAGAL - 3 ruang ]
------------------------------------------------------------
  XX ICU                                | id_t_tt: 71600 | HTTP: 500 | ...
```

---

## Referensi API

- Dokumentasi: [Bridging RS Online Kemenkes v3](https://sirs.kemkes.go.id/fo/downloads/bridging_rs_online_versi3_27012026.pdf)
- Endpoint: `https://sirs.kemkes.go.id/fo/index.php/Fasyankes`
- Method: `PUT`
- Header: `X-rs-id`, `X-Timestamp`, `X-pass`, `Content-Type: application/json`

---

## Keamanan

- File `config.php` sudah di-ignore oleh `.gitignore` — tidak akan ter-upload ke GitHub
- Tidak ada nama tabel, kolom, atau kredensial yang ter-hardcode di script utama
- Jangan pernah commit `config.php` ke repository publik

---

## Lisensi

MIT License — bebas digunakan dan dimodifikasi.
