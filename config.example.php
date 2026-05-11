<?php
// =============================================================
// Salin file ini menjadi config.php dan sesuaikan dengan DB Anda
// Copy this file to config.php and adjust to your database
// =============================================================

// ── Database Lokal SIMRS ──────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'nama_database_simrs');

// ── Kredensial RS Online Kemenkes ─────────────────────────────
define('RS_ID',   'KODE_RS_ANDA');   // X-rs-id header
define('RS_PASS', 'PASSWORD_ANDA');  // X-pass header

// ── Endpoint API ──────────────────────────────────────────────
define('BASE_URL', 'https://sirs.kemkes.go.id/fo/index.php/Fasyankes');

// =============================================================
// MAPPING TABEL & KOLOM DATABASE LOKAL
// Sesuaikan dengan nama tabel di SIMRS Anda
// =============================================================

// Tabel mapping kamar lokal ke TT Kemenkes (contoh: rsonlineTT)
define('TBL_MAPPING', 'nama_tabel_mapping_tt');
define('COL_MAP_ROOM', 'kd_kamar');       // kolom kode kamar di tabel mapping

// Tabel master kamar/bed (contoh: kamar)
define('TBL_ROOM', 'nama_tabel_kamar');
define('COL_ROOM_ID',   'kd_kamar');      // kolom PK kamar
define('COL_ROOM_WARD', 'kd_bangsal');    // kolom FK ke tabel bangsal/ward

// Tabel master bangsal/ruangan (contoh: bangsal)
define('TBL_WARD', 'nama_tabel_bangsal');
define('COL_WARD_ID',   'kd_bangsal');    // kolom PK bangsal
define('COL_WARD_NAME', 'nm_bangsal');    // kolom nama bangsal

// Tabel rawat inap aktif (contoh: kamar_inap)
define('TBL_INPATIENT', 'nama_tabel_rawat_inap');
define('COL_INP_ROOM',             'kd_kamar');    // kolom kode kamar
define('COL_INP_DISCHARGE_DATE',   'tgl_keluar');  // kolom tanggal keluar (NULL = masih dirawat)
define('COL_INP_DISCHARGE_STATUS', 'stts_pulang'); // kolom status pulang

/*
 * STATUS PULANG yang dianggap MASIH DIRAWAT (belum keluar):
 * Pasien dihitung terpakai jika tgl_keluar IS NULL dan
 * stts_pulang bernilai salah satu di bawah ini.
 * Sesuaikan dengan nilai di database Anda.
 *
 * Nilai default: NULL, '', '-', '+', 'Pindah Kamar', 'Status Belum Lengkap'
 */
